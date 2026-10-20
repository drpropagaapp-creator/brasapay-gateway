<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class XpagWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'BRL')));

        if ($currency !== '' && $currency !== 'BRL') {
            Log::info('XpagWebhook: ignored non-BRL event', [
                'currency' => $currency,
                'type' => $type,
                'status' => $status,
            ]);

            return response()->json(['received' => true, 'ignored' => true]);
        }

        if (in_array($status, ['med', 'med_closed'], true)) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $method = strtoupper(trim((string) ($payload['method'] ?? '')));
        if ($method !== '' && $method !== 'PIX') {
            Log::info('XpagWebhook: ignored non-PIX method', [
                'method' => $method,
                'type' => $type,
                'status' => $status,
            ]);

            return response()->json(['received' => true, 'ignored' => true]);
        }

        if ($type === 'cashout') {
            return $this->handleCashout($payload, $status);
        }

        if ($type === 'cashin') {
            $origin = strtolower(trim((string) ($payload['origin'] ?? '')));
            if ($origin === 'static') {
                return response()->json(['received' => true, 'ignored' => true]);
            }

            return $this->handleCashin($payload, $status);
        }

        return response()->json(['received' => true, 'ignored' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleCashin(array $payload, string $status): JsonResponse
    {
        $transactionId = trim((string) ($payload['transaction_id'] ?? $payload['request_number'] ?? ''));
        $externalId = trim((string) ($payload['external_id'] ?? ''));

        $order = $this->findOrder($transactionId, $externalId);
        if ($order === null) {
            Log::debug('XpagWebhook: order not found', [
                'gateway_id' => $transactionId,
                'external_id' => $externalId,
                'status' => $status,
            ]);

            return response()->json(['received' => true]);
        }

        if (in_array($status, ['confirmed', 'paid'], true)) {
            $id = $transactionId !== '' ? $transactionId : (string) $order->gateway_id;
            if ($id !== '' && ((string) $order->gateway !== 'xpag' || (string) $order->gateway_id !== $id)) {
                $order->update([
                    'gateway' => 'xpag',
                    'gateway_id' => $id,
                ]);
            }
            PaymentWebhookDispatcher::dispatch('xpag', $id, 'order.paid', 'paid', $payload);

            return response()->json(['received' => true]);
        }

        return response()->json(['received' => true, 'ignored' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleCashout(array $payload, string $status): JsonResponse
    {
        $transactionId = trim((string) ($payload['e2e'] ?? $payload['transaction_id'] ?? ''));
        $externalId = trim((string) ($payload['external_id'] ?? ''));

        $withdrawal = $this->findWithdrawal($transactionId, $externalId);
        if ($withdrawal === null) {
            Log::debug('XpagWebhook: withdrawal not found', [
                'payout_external_id' => $transactionId,
                'external_id' => $externalId,
                'status' => $status,
            ]);

            return response()->json(['received' => true]);
        }

        if (in_array($status, ['completed', 'paid', 'success'], true)
            && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());
        } elseif ($status === 'failed' && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            $reason = trim((string) ($payload['fail_reason'] ?? 'Payout XPag falhou (webhook failed).'));
            MerchantWithdrawalService::markFailed($withdrawal->fresh(), $reason);
        }

        return response()->json(['received' => true]);
    }

    private function findOrder(string $transactionId, string $externalId): ?Order
    {
        if ($transactionId !== '') {
            $byTx = Order::query()
                ->where('gateway', 'xpag')
                ->where('gateway_id', $transactionId)
                ->first();
            if ($byTx !== null) {
                return $byTx;
            }
        }

        if ($externalId !== '') {
            $byMeta = Order::query()
                ->where('metadata->xpag_external_id', $externalId)
                ->first();
            if ($byMeta !== null) {
                return $byMeta;
            }
        }

        if ($externalId !== '' && ctype_digit($externalId)) {
            return Order::query()
                ->where('id', (int) $externalId)
                ->where(function ($query) {
                    $query->where('gateway', 'xpag')->orWhereNull('gateway');
                })
                ->first();
        }

        return null;
    }

    private function findWithdrawal(string $transactionId, string $externalId): ?Withdrawal
    {
        if ($transactionId !== '') {
            $byTx = Withdrawal::query()
                ->where('payout_provider', 'xpag')
                ->where('payout_external_id', $transactionId)
                ->first();
            if ($byTx !== null) {
                return $byTx;
            }
        }

        if ($externalId !== '') {
            $byExt = Withdrawal::query()
                ->where('payout_provider', 'xpag')
                ->where('payout_external_id', $externalId)
                ->first();
            if ($byExt !== null) {
                return $byExt;
            }

            if (ctype_digit($externalId)) {
                return Withdrawal::query()
                    ->where('payout_provider', 'xpag')
                    ->where('id', (int) $externalId)
                    ->first();
            }
        }

        return null;
    }
}
