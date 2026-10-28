<?php

namespace App\Services\LinaOpenx;

use App\Events\OrderPending;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Agenda o próximo débito OpenX (POST recurring-payments) no ciclo da assinatura.
 */
class LinaOpenxPixAutoRenewalService
{
    public function __construct(
        private readonly LinaOpenxPixAutoService $pixAuto = new LinaOpenxPixAutoService()
    ) {}

    public function ensureNextPayment(Subscription $subscription, ?Order $sourceOrder = null): ?Order
    {
        $consentId = trim((string) ($subscription->gateway_subscription_id ?? ''));
        if ($consentId === '') {
            return null;
        }

        $plan = $subscription->subscriptionPlan;
        if ($plan === null || $plan->isLifetime()) {
            return null;
        }

        [$periodStart, $periodEnd] = $this->nextPeriod($subscription, $plan);
        $periodEndStr = $periodEnd->toDateString();
        $lockKey = 'linaopenx_pix_auto_pay:'.$subscription->id.':'.$periodEndStr;
        if (! Cache::add($lockKey, 1, now()->addHours(12))) {
            return $this->findPendingRenewal($subscription, $periodEndStr);
        }

        try {
            if ($this->hasCompletedRenewalForPeriod($subscription, $periodEndStr)) {
                return null;
            }

            $existing = $this->findPendingRenewal($subscription, $periodEndStr);
            if ($existing !== null && trim((string) ($existing->metadata['linaopenx_pix_auto_recurring_payment_id'] ?? '')) !== '') {
                return $existing;
            }

            $credentials = $this->pixAuto->credentialsForTenant($subscription->tenant_id, $sourceOrder);
            if ($credentials === null) {
                Cache::forget($lockKey);

                return $existing;
            }

            $created = false;
            $order = $existing;
            if ($order === null) {
                $order = $this->createPendingRenewalOrder($subscription, $plan, $periodStart, $periodEnd, $consentId, $sourceOrder);
                $created = true;
                event(new OrderPending($order));
            }

            $payDate = $periodStart->toDateString();
            $paymentId = $this->pixAuto->postRecurringPaymentForOrder($order, $credentials, $consentId, $payDate);

            $meta = is_array($order->metadata) ? $order->metadata : [];
            $meta['checkout_payment_method'] = 'pix_auto';
            $meta['linaopenx_pix_auto_consent_id'] = $consentId;
            $meta['linaopenx_pix_auto_recurring_payment_id'] = $paymentId;
            $meta['subscription_id'] = $subscription->id;
            $order->update([
                'gateway' => LinaOpenxPixAutoService::GATEWAY_SLUG,
                'gateway_id' => $paymentId,
                'metadata' => $meta,
            ]);

            return $order->fresh();
        } catch (\Throwable $e) {
            Cache::forget($lockKey);
            Log::warning('LinaOpenxPixAutoRenewalService: falha ao agendar débito', [
                'subscription_id' => $subscription->id,
                'consent_id' => $consentId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
            if (isset($created, $order) && $created && $order instanceof Order && trim((string) $order->gateway_id) === '') {
                try {
                    $order->delete();
                } catch (\Throwable) {
                }
            }

            return null;
        }
    }

    public function findPendingRenewal(Subscription $subscription, string $periodEnd): ?Order
    {
        return Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('payment_method', 'pix_auto')
            ->where('gateway', LinaOpenxPixAutoService::GATEWAY_SLUG)
            ->where('is_renewal', true)
            ->where('status', 'pending')
            ->whereDate('period_end', $periodEnd)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    public function nextPeriod(Subscription $subscription, SubscriptionPlan $plan): array
    {
        $start = $subscription->current_period_end
            ? $subscription->current_period_end->copy()->startOfDay()
            : now()->startOfDay();
        if ($start->lt(now()->startOfDay())) {
            $start = now()->startOfDay();
        }
        $end = match ($plan->interval) {
            SubscriptionPlan::INTERVAL_WEEKLY => $start->copy()->addWeek(),
            SubscriptionPlan::INTERVAL_QUARTERLY => $start->copy()->addMonths(3),
            SubscriptionPlan::INTERVAL_SEMI_ANNUAL => $start->copy()->addMonths(6),
            SubscriptionPlan::INTERVAL_ANNUAL => $start->copy()->addYear(),
            default => $start->copy()->addMonth(),
        };

        return [$start, $end];
    }

    private function hasCompletedRenewalForPeriod(Subscription $subscription, string $periodEnd): bool
    {
        return Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('payment_method', 'pix_auto')
            ->where('gateway', LinaOpenxPixAutoService::GATEWAY_SLUG)
            ->where('is_renewal', true)
            ->where('status', 'completed')
            ->whereDate('period_end', $periodEnd)
            ->exists();
    }

    private function createPendingRenewalOrder(
        Subscription $subscription,
        SubscriptionPlan $plan,
        $periodStart,
        $periodEnd,
        string $consentId,
        ?Order $sourceOrder
    ): Order {
        $user = $subscription->user;
        $amount = (float) $plan->price;
        $cpf = $sourceOrder?->cpf
            ?? (preg_replace('/\D/', '', (string) ($user?->document ?? '')) ?: null);

        return Order::create([
            'tenant_id' => $subscription->tenant_id,
            'user_id' => $subscription->user_id,
            'product_id' => $subscription->product_id,
            'product_offer_id' => $sourceOrder?->product_offer_id,
            'subscription_plan_id' => $plan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => true,
            'amount' => $amount,
            'email' => $user?->email ?? $sourceOrder?->email,
            'cpf' => $cpf,
            'phone' => $sourceOrder?->phone,
            'customer_ip' => null,
            'coupon_code' => null,
            'status' => 'pending',
            'gateway' => LinaOpenxPixAutoService::GATEWAY_SLUG,
            'gateway_id' => null,
            'payment_method' => 'pix_auto',
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'linaopenx_pix_auto_consent_id' => $consentId,
                'subscription_id' => $subscription->id,
                'subscription_auto_renewal' => true,
            ],
        ]);
    }
}
