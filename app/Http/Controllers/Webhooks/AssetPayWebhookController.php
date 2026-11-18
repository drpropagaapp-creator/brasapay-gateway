<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\AssetPay\AssetPayDriver;
use App\Http\Controllers\Controller;
use App\Jobs\ReconcileAssetPayWithdrawalJob;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Withdrawal;
use App\Services\AssetPay\AssetPayMedService;
use App\Services\MerchantWithdrawalService;
use App\Support\GatewayInboundWebhookAuth;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks AssetPay (API api.assetpay.cash — plataforma Oceanfy).
 *
 * Evento no header X-Oceanfy-Event (ou X-Webhook-Event):
 *  - recebimento            → corpo = cobrança (status CONCLUIDA)
 *  - fila_saida_pagamentos  → { type: TRANSFER_QUEUE, data: transferência }
 *  - transferencia          → { type: TRANSFER, data: transferência }
 *  - estorno                → cobrança DEVOLVIDA, ou { type: TRANSFER_REFUND, data }
 *  - infracoes              → { type: INFRACTION, data: infração }
 *
 * Postbacks (postbackUrl) não são assinados e webhooks do painel são (X-Oceanfy-Signature).
 * Em todos os casos o estado é RECONFERIDO NA API antes de mudar pedido, saque ou MED.
 */
class AssetPayWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event = $this->eventName($request);
        $type = strtoupper(trim((string) $request->input('type', '')));

        if (in_array($type, ['TRANSFER', 'TRANSFER_QUEUE', 'TRANSFER_REFUND'], true)
            || in_array($event, ['transferencia', 'fila_saida_pagamentos'], true)) {
            return $this->handleTransfer($request, $type);
        }

        if ($type === 'INFRACTION' || $event === 'infracoes') {
            return $this->handleInfraction($request);
        }

        return $this->handleCharge($request, $event);
    }

    private function handleCharge(Request $request, string $event): JsonResponse
    {
        $chargeId = $this->str($request->input('id'));
        if ($chargeId === null) {
            return response()->json(['message' => 'id required'], 400);
        }

        $order = $this->findOrder($chargeId, $this->str($request->input('externalRef')));
        if ($order === null) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (! GatewayInboundWebhookAuth::verifyAssetPay($request, $order->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $status = strtoupper((string) $request->input('status', ''));
        if ($status !== 'CONCLUIDA' && $event !== 'recebimento') {
            // DEVOLVIDA (estorno) e demais estados não liberam nada; MED chega por `infracoes`.
            return response()->json(['received' => true, 'ignored' => true]);
        }

        // ProcessPaymentWebhook reconsulta GET /v1/cob/{id} antes de liberar o pedido.
        PaymentWebhookDispatcher::dispatch(
            'assetpay',
            (string) $order->gateway_id,
            'order.paid',
            'paid',
            array_merge($request->all(), ['webhook_source' => 'assetpay_webhook'])
        );

        return response()->json(['received' => true]);
    }

    private function handleTransfer(Request $request, string $type): JsonResponse
    {
        $txId = $this->str($request->input('data.txId'));
        $externalId = $this->str($request->input('data.externalId'));
        $withdrawal = $this->findWithdrawal($txId, $externalId);
        if ($withdrawal === null) {
            Log::warning('AssetPay webhook: saque não encontrado', ['tx_id' => $txId, 'external_id' => $externalId, 'type' => $type]);

            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if (! GatewayInboundWebhookAuth::verifyAssetPay($request, $withdrawal->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($type === 'TRANSFER_REFUND') {
            // O recebedor devolveu um PIX já pago: o valor volta ao saldo da AssetPay. Não mexe no saque.
            Log::warning('AssetPay webhook: transferência devolvida pelo recebedor', [
                'withdrawal_id' => $withdrawal->id,
                'status' => $request->input('data.status'),
            ]);

            return response()->json(['received' => true]);
        }

        if (! in_array($withdrawal->status, ['pending', 'processing'], true)) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $credentials = $this->credentials();
        $lookup = trim((string) ($withdrawal->payout_external_id ?: $txId ?: ''));
        $transfer = $credentials !== null && $lookup !== ''
            ? (new AssetPayDriver)->getTransfer($lookup, $credentials)
            : null;
        if ($transfer === null) {
            // API indisponível: a reconciliação tenta de novo sem confiar no corpo do webhook.
            ReconcileAssetPayWithdrawalJob::dispatch($withdrawal->id)->delay(now()->addSeconds(30));

            return response()->json(['received' => true, 'queued' => true]);
        }

        $apiStatus = AssetPayDriver::mapTransferStatus((string) ($transfer['status'] ?? ''));
        if ($apiStatus === 'paid') {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());
        } elseif (in_array($apiStatus, ['failed', 'cancelled'], true)) {
            $reason = trim((string) ($transfer['errorMessage'] ?? ''));
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout AssetPay '.($apiStatus === 'failed' ? 'falhou' : 'cancelado').($reason !== '' ? ': '.$reason : '.')
            );
        }

        return response()->json(['received' => true]);
    }

    private function handleInfraction(Request $request): JsonResponse
    {
        $infractionId = $this->str($request->input('data.id'));
        if ($infractionId === null) {
            return response()->json(['message' => 'data.id required'], 400);
        }

        $order = $this->findOrder(
            $this->str($request->input('data.transaction.id')),
            $this->str($request->input('data.transaction.externalRef'))
        );
        if ($order === null) {
            Log::info('AssetPay webhook: infração sem pedido conhecido', ['infraction_id' => $infractionId]);

            return response()->json(['received' => true, 'ignored' => true]);
        }

        if (! GatewayInboundWebhookAuth::verifyAssetPay($request, $order->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $credentials = $this->credentials();
        $infraction = $credentials !== null ? (new AssetPayDriver)->getInfraction($infractionId, $credentials) : null;
        if ($infraction === null) {
            // 5xx: a AssetPay reenvia; o estado só muda com a infração conferida na API.
            return response()->json(['message' => 'Infraction not confirmed'], 503);
        }

        $chargeId = trim((string) ($infraction['transaction']['id'] ?? ''));
        if ($chargeId !== '' && $chargeId !== (string) $order->gateway_id) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        try {
            app(AssetPayMedService::class)->syncInfraction($order, $infraction);
        } catch (\Throwable $e) {
            Log::warning('AssetPay webhook: falha ao processar MED', [
                'order_id' => $order->id,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return response()->json(['message' => 'MED processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function findOrder(?string $chargeId, ?string $externalRef): ?Order
    {
        if ($chargeId !== null) {
            $byTx = Order::query()->where('gateway', 'assetpay')->where('gateway_id', $chargeId)->first();
            if ($byTx !== null) {
                return $byTx;
            }
        }

        if ($externalRef !== null && ctype_digit($externalRef)) {
            $order = Order::query()->where('gateway', 'assetpay')->where('id', (int) $externalRef)->first();
            // Só aceita pelo externalRef se a cobrança bater (ou o pedido ainda não tiver id da AssetPay).
            if ($order !== null && ($chargeId === null || trim((string) $order->gateway_id) === '' || $order->gateway_id === $chargeId)) {
                return $order;
            }
        }

        return null;
    }

    private function findWithdrawal(?string $txId, ?string $externalId): ?Withdrawal
    {
        if ($txId !== null) {
            $byTx = Withdrawal::query()
                ->where('payout_provider', 'assetpay')
                ->where('payout_external_id', $txId)
                ->first();
            if ($byTx !== null) {
                return $byTx;
            }
        }

        if ($externalId !== null && str_starts_with($externalId, AssetPayDriver::TRANSFER_EXTERNAL_PREFIX)) {
            $id = substr($externalId, strlen(AssetPayDriver::TRANSFER_EXTERNAL_PREFIX));
            if (ctype_digit($id)) {
                return Withdrawal::query()
                    ->where('payout_provider', 'assetpay')
                    ->where('id', (int) $id)
                    ->first();
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        $cred = GatewayCredential::resolveForPayment(null, 'assetpay');
        if ($cred === null || ! $cred->is_connected) {
            return null;
        }
        $credentials = $cred->getDecryptedCredentials();

        return $credentials !== [] ? $credentials : null;
    }

    private function eventName(Request $request): string
    {
        foreach (['X-Oceanfy-Event', 'X-Webhook-Event'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return '';
    }

    private function str(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $trim = trim($value);

        return $trim !== '' ? $trim : null;
    }
}
