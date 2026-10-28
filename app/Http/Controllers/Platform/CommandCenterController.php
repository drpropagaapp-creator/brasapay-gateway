<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RiskAssessment;
use App\Models\TreasuryDiscrepancy;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payments\AcquirerHealthService;
use App\Services\Platform\PlatformRevenueKpis;
use App\Support\SqlDialect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brasa Command Center v1 — visão única de operação (somente leitura). Só dados reais:
 * quando uma fonte não existe ou falha, o bloco aparece como indisponível, nunca inventado.
 */
class CommandCenterController extends Controller
{
    private const QUEUES = ['payments', 'default', 'payouts', 'webhooks', 'webhooks-outbound', 'webhooks-inbound', 'meta-tracking', 'utmify-tracking', 'uazapi', 'integrax-sms'];

    public function index(AcquirerHealthService $health): Response
    {
        $data = Cache::remember('command_center:v1', 60, fn () => $this->build($health));

        return Inertia::render('Platform/CommandCenter', $data + ['generated_at' => now()->toIso8601String()]);
    }

    /** @return array<string, mixed> */
    private function build(AcquirerHealthService $health): array
    {
        $today = now()->startOfDay();
        $week = now()->subDays(7);
        $alerts = [];

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

        $acquirers = $this->safe(function () use ($health) {
            $out = [];
            foreach ($health->stats(1, 10) as $method => $slugs) {
                foreach ($slugs as $slug => $m) {
                    $out[] = ['method' => $method, 'slug' => $slug] + array_intersect_key($m, array_flip(['attempts', 'success_rate', 'technical_error_rate', 'timeout_rate', 'latency_p95_ms', 'sufficient_sample']));
                }
            }

            return $out;
        });

        $infra = $this->safe(function () {
            $queues = [];
            foreach (self::QUEUES as $q) {
                try {
                    $queues[$q] = Queue::size($q);
                } catch (\Throwable) {
                    $queues[$q] = null;
                }
            }
            $ago = fn ($iso) => $iso ? (int) Carbon::parse($iso)->diffInMinutes(now()) : null;

            return [
                'scheduler_minutes_ago' => $ago(Cache::get('schedule_heartbeat')),
                'queue_minutes_ago' => $ago(Cache::get('queue_heartbeat')),
                'failed_jobs_24h' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : null,
                'queues' => $queues,
                'web_server' => PHP_SAPI,
            ];
        });

        // Alertas derivados só de números reais acima.
        if (($treasury['critical'] ?? 0) > 0) {
            $alerts[] = ['severity' => 'critical', 'text' => "{$treasury['critical']} divergência(s) financeira(s) crítica(s) na tesouraria", 'href' => '/plataforma/tesouraria'];
        }
        if (($withdrawals['investigating'] ?? 0) > 0) {
            $alerts[] = ['severity' => 'high', 'text' => "{$withdrawals['investigating']} saque(s) sem confirmação da adquirente", 'href' => '/plataforma/tesouraria?kind=withdrawal_reconcile_exhausted'];
        }
        if (($risk['high_open'] ?? 0) > 0) {
            $alerts[] = ['severity' => 'high', 'text' => "{$risk['high_open']} análise(s) de risco alto em aberto", 'href' => '/plataforma/risco'];
        }
        foreach ($acquirers ?? [] as $a) {
            if (! empty($a['sufficient_sample']) && ($a['technical_error_rate'] > 0.1 || $a['timeout_rate'] > 0.05)) {
                $alerts[] = ['severity' => 'high', 'text' => sprintf('%s (%s): %.0f%% de erro técnico e %.0f%% de timeout na última hora', $a['slug'], $a['method'], $a['technical_error_rate'] * 100, $a['timeout_rate'] * 100), 'href' => '/plataforma/ops/adquirentes?horas=1'];
            }
        }
        if ($infra !== null) {
            if ($infra['scheduler_minutes_ago'] === null || $infra['scheduler_minutes_ago'] > 5) {
                $alerts[] = ['severity' => 'critical', 'text' => 'Agendador sem sinal há mais de 5 minutos (liberações, conciliações e cobranças param)', 'href' => '/plataforma/ops/saude-pagamentos'];
            }
            if (($infra['failed_jobs_24h'] ?? 0) > 0) {
                $alerts[] = ['severity' => 'attention', 'text' => "{$infra['failed_jobs_24h']} job(s) falharam nas últimas 24 h", 'href' => null];
            }
            foreach ($infra['queues'] as $q => $size) {
                if ($size !== null && $size > 500) {
                    $alerts[] = ['severity' => 'attention', 'text' => "Fila {$q} com {$size} jobs aguardando", 'href' => null];
                }
            }
            if ($infra['web_server'] === 'cli-server') {
                $alerts[] = ['severity' => 'attention', 'text' => 'Site servido pelo servidor embutido do PHP (php artisan serve): limita requisições simultâneas', 'href' => null];
            }
        }
        if (($executive['disputed_open'] ?? 0) > 0) {
            $alerts[] = ['severity' => 'attention', 'text' => "{$executive['disputed_open']} pedido(s) em MED", 'href' => '/plataforma/disputas'];
        }

        $rank = ['critical' => 0, 'high' => 1, 'attention' => 2, 'info' => 3];
        usort($alerts, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return compact('executive', 'sellers', 'withdrawals', 'treasury', 'risk', 'acquirers', 'infra', 'alerts');
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
