<?php

namespace App\Services\Subscriptions;

use App\Models\Subscription;

/**
 * Brasa Subscriptions — métricas de recorrência com definições consistentes:
 * - MRR: soma do preço mensalizado das assinaturas ATIVAS (semanal ×52/12, mensal ×1,
 *   trimestral ÷3, semestral ÷6, anual ÷12). Vitalício não é recorrente e fica de fora.
 *   Planos em outra moeda são convertidos para BRL pelas mesmas taxas do checkout.
 * - ARR = MRR × 12.
 * - Churn 30 d = canceladas nos últimos 30 dias ÷ (ativas + canceladas nos últimos 30 dias).
 * Valores somados em centavos (bcmath).
 */
class SubscriptionMetricsService
{
    private const MONTHLY_FACTOR = [
        'weekly' => '4.333333',
        'monthly' => '1',
        'quarterly' => '0.333333',
        'semi_annual' => '0.166667',
        'annual' => '0.083333',
    ];

    /** @param list<string>|null $allowedProducts */
    public function metrics(int $tenantId, ?array $allowedProducts): array
    {
        $base = fn () => Subscription::query()->where('subscriptions.tenant_id', $tenantId)
            ->when($allowedProducts !== null, fn ($q) => $q->whereIn('subscriptions.product_id', $allowedProducts ?: ['__none__']));

        $mrrCents = '0';
        $riskCents = '0';
        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        $base()->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
            ->select(['subscription_plans.price', 'subscription_plans.interval', 'subscription_plans.currency', 'subscriptions.cancel_at_period_end'])
            ->chunk(1000, function ($rows) use (&$mrrCents, &$riskCents, $rates) {
                foreach ($rows as $r) {
                    $factor = self::MONTHLY_FACTOR[$r->interval] ?? null;
                    if ($factor === null) {
                        continue; // vitalício
                    }
                    $price = sprintf('%.2F', (float) $r->price);
                    $cur = strtoupper((string) ($r->currency ?: 'BRL'));
                    if ($cur === 'EUR') {
                        $price = bcdiv($price, (string) ($rates['brl_eur'] ?? 0.16), 6);
                    } elseif ($cur === 'USD') {
                        $price = bcdiv($price, (string) ($rates['brl_usd'] ?? 0.18), 6);
                    }
                    $monthly = bcmul(bcmul($price, $factor, 8), '100', 0);
                    $mrrCents = bcadd($mrrCents, $monthly, 0);
                    if ($r->cancel_at_period_end) {
                        $riskCents = bcadd($riskCents, $monthly, 0);
                    }
                }
            });

        $active = $base()->where('subscriptions.status', Subscription::STATUS_ACTIVE)->count();
        $cancelled30 = $base()->where('subscriptions.status', Subscription::STATUS_CANCELLED)->where('subscriptions.updated_at', '>=', now()->subDays(30))->count();
        $reasons = $base()->whereNotNull('cancellation_reason')->where('cancellation_requested_at', '>=', now()->subDays(90))
            ->selectRaw('cancellation_reason, COUNT(*) as n')->groupBy('cancellation_reason')->pluck('n', 'cancellation_reason');

        return [
            'mrr' => (float) bcdiv($mrrCents, '100', 2),
            'arr' => (float) bcdiv(bcmul($mrrCents, '12', 0), '100', 2),
            'mrr_at_risk' => (float) bcdiv($riskCents, '100', 2),
            'active' => $active,
            'past_due' => $base()->where('subscriptions.status', Subscription::STATUS_PAST_DUE)->count(),
            'cancel_requested' => $base()->where('subscriptions.status', Subscription::STATUS_ACTIVE)->where('cancel_at_period_end', true)->count(),
            'churn_30d' => ($active + $cancelled30) > 0 ? round($cancelled30 / ($active + $cancelled30), 4) : null,
            'renewals_7d' => $base()->where('subscriptions.status', Subscription::STATUS_ACTIVE)
                ->where(fn ($q) => $q->where('cancel_at_period_end', false)->orWhereNull('cancel_at_period_end'))
                ->whereBetween('current_period_end', [now()->toDateString(), now()->addDays(7)->toDateString()])->count(),
            'cancel_reasons_90d' => $reasons,
        ];
    }
}
