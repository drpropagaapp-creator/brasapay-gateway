<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Withdrawal;
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
            // Webhook sem assinatura: não religa pedido nem sobrescreve o
            // gateway_id com dado do payload. Se o pedido ainda não tem a
            // transação persistida (corrida criação × webhook), o vínculo é
            // confirmado na API da XPag (consulta por external_id = id do
            // pedido) e usa o transaction_id devolvido pela API.
            $id = trim((string) $order->gateway_id);
            if ($id === '' || (string) $order->gateway !== 'xpag') {
                $id = $this->verifiedTransactionIdForOrder($order);
                if ($id === null) {
                    Log::warning('XpagWebhook: transação do pedido não confirmada na API; evento ignorado', [
                        'order_id' => $order->id,
                        'payload_transaction_id' => $transactionId,
                    ]);

                    return response()->json(['received' => true, 'ignored' => true]);
                }
                $order->update(['gateway' => 'xpag', 'gateway_id' => $id]);
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

        // Webhook sem assinatura: nunca confia no status do payload para
        // marcar pago/falho (um "failed" forjado devolveria saldo com o PIX
        // já enviado). O evento só antecipa a reconciliação, que consulta a
        // API da XPag e decide pelo status real.
        if (in_array($status, ['completed', 'paid', 'success', 'failed'], true)
            && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            \App\Jobs\ReconcileXpagWithdrawalJob::dispatch($withdrawal->id);
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
            // gateway nulo cobre a corrida criação × webhook; o vínculo desses
            // pedidos só acontece após confirmação na API (handleCashin).
            return Order::query()
                ->where('id', (int) $externalId)
                ->where(function ($query) {
                    $query->where('gateway', 'xpag')->orWhereNull('gateway');
                })
                ->first();
        }

        return null;
    }

    /**
     * Confirma na API da XPag qual transação pertence ao pedido (consulta por
     * external_id = id do pedido) e devolve o transaction_id REAL informado
     * pela API — nunca o do payload do webhook, que não é autenticado.
     */
    private function verifiedTransactionIdForOrder(Order $order): ?string
    {
        $credentials = \App\Support\GatewayPaymentCredentials::resolve($order->tenant_id, 'xpag', $order);
        if ($credentials === null) {
            return null;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $identifier = trim((string) ($meta['xpag_external_id'] ?? ''));
        if ($identifier === '') {
            $identifier = (string) $order->id;
        }

        try {
            $row = (new \App\Gateways\Xpag\XpagDriver)->consultTransaction($credentials, $identifier);
        } catch (\Throwable $e) {
            Log::warning('XpagWebhook: falha ao consultar transação do pedido na API', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_array($row)) {
            return null;
        }

        $apiExternalId = trim((string) ($row['external_id'] ?? ''));
        if ($apiExternalId !== '' && $apiExternalId !== $identifier) {
            return null;
        }

        $id = trim((string) ($row['transaction_id'] ?? $row['request_number'] ?? $row['id'] ?? ''));

        return $id !== '' ? $id : null;
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
