<?php

namespace App\Services\Platform;

use App\Models\OpsAlert;
use App\Models\Order;
use App\Models\RiskAssessment;
use App\Models\TreasuryDiscrepancy;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payments\AcquirerHealthService;
use App\Support\SqlDialect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Brasa Command Center — visão de operação montada só com dados reais. Quando uma fonte não existe
 * ou falha, o bloco vem null (a tela mostra "indisponível"); nada é estimado ou inventado.
 * Os alertas saem destes mesmos números e têm chave estável para a central de alertas.
 */
class CommandCenterService
{
    public const QUEUES = ['payments', 'default', 'payouts', 'webhooks', 'webhooks-outbound', 'webhooks-inbound', 'meta-tracking', 'utmify-tracking', 'uazapi', 'integrax-sms'];

    public function __construct(private AcquirerHealthService $health) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $today = now()->startOfDay();
        $week = now()->subDays(7);

        $executive = $this->safe(function () use ($today, $week) {
            $completed = fn ($since) => Order::query()->where('status', 'completed')->where('created_at', '>=', $since);
            $kToday = PlatformRevenueKpis::compute($today->toDateTimeString(), now()->toDateTimeString());
            $kWeek = PlatformRevenueKpis::compute($week->toDateTimeString(), now()->toDateTimeString());

            return [
                'volume_today' => round((float) $completed($today)->sum('amount'), 2),
                'orders_today' => $completed($today)->count(),
                'volume_7d' => round((float) $completed($week)->sum('amount'), 2),
                'orders_7d' => $completed($week)->count(),
                'pending_24h' => Order::query()->where('status', 'pending')->where('created_at', '>=', now()->subDay())->count(),
                'fees_today' => $kToday['faturamento_taxas_cobradas'],
                'net_today' => $kToday['faturamento_liquido'],
                'fees_7d' => $kWeek['faturamento_taxas_cobradas'],
                'net_7d' => $kWeek['faturamento_liquido'],
                'disputed_open' => Order::query()->where('status', 'disputed')->count(),
                'refunds_7d' => Order::query()->where('status', 'refunded')->where('updated_at', '>=', $week)->count(),
            ];
        });

        $sellers = $this->safe(fn () => [
            'active' => User::query()->where('role', User::ROLE_INFOPRODUTOR)->where('kyc_status', User::KYC_APPROVED)
                ->where(fn ($q) => $q->where('account_status', 'approved')->orWhereNull('account_status'))->count(),
            'suspended' => User::query()->where('role', User::ROLE_INFOPRODUTOR)->whereIn('account_status', ['suspended', 'blocked'])->count(),
            'kyc_review' => User::query()->where('role', User::ROLE_INFOPRODUTOR)->where('kyc_status', User::KYC_PENDING_REVIEW)->count(),
            'new_7d' => User::query()->where('role', User::ROLE_INFOPRODUTOR)->where('created_at', '>=', $week)->count(),
        ]);

        $topSellers = $this->safe(function () {
            $rows = Order::query()->where('created_at', '>=', now()->subDays(30))->whereIn('status', ['completed', 'refunded', 'disputed'])
                ->groupBy('tenant_id')
                ->selectRaw("tenant_id, SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as volume,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN status IN ('refunded','disputed') THEN 1 ELSE 0 END) as lost")
                ->orderByDesc('volume')->limit(15)->get();
            $users = User::query()->whereIn('id', $rows->pluck('tenant_id'))->get(['id', 'name', 'email', 'account_status'])->keyBy('id');

            return $rows->map(fn ($r) => [
                'tenant_id' => (int) $r->tenant_id, 'name' => $users->get($r->tenant_id)?->name, 'email' => $users->get($r->tenant_id)?->email,
                'status' => $users->get($r->tenant_id)?->account_status ?? 'approved',
                'volume_30d' => round((float) $r->volume, 2), 'paid_30d' => (int) $r->paid,
                'loss_rate' => ($r->paid + $r->lost) > 0 ? round($r->lost / ($r->paid + $r->lost), 4) : null,
            ])->values()->all();
        });

