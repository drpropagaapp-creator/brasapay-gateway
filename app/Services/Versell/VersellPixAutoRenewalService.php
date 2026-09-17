<?php

namespace App\Services\Versell;

use App\Events\OrderPending;
use App\Gateways\Versell\VersellCredentials;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\FakeConsumerData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Garante cobr Versell com pedido local + txid próprio (ciclo seguinte do Pix Automático).
 */
class VersellPixAutoRenewalService
{
    /**
     * Cria (ou reutiliza) o pedido de renovação e agenda PUT /cobr/{txid} se a rec estiver APROVADA.
     */
    public function ensureNextCobr(Subscription $subscription, ?Order $sourceOrder = null): ?Order
    {
        $idRec = trim((string) ($subscription->gateway_subscription_id ?? ''));
        if ($idRec === '') {
            return null;
        }

        $plan = $subscription->subscriptionPlan;
        if ($plan === null || $plan->isLifetime()) {
            return null;
        }

        [$periodStart, $periodEnd] = $this->nextPeriod($subscription, $plan);
        $periodEndStr = $periodEnd->toDateString();
        $lockKey = 'versell_pix_auto_cobr:'.$subscription->id.':'.$periodEndStr;
        if (! Cache::add($lockKey, 1, now()->addHours(12))) {
            return $this->findPendingRenewal($subscription, $periodEndStr);
        }

        try {
            if ($this->hasCompletedRenewalForPeriod($subscription, $periodEndStr)) {
                return null;
            }

            $existing = $this->findPendingRenewal($subscription, $periodEndStr);
            if ($existing !== null && trim((string) $existing->gateway_id) !== '') {
                return $existing;
            }

            $credentials = $this->credentialsFor($subscription);
            if ($credentials === null) {
                Cache::forget($lockKey);

                return $existing;
            }

            $pix = new VersellPixRecorrenteService($credentials);
            if (! $pix->isRecorrenciaAprovada($idRec)) {
                Cache::forget($lockKey);
                Log::info('VersellPixAutoRenewalService: rec ainda não APROVADA', [
                    'subscription_id' => $subscription->id,
                    'idRec' => $idRec,
                ]);

                return $existing;
            }

            $created = false;
            $order = $existing;
            if ($order === null) {
                $order = $this->createPendingRenewalOrder($subscription, $plan, $periodStart, $periodEnd, $idRec, $sourceOrder);
                $created = true;
                event(new OrderPending($order));
            }

            $txid = trim((string) $order->gateway_id);
            if ($txid === '') {
                $txid = self::makeTxid((int) $order->id);
            }

            $devedor = $this->devedorFor($subscription, $sourceOrder ?? $order);
            $pix->createCobrancaRecorrente(
                $idRec,
                (float) $order->amount,
                $periodStart->toDateString(),
                $txid,
                $devedor,
                'Renovação assinatura #'.$subscription->id
            );

            $meta = is_array($order->metadata) ? $order->metadata : [];
            $meta['checkout_payment_method'] = 'pix_auto';
            $meta['versell_pix_auto_id_rec'] = $idRec;
            $meta['subscription_id'] = $subscription->id;
            $order->update([
                'gateway' => 'versell',
                'gateway_id' => $txid,
                'metadata' => $meta,
            ]);

            return $order->fresh();
        } catch (\Throwable $e) {
            Cache::forget($lockKey);
            Log::warning('VersellPixAutoRenewalService: falha ao agendar cobr', [
                'subscription_id' => $subscription->id,
                'idRec' => $idRec,
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

    /**
     * Associa uma cobr já existente na Versell a um pedido local (fallback do webhook).
     */
    public function attachExistingCobr(Subscription $subscription, string $txid, string $idRec): ?Order
    {
        $txid = trim($txid);
        $idRec = trim($idRec);
        if ($txid === '' || $idRec === '') {
            return null;
        }

        $byTxid = Order::query()
            ->where('gateway', 'versell')
            ->where('gateway_id', $txid)
            ->first();
        if ($byTxid !== null) {
            return $byTxid;
        }

        $plan = $subscription->subscriptionPlan;
        if ($plan === null || $plan->isLifetime()) {
            return null;
        }

        [$periodStart, $periodEnd] = $this->nextPeriod($subscription, $plan);
        $pending = $this->findPendingRenewal($subscription, $periodEnd->toDateString());
        if ($pending !== null) {
            $meta = is_array($pending->metadata) ? $pending->metadata : [];
            $meta['versell_pix_auto_id_rec'] = $idRec;
            $meta['subscription_id'] = $subscription->id;
            $pending->update([
                'gateway' => 'versell',
                'gateway_id' => $txid,
                'metadata' => $meta,
            ]);

            return $pending->fresh();
        }

        $order = $this->createPendingRenewalOrder($subscription, $plan, $periodStart, $periodEnd, $idRec, null);
        $order->update([
            'gateway_id' => $txid,
        ]);
        event(new OrderPending($order));

        return $order->fresh();
    }

    public function findPendingRenewal(Subscription $subscription, string $periodEnd): ?Order
    {
        return Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('payment_method', 'pix_auto')
            ->where('gateway', 'versell')
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

    public static function makeTxid(int $orderId): string
    {
        $base = 'pixautorenov'.$orderId;
        $txid = $base.Str::lower(Str::random(max(26 - strlen($base), 10)));
        $txid = preg_replace('/[^a-zA-Z0-9]/', '', $txid) ?: ('pixautorenov'.bin2hex(random_bytes(8)));
        if (strlen($txid) < 26) {
            $txid .= bin2hex(random_bytes(8));
        }

        return substr($txid, 0, 35);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credentialsFor(Subscription $subscription): ?array
    {
        $credential = GatewayCredential::resolveForPayment($subscription->tenant_id, 'versell');
        if ($credential === null) {
            return null;
        }
        $credentials = $credential->getDecryptedCredentials();
        if (! VersellCredentials::isCashInReady($credentials)) {
            return null;
        }

        return $credentials;
    }

    private function hasCompletedRenewalForPeriod(Subscription $subscription, string $periodEnd): bool
    {
        return Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('payment_method', 'pix_auto')
            ->where('gateway', 'versell')
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
        string $idRec,
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
            'gateway' => 'versell',
            'gateway_id' => null,
            'payment_method' => 'pix_auto',
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'versell_pix_auto_id_rec' => $idRec,
                'subscription_id' => $subscription->id,
                'subscription_auto_renewal' => true,
            ],
        ]);
    }

    /**
     * @return array{name: string, document: string, email: string}
     */
    private function devedorFor(Subscription $subscription, Order $order): array
    {
        $user = $subscription->user;
        $document = preg_replace('/\D/', '', (string) ($order->cpf ?? $user?->document ?? '')) ?: '';
        if (strlen($document) < 11) {
            $document = FakeConsumerData::getForGateway((int) $order->id)['document'] ?? '00000000000';
        }

        return [
            'name' => $user?->name ?? $order->email ?? 'Cliente',
            'document' => $document,
            'email' => $user?->email ?? (string) $order->email,
        ];
    }
}
