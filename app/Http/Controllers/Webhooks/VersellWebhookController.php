<?php

namespace App\Http\Controllers\Webhooks;

use App\Events\OrderRejected;
use App\Events\SubscriptionCancelled;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\Versell\VersellPixAutoLifecycleService;
use App\Services\Versell\VersellPixAutoRenewalService;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks Versell Cash In + Pix Automático.
 * Cash In: PUT /webhook/{chave} → POST …/versell/pix
 * Pix Auto: PUT /webhookrec|/webhookcobr → POST …/versell/pix-automatico/rec|/cobr
 */
class VersellWebhookController extends Controller
{
    public function pix(Request $request): JsonResponse
    {
        $pixItems = $request->input('pix');
        if (! is_array($pixItems) || $pixItems === []) {
            Log::info('VersellWebhook: payload sem pix[]', [
                'gateway' => 'versell',
                'keys' => array_keys($request->all()),
            ]);

            return response()->json(['message' => 'pix array required'], 400);
        }

        $dispatched = 0;
        foreach ($pixItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $txid = trim((string) ($item['txid'] ?? ''));
            if ($txid === '') {
                continue;
            }

            $order = Order::query()
                ->where('gateway', 'versell')
                ->where('gateway_id', $txid)
                ->first();

            if ($order === null) {
                Log::info('VersellWebhook: order not found', [
                    'gateway' => 'versell',
                    'txid' => $txid,
                ]);
                continue;
            }

            $endToEndId = trim((string) ($item['endToEndId'] ?? $item['end_to_end_id'] ?? ''));
            if ($endToEndId !== '') {
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $meta['versell_end_to_end_id'] = $endToEndId;
                $meta['versell_txid'] = $txid;
                $order->update(['metadata' => $meta]);
            }

            $hasRefund = $this->itemHasDevolucao($item);
            if ($hasRefund) {
                PaymentWebhookDispatcher::dispatch(
                    'versell',
                    $txid,
                    'order.refunded',
                    'refunded',
                    $request->all()
                );
            } else {
                PaymentWebhookDispatcher::dispatch(
                    'versell',
                    $txid,
                    'order.paid',
                    'paid',
                    $request->all()
                );
            }
            $dispatched++;
        }

        return response()->json([
            'received' => true,
            'dispatched' => $dispatched,
        ]);
    }

    /**
     * POST …/pix-automatico/rec — mudanças de status da recorrência.
     */
    public function pixAutoRec(Request $request): JsonResponse
    {
        $recs = $request->input('recs');
        if (! is_array($recs)) {
            $recs = [];
        }

        foreach ($recs as $rec) {
            if (! is_array($rec)) {
                continue;
            }
            $idRec = trim((string) ($rec['idRec'] ?? ''));
            $status = strtoupper(trim((string) ($rec['status'] ?? '')));
            if ($idRec === '') {
                continue;
            }

            $order = Order::query()
                ->where('gateway', 'versell')
                ->where('metadata->versell_pix_auto_id_rec', $idRec)
                ->orderByDesc('id')
                ->first();

            $subscription = Subscription::query()
                ->where('gateway_subscription_id', $idRec)
                ->orderByDesc('id')
                ->first();

            if ($order === null && $subscription !== null) {
                $order = Order::query()
                    ->where('gateway', 'versell')
                    ->where('user_id', $subscription->user_id)
                    ->where('product_id', $subscription->product_id)
                    ->where('payment_method', 'pix_auto')
                    ->orderByDesc('id')
                    ->first();
            }

            if ($order === null) {
                Log::info('VersellWebhook pixAutoRec: order not found', [
                    'idRec' => $idRec,
                    'status' => $status,
                ]);
            } else {
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $meta['versell_pix_auto_rec_status'] = $status !== '' ? $status : null;
                $meta['versell_pix_auto_rec_at'] = now()->toIso8601String();
                $order->update(['metadata' => array_filter($meta, fn ($v) => $v !== null && $v !== '')]);
            }

            $this->applyRecStatus($idRec, $status, $order, $subscription);
        }

        return response()->json(['received' => true]);
    }