        $withdrawals = $this->safe(fn () => [
            'awaiting' => Withdrawal::query()->where('status', 'pending')->count(),
            'paid_7d' => Withdrawal::query()->where('status', 'paid')->where('updated_at', '>=', $week)->count(),
            'paid_amount_7d' => round((float) Withdrawal::query()->where('status', 'paid')->where('updated_at', '>=', $week)->sum('amount'), 2),
            'investigating' => Withdrawal::query()->whereIn('status', ['pending', 'processing'])
                ->whereRaw('NOT '.SqlDialect::jsonKeyMissingOrEmpty('payout_meta', 'reconcile_exhausted'))->count(),
        ]);

        $treasury = $this->safe(function () {
            if (! Schema::hasTable('treasury_discrepancies')) {
                return null;
            }
            $active = TreasuryDiscrepancy::query()->whereIn('status', ['open', 'investigating']);

            return [
                'critical' => (clone $active)->where('severity', 'critical')->count(),
                'high' => (clone $active)->where('severity', 'high')->count(),
                'total' => (clone $active)->count(),
                'last_run' => DB::table('treasury_runs')->latest('id')->value('finished_at'),
            ];
        });

        $risk = $this->safe(fn () => Schema::hasTable('risk_assessments') ? [
            'high_open' => RiskAssessment::query()->where('level', 'high')->where('status', 'open')->count(),
            'medium_open' => RiskAssessment::query()->where('level', 'medium')->where('status', 'open')->count(),
        ] : null);

        $acquirers = $this->safe(function () {
            $out = [];
            foreach ($this->health->stats(1, 10) as $method => $slugs) {
                foreach ($slugs as $slug => $m) {
                    $out[] = ['method' => $method, 'slug' => $slug] + array_intersect_key($m, array_flip(['attempts', 'success_rate', 'technical_error_rate', 'timeout_rate', 'latency_p95_ms', 'sufficient_sample']));
                }
            }

            return $out;
        });

        $infra = $this->safe(fn () => $this->infra());
        $automations = $this->safe(fn () => $this->automations());

        $data = compact('executive', 'sellers', 'topSellers', 'withdrawals', 'treasury', 'risk', 'acquirers', 'infra', 'automations');
        $data['alerts'] = $this->alerts($data);

