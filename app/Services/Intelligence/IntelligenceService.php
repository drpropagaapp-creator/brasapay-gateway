<?php

namespace App\Services\Intelligence;

use App\Models\AdSpend;
use App\Models\Order;
use App\Support\RecoveryEmailSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Brasa Intelligence — análise do seller a partir de dados reais da plataforma.
 *
 * - Receita = apenas pedidos com pagamento confirmado (status completed); valores em centavos.
 * - ROAS/CAC só existem onde o seller informou custo (ad_spends); sem custo → null, nunca estimado.
 * - Insights são regras determinísticas com a evidência numérica junto; nenhuma ação automática.
 */
class IntelligenceService
{
    /** Status de pedido cujo pagamento foi aprovado em algum momento (para taxa de aprovação). */
    private const APPROVED = ['completed', 'refunded', 'disputed'];

    private const NO_SOURCE = '(sem origem)';

    /**
     * @param  list<string>|null  $allowedProducts  null = todos os produtos do tenant
     * @return array<string, mixed>
     */
    public function report(int $tenantId, ?array $allowedProducts, CarbonInterface $start, CarbonInterface $end, bool $includeSpend = true): array
    {
        $days = max(1, (int) ceil($start->diffInDays($end)));
        $prevEnd = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subDays($days)->addSecond();

        $attribution = $this->attribution($tenantId, $allowedProducts, $start, $end);
        // Custo é do tenant inteiro: quem só vê parte dos produtos (equipe) não recebe ROAS/CAC distorcido.
        $spend = $includeSpend ? $this->spend($tenantId, $start, $end) : ['total_cents' => 0, 'by_source' => [], 'by_campaign' => []];
        $sources = $this->mergeSpend($attribution['sources'], $spend['by_source'], $attribution['new_by_source']);
        $campaigns = $this->mergeSpend($attribution['campaigns'], $spend['by_campaign'], $attribution['new_by_campaign']);

        $payments = $this->paymentHealth($tenantId, $allowedProducts, $start, $end);
        $paymentsPrev = $this->paymentHealth($tenantId, $allowedProducts, $prevStart, $prevEnd);
        $products = $this->productHealth($tenantId, $allowedProducts, $start, $end);

        $report = [
            'period' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'days' => $days],
            'totals' => [
                'revenue_cents' => $attribution['revenue_cents'],
                'paid_orders' => $attribution['paid_orders'],
                'new_customers' => $attribution['new_customers'],
                'spend_cents' => $spend['total_cents'],
                'roas' => $this->ratio($attribution['revenue_cents'], $spend['total_cents']),
                'cac_cents' => $spend['total_cents'] > 0 && $attribution['new_customers'] > 0 ? intdiv($spend['total_cents'], $attribution['new_customers']) : null,
                'unattributed_revenue_cents' => $attribution['sources'][self::NO_SOURCE]['revenue_cents'] ?? 0,
            ],
            'sources' => array_values($sources),
            'campaigns' => array_values($campaigns),
            'payments' => array_values($payments),
            'products' => array_values($products),
        ];
        $report['insights'] = $this->insights($tenantId, $allowedProducts, $report, $paymentsPrev, $start, $end);