    /**
     * POST …/pix-automatico/cobr — cobranças recorrentes / tentativas.
     */
    public function pixAutoCobr(Request $request): JsonResponse
    {
        $cobsr = $request->input('cobsr');
        if (! is_array($cobsr) || $cobsr === []) {
            $txid = trim((string) ($request->input('txid') ?? $request->input('cobr.txid') ?? ''));
            if ($txid !== '') {
                $cobsr = [[
                    'txid' => $txid,
                    'status' => $request->input('status') ?? $request->input('cobr.status'),
                    'tentativas' => $request->input('tentativas') ?? [],
                ]];
            } else {
                Log::info('VersellWebhook pixAutoCobr: payload sem cobsr[]', [
                    'keys' => array_keys($request->all()),
                ]);

                return response()->json(['received' => true]);
            }
        }

        $dispatched = 0;
        foreach ($cobsr as $item) {
            if (! is_array($item)) {
                continue;
            }

            $txid = trim((string) ($item['txid'] ?? ''));
            if ($txid === '') {
                continue;
            }

            $order = Order::query()
                ->where('gateway', 'versell')
                ->where('gateway_id', $txid)
                ->first();

            if ($order === null) {
                $idRec = trim((string) ($item['idRec'] ?? ''));
                if ($idRec !== '') {
                    $subscription = Subscription::query()
                        ->where('gateway_subscription_id', $idRec)
                        ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
                        ->orderByDesc('id')
                        ->first();
                    if ($subscription !== null) {
                        $order = app(VersellPixAutoRenewalService::class)
                            ->attachExistingCobr($subscription, $txid, $idRec);
                    }
                }
            }

            if ($order === null) {
                Log::info('VersellWebhook pixAutoCobr: order not found', ['txid' => $txid]);

                continue;
            }

            if (! $this->cobrLooksPaid($item)) {
                if ($this->cobrLooksExpired($item)) {
                    app(VersellPixAutoLifecycleService::class)->retryExpiredCobr($order);
                }

                continue;
            }

            $endToEndId = $this->endToEndFromCobr($item);
            if ($endToEndId !== '') {
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $meta['versell_end_to_end_id'] = $endToEndId;
                $order->update(['metadata' => $meta]);
            }

            PaymentWebhookDispatcher::dispatch(
                'versell',
                $txid,
                'order.paid',
                'paid',
                $request->all()
            );
            $dispatched++;
        }

        return response()->json([
            'received' => true,
            'dispatched' => $dispatched,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function cobrLooksPaid(array $item): bool
    {
        $status = strtoupper(trim((string) ($item['status'] ?? '')));
        if (in_array($status, ['CONCLUIDA', 'LIQUIDADA', 'PAID', 'PAGO'], true)) {
            return true;
        }

        $tentativas = $item['tentativas'] ?? null;
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
     * @param  array<string, mixed>  $item
     */
    private function cobrLooksExpired(array $item): bool
    {
        return strtoupper(trim((string) ($item['status'] ?? ''))) === 'EXPIRADA';
    }

    private function applyRecStatus(string $idRec, string $status, ?Order $order, ?Subscription $subscription): void
    {
        if ($status === 'APROVADA') {
            if ($subscription === null && $order !== null && $order->status === 'completed') {
                $subscription = Subscription::query()
                    ->where('user_id', $order->user_id)
                    ->where('product_id', $order->product_id)
                    ->where('subscription_plan_id', $order->subscription_plan_id)
                    ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
                    ->orderByDesc('id')
                    ->first();
            }
            if ($subscription !== null) {
                app(VersellPixAutoRenewalService::class)->ensureNextCobr($subscription, $order);
            }

            return;
        }

        if (in_array($status, ['REJEITADA', 'EXPIRADA'], true) && $order !== null && $order->status === 'pending' && ! $order->is_renewal) {
            $order->update(['status' => 'rejected']);
            event(new OrderRejected($order));

            return;
        }

        if ($status !== 'CANCELADA') {
            return;
        }

        $toCancel = $subscription;
        if ($toCancel === null) {
            $toCancel = Subscription::query()
                ->where('gateway_subscription_id', $idRec)
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
                ->orderByDesc('id')
                ->first();
        }
        Cache::put(
            VersellPixAutoLifecycleService::skipRemoteCancelKey($idRec),
            1,
            VersellPixAutoLifecycleService::SKIP_REMOTE_CANCEL_TTL_SECONDS
        );
        if ($toCancel !== null && in_array($toCancel->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true)) {
            $toCancel->update(['status' => Subscription::STATUS_CANCELLED]);
            event(new SubscriptionCancelled($toCancel->fresh()));
        }

        Order::query()
            ->where('gateway', 'versell')
            ->where('payment_method', 'pix_auto')
            ->where('status', 'pending')
            ->where('metadata->versell_pix_auto_id_rec', $idRec)
            ->update(['status' => 'cancelled']);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function endToEndFromCobr(array $item): string
    {
        $tentativas = $item['tentativas'] ?? null;
        if (! is_array($tentativas)) {
            return '';
        }
        foreach (array_reverse($tentativas) as $t) {
            if (! is_array($t)) {
                continue;
            }
            $e2e = trim((string) ($t['endToEndId'] ?? $t['end_to_end_id'] ?? ''));
            if ($e2e !== '') {
                return $e2e;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemHasDevolucao(array $item): bool
    {
        $devolucoes = $item['devolucoes'] ?? null;
        if (! is_array($devolucoes) || $devolucoes === []) {
            return false;
        }

        foreach ($devolucoes as $dev) {
            if (! is_array($dev)) {
                continue;
            }
            $status = strtoupper(trim((string) ($dev['status'] ?? '')));
            if (in_array($status, ['DEVOLVIDO', 'REFUNDED', 'CONCLUIDA', 'COMPLETED'], true)) {
                return true;
            }
            if ($status !== '' || trim((string) ($dev['id'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