        return $data;
    }

    /** @return array<string, mixed> */
    private function infra(): array
    {
        $queues = [];
        foreach (self::QUEUES as $q) {
            try {
                $queues[$q] = Queue::size($q);
            } catch (\Throwable) {
                $queues[$q] = null;
            }
        }
        $ago = fn ($iso) => $iso ? (int) Carbon::parse($iso)->diffInMinutes(now()) : null;

        $t = microtime(true);
        DB::select('select 1');
        $dbMs = (int) round((microtime(true) - $t) * 1000);

        $redisMs = null;
        $usesRedis = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true);
        if ($usesRedis) {
            try {
                $t = microtime(true);
                Redis::connection()->ping();
                $redisMs = (int) round((microtime(true) - $t) * 1000);
            } catch (\Throwable) {
                $redisMs = -1;
            }
        }

        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());
        $since = now()->subHour();

        return [
            'scheduler_minutes_ago' => $ago(Cache::get('schedule_heartbeat')),
            'queue_minutes_ago' => $ago(Cache::get('queue_heartbeat')),
            'failed_jobs_24h' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : null,
            'queues' => $queues,
            'web_server' => PHP_SAPI,
            'db_ms' => $dbMs,
            'redis_ms' => $redisMs, // null = Redis não usado; -1 = sem resposta
            'disk_used_pct' => $total ? round(1 - $free / $total, 4) : null,
            'disk_free_gb' => $free ? round($free / 1024 ** 3, 1) : null,
            'api_calls_1h' => Schema::hasTable('api_request_logs') ? DB::table('api_request_logs')->where('created_at', '>=', $since)->count() : null,
            'api_5xx_1h' => Schema::hasTable('api_request_logs') ? DB::table('api_request_logs')->where('created_at', '>=', $since)->where('status', '>=', 500)->count() : null,
            'webhooks_failed_1h' => Schema::hasTable('api_webhook_deliveries') ? DB::table('api_webhook_deliveries')->where('status', 'failed')->where('updated_at', '>=', $since)->count() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function automations(): array
    {
        $day = now()->subDay();
        $count = fn (string $table, callable $q) => Schema::hasTable($table) ? $q(DB::table($table))->count() : null;

        return [
            'recovery_sent_24h' => $count('recovery_messages', fn ($q) => $q->where('status', 'sent')->where('sent_at', '>=', $day)),
            'recovery_failed_24h' => $count('recovery_messages', fn ($q) => $q->where('status', 'failed')->where('updated_at', '>=', $day)),
            'webhooks_delivered_24h' => $count('api_webhook_deliveries', fn ($q) => $q->where('status', 'delivered')->where('delivered_at', '>=', $day)),
            'webhooks_failed_24h' => $count('api_webhook_deliveries', fn ($q) => $q->where('status', 'failed')->where('updated_at', '>=', $day)),
            'experiments_running' => $count('experiments', fn ($q) => $q->where('status', 'running')),
            'ai_answers_24h' => $count('ai_messages', fn ($q) => $q->where('role', 'assistant')->where('created_at', '>=', $day)),
            'ai_errors_1h' => $count('ai_messages', fn ($q) => $q->where('status', 'error')->where('created_at', '>=', now()->subHour())),
            'domains_failed' => $count('enterprise_domains', fn ($q) => $q->where('status', 'failed')),
        ];
    }

    /**
     * Alertas com chave estável, área, orientação e link. Só números reais.
     *
     * @return list<array{key: string, severity: string, area: string, title: string, hint: string|null, href: string|null}>
     */
    private function alerts(array $d): array
    {
        $a = [];
        $add = function (string $key, string $sev, string $area, string $title, ?string $hint, ?string $href) use (&$a) {
            $a[] = compact('key', 'title', 'hint', 'href') + ['severity' => $sev, 'area' => $area];
        };
        ['treasury' => $treasury, 'withdrawals' => $w, 'risk' => $risk, 'infra' => $infra, 'executive' => $exec, 'automations' => $auto] = $d;

        if (($treasury['critical'] ?? 0) > 0) {
            $add('treasury.critical', 'critical', 'financeiro', "{$treasury['critical']} divergência(s) financeira(s) crítica(s) na tesouraria", 'Abra cada divergência e confira na adquirente antes de qualquer ajuste de saldo.', '/plataforma/tesouraria');
        }
        if (($w['investigating'] ?? 0) > 0) {
            $add('withdrawals.ambiguous', 'high', 'financeiro', "{$w['investigating']} saque(s) sem confirmação da adquirente", 'Não devolva nem repita: confirme o status no painel da adquirente.', '/plataforma/tesouraria?kind=withdrawal_reconcile_exhausted');
        }
        if (($risk['high_open'] ?? 0) > 0) {
            $add('risk.high', 'high', 'risco', "{$risk['high_open']} análise(s) de risco alto em aberto", 'Revise as evidências; nenhuma ação é tomada automaticamente.', '/plataforma/risco');
        }
        foreach ($d['acquirers'] ?? [] as $acq) {
            if (! empty($acq['sufficient_sample']) && ($acq['technical_error_rate'] > 0.1 || $acq['timeout_rate'] > 0.05)) {
                $add("acquirer.{$acq['slug']}.{$acq['method']}", 'high', 'pagamentos', sprintf('%s (%s): %.0f%% de erro técnico e %.0f%% de timeout na última hora', $acq['slug'], $acq['method'], $acq['technical_error_rate'] * 100, $acq['timeout_rate'] * 100), 'Confira o status da adquirente; se necessário, desligue o roteamento inteligente.', '/plataforma/ops/adquirentes?horas=1');
            }
        }
        if ($infra !== null) {
            if ($infra['scheduler_minutes_ago'] === null || $infra['scheduler_minutes_ago'] > 5) {
                $add('infra.scheduler', 'critical', 'infraestrutura', 'Agendador sem sinal há mais de 5 minutos', 'Liberações, conciliações e cobranças param. Verifique o container do scheduler.', '/plataforma/ops/saude-pagamentos');
            }
            if (($infra['redis_ms'] ?? null) === -1) {
                $add('infra.redis', 'critical', 'infraestrutura', 'Redis não respondeu', 'Filas, cache e sessões dependem dele.', null);
            }
            if (($infra['db_ms'] ?? 0) > 500) {
                $add('infra.db_latency', 'high', 'infraestrutura', "Banco respondendo devagar ({$infra['db_ms']} ms num select simples)", null, null);
            }
            if (($infra['disk_used_pct'] ?? 0) >= 0.85) {
                $add('infra.disk', $infra['disk_used_pct'] >= 0.95 ? 'critical' : 'attention', 'infraestrutura', sprintf('Disco %.0f%% ocupado (%s GB livres)', $infra['disk_used_pct'] * 100, $infra['disk_free_gb']), 'Limpe logs, imagens Docker antigas e backups locais.', null);
            }
            if (($infra['failed_jobs_24h'] ?? 0) > 0) {
                $add('infra.failed_jobs', 'attention', 'infraestrutura', "{$infra['failed_jobs_24h']} job(s) falharam nas últimas 24 h", 'Veja a lista em Infraestrutura; só jobs seguros podem ser reprocessados aqui.', null);
            }
            foreach ($infra['queues'] as $q => $size) {
                if ($size !== null && $size > 500) {
                    $add("infra.queue.{$q}", 'attention', 'infraestrutura', "Fila {$q} com {$size} jobs aguardando", 'Verifique se o worker dessa fila está rodando.', null);
                }
            }
            if (($infra['api_calls_1h'] ?? 0) >= 20 && ($infra['api_5xx_1h'] ?? 0) / max(1, $infra['api_calls_1h']) > 0.05) {
                $add('infra.api_5xx', 'high', 'infraestrutura', "API pública com {$infra['api_5xx_1h']} erro(s) 5xx em {$infra['api_calls_1h']} chamadas na última hora", null, null);
            }
            if (($infra['webhooks_failed_1h'] ?? 0) >= 20) {
                $add('infra.webhooks_failed', 'attention', 'automacoes', "{$infra['webhooks_failed_1h']} entregas de webhook falharam na última hora", 'Pode ser o servidor de um integrador fora do ar; veja a lista em Automações.', null);
            }
            if ($infra['web_server'] === 'cli-server') {
                $add('infra.cli_server', 'attention', 'infraestrutura', 'Site servido pelo servidor embutido do PHP (php artisan serve)', 'Limita requisições simultâneas.', null);
            }
        }
        if (($auto['ai_errors_1h'] ?? 0) >= 5) {
            $add('automations.ai_errors', 'attention', 'automacoes', "Assistente IA com {$auto['ai_errors_1h']} falhas do provedor na última hora", 'Confira a chave e o status do provedor em Assistente IA.', '/plataforma/assistente-ia');
        }
        if (($exec['disputed_open'] ?? 0) > 0) {
            $add('payments.med', 'attention', 'pagamentos', "{$exec['disputed_open']} pedido(s) em MED", null, '/plataforma/disputas');
        }

        usort($a, fn ($x, $y) => OpsAlert::SEVERITY_RANK[$x['severity']] <=> OpsAlert::SEVERITY_RANK[$y['severity']]);

        return $a;
    }

    /**
     * Grava os alertas atuais na central: novos abrem, os que continuam são atualizados,
     * os que sumiram são resolvidos. Retorna [abertos, resolvidos].
     *
     * @return array{0: int, 1: int}
     */
    public function syncAlerts(array $alerts): array
    {
        $now = now();
        $current = OpsAlert::query()->whereIn('status', ['open', 'acknowledged'])->get()->keyBy('key');
        $opened = 0;
        foreach ($alerts as $al) {
            $row = $current->pull($al['key']);
            $attrs = ['severity' => $al['severity'], 'area' => $al['area'], 'title' => mb_substr($al['title'], 0, 255),
                'hint' => $al['hint'] ? mb_substr($al['hint'], 0, 500) : null, 'href' => $al['href'], 'last_seen_at' => $now];
            if ($row) {
                $row->update($attrs);
            } else {
                OpsAlert::query()->create($attrs + ['key' => $al['key'], 'status' => 'open', 'first_seen_at' => $now]);
                $opened++;
            }
        }
        foreach ($current as $gone) {
            $gone->update(['status' => 'resolved', 'resolved_at' => $now]);
        }

        return [$opened, $current->count()];
    }

    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