        return $report;
    }

    /**
     * Receita confirmada por origem/campanha. UTM vem da sessão de checkout do pedido e, na falta,
     * do metadata do pedido. Cliente novo = primeira compra paga do e-mail, sem compra paga antes do período.
     */
    private function attribution(int $tenantId, ?array $allowed, CarbonInterface $start, CarbonInterface $end): array
    {
        $out = ['sources' => [], 'campaigns' => [], 'new_by_source' => [], 'new_by_campaign' => [], 'revenue_cents' => 0, 'paid_orders' => 0, 'new_customers' => 0];
        $seen = [];

        $this->ordersQuery($tenantId, $allowed, $start, $end)
            ->where('status', 'completed')
            ->select(['id', 'email', 'amount', 'metadata'])
            ->chunkById(1000, function ($orders) use (&$out, &$seen, $tenantId, $start) {
                $ids = $orders->pluck('id')->all();
                $sessionUtm = DB::table('checkout_sessions')->whereIn('order_id', $ids)
                    ->get(['order_id', 'utm_source', 'utm_campaign'])->keyBy('order_id');

                $emails = $orders->map(fn ($o) => strtolower(trim((string) $o->email)))->filter()->unique()->values()->all();
                $returning = $emails === [] ? [] : array_flip(Order::query()->where('tenant_id', $tenantId)
                    ->whereIn('status', self::APPROVED)->where('created_at', '<', $start)
                    ->whereIn(DB::raw('LOWER(email)'), $emails)
                    ->selectRaw('DISTINCT LOWER(email) as e')->pluck('e')->all());

                foreach ($orders as $o) {
                    $cents = (int) bcmul((string) $o->amount, '100', 0);
                    $meta = is_array($o->metadata) ? $o->metadata : [];
                    $s = $sessionUtm->get($o->id);
                    $source = $this->label($s->utm_source ?? null) ?? $this->label($meta['utm_source'] ?? null) ?? self::NO_SOURCE;
                    $campaign = $this->label($s->utm_campaign ?? null) ?? $this->label($meta['utm_campaign'] ?? null);
                    $campKey = $source.'|'.($campaign ?? '');

                    $out['revenue_cents'] += $cents;
                    $out['paid_orders']++;
                    $out['sources'][$source] ??= ['source' => $source, 'campaign' => null, 'paid_orders' => 0, 'revenue_cents' => 0];
                    $out['sources'][$source]['paid_orders']++;
                    $out['sources'][$source]['revenue_cents'] += $cents;
                    $out['campaigns'][$campKey] ??= ['source' => $source, 'campaign' => $campaign, 'paid_orders' => 0, 'revenue_cents' => 0];
                    $out['campaigns'][$campKey]['paid_orders']++;
                    $out['campaigns'][$campKey]['revenue_cents'] += $cents;

                    $email = strtolower(trim((string) $o->email));
                    if ($email !== '' && ! isset($seen[$email]) && ! isset($returning[$email])) {
                        $out['new_customers']++;
                        $out['new_by_source'][$source] = ($out['new_by_source'][$source] ?? 0) + 1;
                        $out['new_by_campaign'][$campKey] = ($out['new_by_campaign'][$campKey] ?? 0) + 1;
                    }
                    if ($email !== '') {
                        $seen[$email] = true;
                    }
                }
            });

        return $out;
    }

    /** @return array{total_cents:int, by_source: array<string,int>, by_campaign: array<string,int>} */
    private function spend(int $tenantId, CarbonInterface $start, CarbonInterface $end): array
    {
        $out = ['total_cents' => 0, 'by_source' => [], 'by_campaign' => []];
        $rows = AdSpend::query()->where('tenant_id', $tenantId)
            ->whereBetween('spent_on', [$start->toDateString(), $end->toDateString()])
            ->get(['source', 'campaign', 'amount_cents']);
        foreach ($rows as $r) {
            $source = $this->label($r->source) ?? self::NO_SOURCE;
            $out['total_cents'] += $r->amount_cents;
            $out['by_source'][$source] = ($out['by_source'][$source] ?? 0) + $r->amount_cents;
            $campaign = $this->label($r->campaign);
            if ($campaign !== null) {
                $key = $source.'|'.$campaign;
                $out['by_campaign'][$key] = ($out['by_campaign'][$key] ?? 0) + $r->amount_cents;
            }
        }

        return $out;
    }

    /** Junta receita e custo; linhas só com custo também aparecem (ROAS 0). */
    private function mergeSpend(array $rows, array $spend, array $newCustomers): array
    {
        foreach ($spend as $key => $cents) {
            if (! isset($rows[$key])) {
                [$source, $campaign] = array_pad(explode('|', $key, 2), 2, null);
                $rows[$key] = ['source' => $source, 'campaign' => $campaign, 'paid_orders' => 0, 'revenue_cents' => 0];
            }
        }
        foreach ($rows as $key => &$row) {
            $cost = $spend[$key] ?? null;
            $new = $newCustomers[$key] ?? 0;
            $row['new_customers'] = $new;
            $row['spend_cents'] = $cost;
            $row['roas'] = $cost ? $this->ratio($row['revenue_cents'], $cost) : null;
            $row['cac_cents'] = $cost && $new > 0 ? intdiv($cost, $new) : null;
        }
        unset($row);
        uasort($rows, fn ($a, $b) => [$b['revenue_cents'], $b['spend_cents'] ?? 0] <=> [$a['revenue_cents'], $a['spend_cents'] ?? 0]);

        return array_slice($rows, 0, 100, true);
    }

    /** Taxa de aprovação por método (pedidos novos, sem renovações automáticas). */
    private function paymentHealth(int $tenantId, ?array $allowed, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = $this->ordersQuery($tenantId, $allowed, $start, $end)
            ->where(fn ($q) => $q->where('is_renewal', false)->orWhereNull('is_renewal'))
            ->groupBy('payment_method', 'status')
            ->selectRaw('payment_method, status, COUNT(*) as n, COALESCE(SUM(amount),0) as total')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $method = $r->payment_method ?: 'outro';
            $out[$method] ??= ['method' => $method, 'attempts' => 0, 'approved' => 0, 'paid' => 0, 'pending' => 0, 'revenue_cents' => 0];
            $out[$method]['attempts'] += (int) $r->n;
            if (in_array($r->status, self::APPROVED, true)) {
                $out[$method]['approved'] += (int) $r->n;
            }
            if ($r->status === 'completed') {
                $out[$method]['paid'] += (int) $r->n;
                $out[$method]['revenue_cents'] += (int) bcmul((string) $r->total, '100', 0);
            }
            if ($r->status === 'pending') {
                $out[$method]['pending'] += (int) $r->n;
            }
        }
        foreach ($out as &$m) {
            $m['approval_rate'] = $m['attempts'] > 0 ? round($m['approved'] / $m['attempts'], 4) : null;
        }
        unset($m);
        uasort($out, fn ($a, $b) => $b['attempts'] <=> $a['attempts']);

        return $out;
    }

    /** Vendas, receita, reembolsos e disputas por produto. */
    private function productHealth(int $tenantId, ?array $allowed, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = $this->ordersQuery($tenantId, $allowed, $start, $end)
            ->whereIn('orders.status', self::APPROVED)
            ->leftJoin('products', 'products.id', '=', 'orders.product_id')
            ->groupBy('orders.product_id', 'products.name', 'orders.status')
            ->selectRaw('orders.product_id, products.name as name, orders.status, COUNT(*) as n, COALESCE(SUM(orders.amount),0) as total')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $id = (string) $r->product_id;
            $out[$id] ??= ['product_id' => $id, 'name' => $r->name ?? 'Produto removido', 'paid' => 0, 'refunded' => 0, 'disputed' => 0, 'revenue_cents' => 0];
            $n = (int) $r->n;
            match ($r->status) {
                'completed' => $out[$id]['paid'] += $n,
                'refunded' => $out[$id]['refunded'] += $n,
                default => $out[$id]['disputed'] += $n,
            };
            if ($r->status === 'completed') {
                $out[$id]['revenue_cents'] += (int) bcmul((string) $r->total, '100', 0);
            }
        }
        foreach ($out as &$p) {
            $sold = $p['paid'] + $p['refunded'] + $p['disputed'];
            $p['loss_rate'] = $sold > 0 ? round(($p['refunded'] + $p['disputed']) / $sold, 4) : null;
        }
        unset($p);
        uasort($out, fn ($a, $b) => $b['revenue_cents'] <=> $a['revenue_cents']);

        return array_slice($out, 0, 50, true);
    }

    /** Regras com evidência. Nada aqui executa ação: só aponta onde olhar. */
    private function insights(int $tenantId, ?array $allowed, array $report, array $paymentsPrev, CarbonInterface $start, CarbonInterface $end): array
    {
        $insights = [];

        foreach ($report['campaigns'] as $c) {
            if ($c['spend_cents'] && $c['spend_cents'] >= 5000 && $c['roas'] !== null && bccomp($c['roas'], '1', 2) < 0) {
                $insights[] = ['level' => 'danger', 'title' => 'Campanha gastando mais do que fatura',
                    'detail' => sprintf('%s / %s: custo de %s e receita confirmada de %s (ROAS %s).', $c['source'], $c['campaign'], $this->brl($c['spend_cents']), $this->brl($c['revenue_cents']), $c['roas'])];
            }
        }

        foreach ($report['payments'] as $m) {
            $prev = $paymentsPrev[$m['method']] ?? null;
            if ($prev && $m['attempts'] >= 20 && $prev['attempts'] >= 20 && $m['approval_rate'] !== null && $prev['approval_rate'] !== null
                && ($prev['approval_rate'] - $m['approval_rate']) >= 0.10) {
                $insights[] = ['level' => 'warning', 'title' => 'Queda na aprovação de '.$this->methodLabel($m['method']),
                    'detail' => sprintf('Aprovação caiu de %.1f%% para %.1f%% em relação ao período anterior (%d tentativas agora, %d antes).', $prev['approval_rate'] * 100, $m['approval_rate'] * 100, $m['attempts'], $prev['attempts'])];
            }
        }

        foreach ($report['products'] as $p) {
            $sold = $p['paid'] + $p['refunded'] + $p['disputed'];
            if ($sold >= 20 && $p['loss_rate'] !== null && $p['loss_rate'] >= 0.05) {
                $insights[] = ['level' => 'warning', 'title' => 'Reembolsos e disputas altos em '.$p['name'],
                    'detail' => sprintf('%d de %d vendas aprovadas foram reembolsadas ou contestadas (%.1f%%).', $p['refunded'] + $p['disputed'], $sold, $p['loss_rate'] * 100)];
            }
        }

        $pending = $this->ordersQuery($tenantId, $allowed, $start, $end)->where('status', 'pending')
            ->whereIn('payment_method', ['pix', 'boleto'])->where('created_at', '<', now()->subHour())->count();
        if ($pending >= 10 && ! (RecoveryEmailSettings::forTenant($tenantId)['enabled'] ?? false)) {
            $insights[] = ['level' => 'info', 'title' => 'Pix e boletos gerados sem pagamento',
                'detail' => sprintf('%d pedidos de Pix/boleto ficaram pendentes no período. A Recuperação de vendas por e-mail está desligada.', $pending),
                'href' => '/recuperacao'];
        }

        $t = $report['totals'];
        if ($t['paid_orders'] >= 20 && $t['revenue_cents'] > 0 && $t['unattributed_revenue_cents'] * 2 >= $t['revenue_cents']) {
            $insights[] = ['level' => 'info', 'title' => 'Metade da receita sem origem identificada',
                'detail' => sprintf('%s de %s vieram sem UTM. Use links com utm_source e utm_campaign nos anúncios para saber o que vende.', $this->brl($t['unattributed_revenue_cents']), $this->brl($t['revenue_cents']))];
        }

        if ($t['spend_cents'] === 0 && $t['paid_orders'] > 0 && $allowed === null) {
            $insights[] = ['level' => 'info', 'title' => 'ROAS e CAC precisam do custo dos anúncios',
                'detail' => 'Nenhum custo de anúncio informado no período. Cadastre o investimento por origem/campanha para ver ROAS e CAC reais.'];
        }

        return $insights;
    }

    private function ordersQuery(int $tenantId, ?array $allowed, CarbonInterface $start, CarbonInterface $end)
    {
        return Order::query()->where('orders.tenant_id', $tenantId)
            ->whereBetween('orders.created_at', [$start, $end])
            ->when($allowed !== null, fn ($q) => $q->whereIn('orders.product_id', $allowed ?: ['__none__']));
    }

    private function label(mixed $v): ?string
    {
        $v = is_scalar($v) ? mb_strtolower(trim((string) $v)) : '';

        return $v === '' ? null : mb_substr($v, 0, 191);
    }

    private function ratio(int $revenueCents, int $spendCents): ?string
    {
        return $spendCents > 0 ? bcdiv((string) $revenueCents, (string) $spendCents, 2) : null;
    }

    private function brl(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    private function methodLabel(string $m): string
    {
        return ['pix' => 'Pix', 'card' => 'cartão', 'credit_card' => 'cartão', 'boleto' => 'boleto', 'pix_auto' => 'Pix Automático', 'paypal' => 'PayPal'][$m] ?? $m;
    }
}
