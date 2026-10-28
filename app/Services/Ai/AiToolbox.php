<?php

namespace App\Services\Ai;

use App\Models\CheckoutSession;
use App\Models\Experiment;
use App\Models\Order;
use App\Models\RecoveryMessage;
use App\Models\TenantWallet;
use App\Models\User;
use App\Services\Experiments\ExperimentService;
use App\Services\Intelligence\IntelligenceService;
use App\Services\MerchantWalletAdminBlockService;
use App\Services\Subscriptions\SubscriptionMetricsService;
use App\Services\TeamAccessService;
use App\Support\RecoveryEmailSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ferramentas do Brasa AI: só leitura, só agregados, sem dados pessoais de compradores.
 * O tenant e os produtos liberados vêm do usuário logado — nunca de parâmetro do modelo.
 * Nenhuma ferramenta muda preço, adquirente, campanha, página, reembolso, saldo, saque ou permissão.
 */
class AiToolbox
{
    private const PERIOD = ['type' => 'string', 'enum' => ['hoje', '7d', '30d', '90d'], 'description' => 'Janela de tempo.'];

    private int $tenantId;

    /** @var list<string>|null */
    private ?array $allowed;

    public function __construct(private User $user)
    {
        $this->tenantId = (int) $user->tenant_id;
        $this->allowed = $user->isTeam() ? array_map('strval', app(TeamAccessService::class)->allowedProductIdsFor($user)) : null;
    }

    /** @return list<array{name: string, description: string, schema: array}> */
    public function definitions(): array
    {
        $period = fn (string $desc) => [
            'name' => '', 'description' => $desc,
            'schema' => ['type' => 'object', 'properties' => ['periodo' => self::PERIOD], 'required' => ['periodo'], 'additionalProperties' => false],
        ];
        $none = ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];

        $defs = [
            ['name' => 'vendas_resumo'] + $period('Vendas pagas, receita confirmada, clientes novos e aprovação por método de pagamento na janela, com o período anterior para comparar.'),
            ['name' => 'vendas_por_dia'] + $period('Receita confirmada e vendas pagas dia a dia na janela (para entender quedas e picos).'),
            ['name' => 'produtos_desempenho'] + $period('Por produto: vendas pagas, receita, reembolsos e disputas.'),
            ['name' => 'funil_checkout'] + $period('Por produto: visitas ao checkout, quem começou a preencher, quem preencheu e quem converteu (abandono).'),
            ['name' => 'campanhas'] + $period('Receita por origem/campanha (UTM). ROAS e CAC só aparecem onde o seller informou custo de anúncio.'),
            ['name' => 'alertas'] + $period('Alertas automáticos da plataforma com os números que os geraram.'),
            ['name' => 'assinaturas', 'description' => 'Recorrência: MRR, assinaturas ativas, atrasadas, cancelamentos agendados, churn de 30 dias e renovações dos próximos 7 dias.', 'schema' => $none],
            ['name' => 'testes_ab', 'description' => 'Testes A/B da landing: status, resultados por variante e conclusão estatística.', 'schema' => $none],
            ['name' => 'recuperacao', 'description' => 'Recuperação de vendas por e-mail: se está ligada e o resultado dos últimos 30 dias.', 'schema' => $none],
        ];
        if ($this->canSeeFinance()) {
            $defs[] = ['name' => 'saldo', 'description' => 'Saldo disponível para saque por método e saldo pendente (só consulta).', 'schema' => $none];
        }

