<?php

namespace App\Services\Versell;

use App\Events\OrderCancelled;
use App\Events\SubscriptionCancelled;
use App\Gateways\Versell\VersellCredentials;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cancelamento remoto, retentativa e reconciliação do Pix Automático Versell.
 */
class VersellPixAutoLifecycleService
{
    public const SKIP_REMOTE_CANCEL_TTL_SECONDS = 120;

    public const MAX_RETRIES = 3;

    public static function skipRemoteCancelKey(string $idRec): string
    {
        return 'versell_pix_auto_skip_remote_cancel:'.$idRec;
    }

    public static function isVersellSubscription(Subscription $subscription): bool
    {
        $idRec = trim((string) ($subscription->gateway_subscription_id ?? ''));
        if ($idRec === '' || ! str_starts_with($idRec, 'R')) {
            return false;
        }

        $gateway = Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('payment_method', 'pix_auto')
            ->whereIn('gateway', ['versell', 'efi'])
            ->orderByDesc('id')
            ->value('gateway');

        return $gateway === 'versell';
    }

    public function cancelRemote(Subscription $subscription): void
    {
        if (! self::isVersellSubscription($subscription)) {
            return;
        }

        $idRec = trim((string) $subscription->gateway_subscription_id);
        if (Cache::get(self::skipRemoteCancelKey($idRec))) {
            return;
        }

        $credentials = $this->credentialsForTenant($subscription->tenant_id);
        if ($credentials === null) {
            Log::info('VersellPixAutoLifecycleService: sem credencial para cancelar rec', [
                'subscription_id' => $subscription->id,
                'idRec' => $idRec,
            ]);

            return;
        }

        $pix = new VersellPixRecorrenteService($credentials);
        try {
            $pix->cancelRecurrence($idRec);
        } catch (\Throwable $e) {
            Log::warning('VersellPixAutoLifecycleService: falha ao cancelar rec', [
                'subscription_id' => $subscription->id,
                'idRec' => $idRec,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }

        $pending = Order::query()
            ->where('gateway', 'versell')
            ->where('payment_method', 'pix_auto')
            ->where('status', 'pending')
            ->where(function ($q) use ($idRec, $subscription) {
                $q->where('metadata->versell_pix_auto_id_rec', $idRec)
                    ->orWhere(function ($q2) use ($subscription) {
                        $q2->where('user_id', $subscription->user_id)
                            ->where('product_id', $subscription->product_id)
                            ->where('subscription_plan_id', $subscription->subscription_plan_id)
                            ->where('is_renewal', true);
                    });
            })
            ->get();

        foreach ($pending as $order) {
            $txid = trim((string) $order->gateway_id);
            if ($txid !== '') {
                try {
                    $pix->cancelCobranca($txid);
                } catch (\Throwable $e) {
                    Log::warning('VersellPixAutoLifecycleService: falha ao cancelar cobr', [
                        'order_id' => $order->id,
                        'txid' => $txid,
                        'error' => mb_substr($e->getMessage(), 0, 300),
                    ]);
                }
            }
            if ($order->status === 'pending') {
                $order->update(['status' => 'cancelled']);
                event(new OrderCancelled($order));
            }
        }
    }

    public function retryExpiredCobr(Order $order): bool
    {
        if ($order->gateway !== 'versell' || $order->payment_method !== 'pix_auto' || $order->status !== 'pending') {
            return false;
        }

        $txid = trim((string) $order->gateway_id);
        if ($txid === '') {
            return false;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $retries = (int) ($meta['versell_pix_auto_retry_count'] ?? 0);
        if ($retries >= self::MAX_RETRIES) {
            return false;
        }

        $credentials = $this->credentialsForTenant($order->tenant_id);
        if ($credentials === null) {
            return false;
        }

        $pix = new VersellPixRecorrenteService($credentials);
        try {
            $cobr = $pix->getCobranca($txid);
            $status = strtoupper(trim((string) ($cobr['status'] ?? '')));
            if (in_array($status, ['CONCLUIDA', 'LIQUIDADA', 'CANCELADA', 'ATIVA'], true)) {
                return false;
            }
            if ($status !== 'EXPIRADA') {
                return false;
            }
            $pix->requestRetentativa($txid);
            $meta['versell_pix_auto_retry_count'] = $retries + 1;
            $meta['versell_pix_auto_retry_at'] = now()->toIso8601String();
            $order->update(['metadata' => $meta]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('VersellPixAutoLifecycleService: falha na retentativa', [
                'order_id' => $order->id,
                'txid' => $txid,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return false;
        }
    }

    /**
     * @return array{rec: int, paid: int, retries: int, cancelled: int, errors: int}
     */
    public function reconcile(int $limit = 80): array
    {
        $stats = ['rec' => 0, 'paid' => 0, 'retries' => 0, 'cancelled' => 0, 'errors' => 0];

        $subscriptions = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->whereNotNull('gateway_subscription_id')
            ->where('gateway_subscription_id', 'like', 'R%')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($subscriptions as $subscription) {
            if (! self::isVersellSubscription($subscription)) {
                continue;
            }
            try {
                $this->reconcileSubscription($subscription);
                $stats['rec']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('VersellPixAutoLifecycleService: reconcile rec falhou', [
                    'subscription_id' => $subscription->id,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        $orders = Order::query()
            ->where('gateway', 'versell')
            ->where('payment_method', 'pix_auto')
            ->where('status', 'pending')
            ->whereNotNull('gateway_id')
            ->where('gateway_id', '!=', '')
            ->where('created_at', '>=', now()->subDays(45))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            try {
                $outcome = $this->reconcilePendingOrder($order);
                if ($outcome === 'paid') {
                    $stats['paid']++;
                } elseif ($outcome === 'retry') {
                    $stats['retries']++;
                } elseif ($outcome === 'cancelled') {
                    $stats['cancelled']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('VersellPixAutoLifecycleService: reconcile cobr falhou', [
                    'order_id' => $order->id,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        return $stats;
    }

    private function reconcileSubscription(Subscription $subscription): void
    {
        $idRec = trim((string) $subscription->gateway_subscription_id);
        $credentials = $this->credentialsForTenant($subscription->tenant_id);
        if ($credentials === null) {
            return;
        }

        $pix = new VersellPixRecorrenteService($credentials);
        $rec = $pix->getRecurrence($idRec);
        $status = strtoupper(trim((string) ($rec['status'] ?? '')));

        if ($status === 'APROVADA') {
            app(VersellPixAutoRenewalService::class)->ensureNextCobr($subscription);

            return;
        }

        if (! in_array($status, ['CANCELADA', 'REJEITADA', 'EXPIRADA'], true)) {
            return;
        }

        Cache::put(self::skipRemoteCancelKey($idRec), 1, self::SKIP_REMOTE_CANCEL_TTL_SECONDS);
        if (in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true)) {
            $subscription->update(['status' => Subscription::STATUS_CANCELLED]);
            event(new SubscriptionCancelled($subscription->fresh()));
        }
    }

    private function reconcilePendingOrder(Order $order): ?string
    {
        $txid = trim((string) $order->gateway_id);
        $credentials = $this->credentialsForTenant($order->tenant_id);
        if ($credentials === null || $txid === '') {
            return null;
        }

        $pix = new VersellPixRecorrenteService($credentials);
        $cobr = $pix->getCobranca($txid);
        $status = strtoupper(trim((string) ($cobr['status'] ?? '')));

        if (in_array($status, ['CONCLUIDA', 'LIQUIDADA'], true) || $this->tentativaPaga($cobr)) {
            ProcessPaymentWebhook::dispatchSync('versell', $txid, 'order.paid', 'paid', [
                'source' => 'versell_pix_auto_reconcile',
            ]);

            return 'paid';
        }

        if ($status === 'CANCELADA') {
            $order->update(['status' => 'cancelled']);
            event(new OrderCancelled($order));

            return 'cancelled';
        }

        if ($status === 'EXPIRADA' && $this->retryExpiredCobr($order->fresh())) {
            return 'retry';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $cobr
     */
    private function tentativaPaga(array $cobr): bool
    {
        $tentativas = $cobr['tentativas'] ?? null;
        if (! is_array($tentativas)) {
            return false;
        }
        foreach ($tentativas as $t) {
            if (! is_array($t)) {
                continue;
            }
            $tStatus = strtoupper(trim((string) ($t['status'] ?? '')));
            if (in_array($tStatus, ['PAGA', 'PAID', 'CONCLUIDA', 'LIQUIDADA'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credentialsForTenant(?int $tenantId): ?array
    {
        $credential = GatewayCredential::resolveForPayment($tenantId, 'versell');
        if ($credential === null) {
            return null;
        }
        $credentials = $credential->getDecryptedCredentials();
        if (! VersellCredentials::isCashInReady($credentials)) {
            return null;
        }

        return $credentials;
    }
}
