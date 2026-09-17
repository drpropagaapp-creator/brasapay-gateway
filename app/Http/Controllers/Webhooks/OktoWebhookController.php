<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\Okto\OktoDriver;
use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\PlatformOrderAdminService;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OktoWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (! $this->verifyRequest($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        $invoice = is_array($payload['Invoice'] ?? null) ? $payload['Invoice'] : null;
        $transfer = is_array($payload['Transfer'] ?? null) ? $payload['Transfer'] : null;

        if ($transfer !== null) {
            return $this->handleTransfer($transfer, $payload);
        }

        if ($invoice !== null) {
            return $this->handleInvoice($invoice, $payload);
        }

        return response()->json(['received' => true, 'ignored' => true]);
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<string, mixed>  $payload
     */
    private function handleInvoice(array $invoice, array $payload): JsonResponse
    {
        $transactionId = trim((string) ($invoice['id'] ?? ''));
        $externalId = trim((string) ($invoice['externalId'] ?? ''));
        $status = strtolower(trim((string) ($invoice['status'] ?? '')));

        $order = $this->findOrder($transactionId, $externalId);
        if ($order === null) {
            Log::debug('OktoWebhook: order not found', [
                'gateway_id' => $transactionId,
                'external_id' => $externalId,
                'status' => $status,
            ]);

            return response()->json(['received' => true]);
        }

        if ($status === 'paid') {
            $id = $transactionId !== '' ? $transactionId : (string) $order->gateway_id;
            PaymentWebhookDispatcher::dispatch('okto', $id, 'order.paid', 'paid', $payload);

            return response()->json(['received' => true]);
        }

        if ($status === 'reversed') {
            PlatformOrderAdminService::applyGatewayRefund($order);

            return response()->json(['received' => true]);
        }

        return response()->json(['received' => true, 'ignored' => true]);
    }

    /**
     * @param  array<string, mixed>  $transfer
     * @param  array<string, mixed>  $payload
     */
    private function handleTransfer(array $transfer, array $payload): JsonResponse
    {
        $transactionId = trim((string) ($transfer['id'] ?? ''));
        $externalId = trim((string) ($transfer['externalId'] ?? ''));
        $status = strtolower(trim((string) ($transfer['status'] ?? '')));

        $withdrawal = $this->findWithdrawal($transactionId, $externalId);
        if ($withdrawal === null) {
            Log::debug('OktoWebhook: withdrawal not found', [
                'payout_external_id' => $transactionId,
                'external_id' => $externalId,
                'status' => $status,
            ]);

            return response()->json(['received' => true]);
        }

        if ($status === 'success' && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());
        } elseif ($status === 'failed' && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout Okto falhou (webhook failed).'
            );
        }

        return response()->json(['received' => true]);
    }

    private function findOrder(string $transactionId, string $externalId): ?Order
    {
        if ($transactionId !== '') {
            $byTx = Order::query()
                ->where('gateway', 'okto')
                ->where('gateway_id', $transactionId)
                ->first();
            if ($byTx !== null) {
                return $byTx;
            }
        }

        if ($externalId !== '' && ctype_digit($externalId)) {
            return Order::query()
                ->where('gateway', 'okto')
                ->where('id', (int) $externalId)
                ->first();
        }

        return null;
    }

    private function findWithdrawal(string $transactionId, string $externalId): ?Withdrawal
    {
        if ($transactionId !== '') {
            $byTx = Withdrawal::query()
                ->where('payout_provider', 'okto')
                ->where('payout_external_id', $transactionId)
                ->first();
            if ($byTx !== null) {
                return $byTx;
            }
        }

        if ($externalId !== '') {
            $byExt = Withdrawal::query()
                ->where('payout_provider', 'okto')
                ->where('payout_external_id', $externalId)
                ->first();
            if ($byExt !== null) {
                return $byExt;
            }

            if (ctype_digit($externalId)) {
                return Withdrawal::query()
                    ->where('payout_provider', 'okto')
                    ->where('id', (int) $externalId)
                    ->first();
            }
        }

        return null;
    }

    private function verifyRequest(Request $request): bool
    {
        $cred = GatewayCredential::resolveForPayment(null, 'okto');
        $credentials = $cred !== null ? $cred->getDecryptedCredentials() : [];
        $pem = trim((string) ($credentials['rsa_public_key'] ?? ''));
        $expectedToken = trim((string) ($credentials['notification_token'] ?? $credentials['access_token'] ?? ''));

        $headerToken = $request->header('Gaming-Operator-Token');
        $tokenOk = is_string($headerToken) && $expectedToken !== '' && hash_equals($expectedToken, trim($headerToken));

        $signature = $request->header('X-Payload-Signature');
        $sigOk = false;
        if (is_string($signature) && $pem !== '') {
            $sigOk = OktoDriver::verifyPayloadSignature($request->getContent(), $signature, $pem);
        }

        if ($pem !== '') {
            if (! $sigOk) {
                Log::warning('OktoWebhook: assinatura RSA inválida');

                return false;
            }

            return true;
        }

        if ($expectedToken !== '') {
            if (! $tokenOk) {
                Log::warning('OktoWebhook: Gaming-Operator-Token inválido');

                return false;
            }

            return true;
        }

        Log::warning('OktoWebhook: sem chave RSA nem notification token configurados');

        return false;
    }
}