        return $defs;
    }

    public function run(string $name, array $input): array
    {
        [$start, $end, $days] = $this->window((string) ($input['periodo'] ?? '30d'));

        return match ($name) {
            'vendas_resumo' => $this->salesSummary($start, $end, $days),
            'vendas_por_dia' => $this->salesByDay($start, $end),
            'produtos_desempenho' => ['periodo_dias' => $days, 'produtos' => array_map(fn ($p) => [
                'produto' => $p['name'], 'vendas_pagas' => $p['paid'], 'receita' => $this->brl($p['revenue_cents']),
                'reembolsos' => $p['refunded'], 'disputas' => $p['disputed'], 'taxa_perda' => $p['loss_rate'],
            ], array_slice($this->report($start, $end)['products'], 0, 15))],
            'funil_checkout' => $this->funnel($start, $end, $days),
            'campanhas' => ['periodo_dias' => $days, 'custo_informado' => $this->canSeeFinance(), 'campanhas' => array_map(fn ($c) => [
                'origem' => $c['source'], 'campanha' => $c['campaign'], 'vendas_pagas' => $c['paid_orders'], 'receita' => $this->brl($c['revenue_cents']),
                'clientes_novos' => $c['new_customers'], 'custo' => $c['spend_cents'] === null ? null : $this->brl($c['spend_cents']),
                'roas' => $c['roas'], 'cac' => $c['cac_cents'] === null ? null : $this->brl($c['cac_cents']),
            ], array_slice($this->report($start, $end)['campaigns'], 0, 15))],
            'alertas' => ['periodo_dias' => $days, 'alertas' => $this->report($start, $end)['insights']],
            'assinaturas' => app(SubscriptionMetricsService::class)->metrics($this->tenantId, $this->allowed),
            'testes_ab' => $this->experiments(),
            'recuperacao' => $this->recovery(),
            'saldo' => $this->balance(),
            default => throw new \InvalidArgumentException('Ferramenta desconhecida.'),
        };
    }

    private function canSeeFinance(): bool
    {
        return ! $this->user->isTeam() || app(TeamAccessService::class)->can($this->user, 'financeiro.view');
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface, 2: int} */
    private function window(string $period): array
    {
        $days = ['hoje' => 1, '7d' => 7, '30d' => 30, '90d' => 90][$period] ?? 30;

        return [now()->subDays($days - 1)->startOfDay(), now(), $days];
    }

    private function report(CarbonInterface $start, CarbonInterface $end): array
    {
        return app(IntelligenceService::class)->report($this->tenantId, $this->allowed, $start, $end, $this->canSeeFinance());
    }

    private function salesSummary(CarbonInterface $start, CarbonInterface $end, int $days): array
    {
        $now = $this->report($start, $end);
        $prev = $this->report($start->copy()->subDays($days), $start->copy()->subSecond());
        $t = $now['totals'];

        return [
            'periodo_dias' => $days,
            'receita_confirmada' => $this->brl($t['revenue_cents']), 'vendas_pagas' => $t['paid_orders'], 'clientes_novos' => $t['new_customers'],
            'ticket_medio' => $t['paid_orders'] > 0 ? $this->brl(intdiv($t['revenue_cents'], $t['paid_orders'])) : null,
            'periodo_anterior' => ['receita_confirmada' => $this->brl($prev['totals']['revenue_cents']), 'vendas_pagas' => $prev['totals']['paid_orders']],
            'aprovacao_por_metodo' => array_map(fn ($m) => ['metodo' => $m['method'], 'tentativas' => $m['attempts'], 'aprovacao' => $m['approval_rate'], 'pendentes' => $m['pending']], $now['payments']),
            'observacao' => 'Receita conta só pagamento confirmado.',
        ];
    }

    private function salesByDay(CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = Order::query()->where('tenant_id', $this->tenantId)->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->when($this->allowed !== null, fn ($q) => $q->whereIn('product_id', $this->allowed ?: ['__none__']))
            ->selectRaw('DATE(created_at) as dia, COUNT(*) as vendas, COALESCE(SUM(amount),0) as receita')
            ->groupBy(DB::raw('DATE(created_at)'))->orderBy('dia')->get();

        return ['dias' => $rows->map(fn ($r) => ['dia' => (string) $r->dia, 'vendas_pagas' => (int) $r->vendas,
            'receita' => $this->brl((int) bcmul((string) $r->receita, '100', 0))])->values()->all()];
    }

    private function funnel(CarbonInterface $start, CarbonInterface $end, int $days): array
    {
        $rows = CheckoutSession::query()->where('checkout_sessions.tenant_id', $this->tenantId)
            ->whereBetween('checkout_sessions.created_at', [$start, $end])
            ->when($this->allowed !== null, fn ($q) => $q->whereIn('checkout_sessions.product_id', $this->allowed ?: ['__none__']))
            ->leftJoin('products', 'products.id', '=', 'checkout_sessions.product_id')
            ->groupBy('checkout_sessions.product_id', 'products.name')
            ->selectRaw("products.name as produto, COUNT(*) as visitas,
                SUM(CASE WHEN checkout_sessions.step IN ('form_started','form_filled','converted') THEN 1 ELSE 0 END) as comecaram,
                SUM(CASE WHEN checkout_sessions.step IN ('form_filled','converted') THEN 1 ELSE 0 END) as preencheram,
                SUM(CASE WHEN checkout_sessions.step = 'converted' OR checkout_sessions.order_id IS NOT NULL THEN 1 ELSE 0 END) as converteram")
            ->orderByDesc('visitas')->limit(15)->get();

        return ['periodo_dias' => $days, 'observacao' => 'Converteu = gerou pedido; pagamento confirmado está em vendas_resumo.',
            'produtos' => $rows->map(fn ($r) => ['produto' => $r->produto ?? 'Produto removido', 'visitas' => (int) $r->visitas,
                'comecaram' => (int) $r->comecaram, 'preencheram' => (int) $r->preencheram, 'converteram' => (int) $r->converteram])->values()->all()];
    }

    private function experiments(): array
    {
        $svc = app(ExperimentService::class);

        return ['testes' => Experiment::query()->where('tenant_id', $this->tenantId)
            ->when($this->allowed !== null, fn ($q) => $q->whereIn('product_id', $this->allowed ?: ['__none__']))
            ->latest('id')->limit(10)->get()
            ->map(fn (Experiment $e) => ['nome' => $e->name, 'status' => $e->status, 'resultado' => $svc->results($e)])->values()->all()];
    }

    private function recovery(): array
    {
        $since = now()->subDays(30);
        $q = RecoveryMessage::query()->where('tenant_id', $this->tenantId)->where('created_at', '>=', $since);

        return [
            'ligada' => (bool) RecoveryEmailSettings::forTenant($this->tenantId)['enabled'],
            'enviados_30d' => (clone $q)->where('status', 'sent')->count(),
            'recuperados_30d' => (clone $q)->whereNotNull('recovered_order_id')->count(),
            'receita_recuperada_30d' => $this->brl((int) (clone $q)->whereNotNull('recovered_order_id')->sum('recovered_amount_cents')),
        ];
    }

    private function balance(): array
    {
        abort_unless($this->canSeeFinance(), 403);
        $wallet = TenantWallet::query()->where('tenant_id', $this->tenantId)->first();
        if ($wallet === null) {
            return ['disponivel_total' => $this->brl(0), 'pendente' => $this->brl(0)];
        }
        $cents = fn ($v) => (int) bcmul(number_format((float) $v, 2, '.', ''), '100', 0);

        return [
            'disponivel_para_saque' => [
                'pix' => $this->brl($cents(MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, 'pix'))),
                'cartao' => $this->brl($cents(MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, 'card'))),
                'boleto' => $this->brl($cents(MerchantWalletAdminBlockService::effectiveAvailableForWithdrawal($wallet, 'boleto'))),
            ],
            'pendente' => $this->brl($cents($wallet->pending_balance ?? 0)),
            'observacao' => 'Só consulta. Saques são feitos pelo seller na tela Financeiro.',
        ];
    }

    private function brl(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
