<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\Xflow\XflowDriver;
use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Withdrawal;
use App\Models\MedDispute;
use App\Services\MerchantWithdrawalService;
use App\Services\PlatformOrderAdminService;
use App\Services\Xflow\XflowMedService;
use App\Support\GatewayInboundWebhookAuth;
use App\Support\GatewayPaymentCredentials;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class XflowWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $event = $this->eventName($request, $payload);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if (str_starts_with($event, 'dispute.')) {
            return $this->handleDispute($request, $event, $data, $payload);
        }

        $transactionId = trim((string) ($data['id'] ?? $payload['id'] ?? ''));

        if ($transactionId === '') {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        if (str_starts_with($event, 'withdrawal.')) {
            return $this->handlePayout($request, $event, $transactionId, $data, $payload);
        }

        $order = Order::query()
            ->where('gateway', 'xflow')
            ->where('gateway_id', $transactionId)
            ->first();

        if ($order === null) {
            Log::debug('XflowWebhook: order not found', ['gateway_id' => $transactionId, 'event' => $event]);

            return response()->json(['received' => true]);
        }

        if (! $this->verifySignature($request, $order->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($this->shouldIgnoreSandboxLivemode($order->tenant_id, $data)) {
            Log::info('XflowWebhook: ignored livemode=false on live credentials', [
                'order_id' => $order->id,
                'gateway_id' => $transactionId,
                'event' => $event,
            ]);

            return response()->json(['received' => true, 'ignored' => true]);
        }

        if ($event === 'transaction.refund_failed') {
            return $this->handleRefundFailed($order, $data);
        }

        $mapped = $this->mapEvent($event, $data['status'] ?? null);
        if ($mapped === null) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        PaymentWebhookDispatcher::dispatch('xflow', $transactionId, $mapped['event'], $mapped['status'], $payload);

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleRefundFailed(Order $order, array $data): JsonResponse
    {
        $failureReason = $this->refundFailureReason($data);
        $patch = [
            'xflow_refund_status' => 'failed',
            'xflow_refund_pending' => false,
            'xflow_refund_failure_reason' => $failureReason,
        ];

        $remote = $this->remoteRefundStatus($order);
        if ($remote === 'completed') {
            Log::info('XflowWebhook: refund_failed ignorado; cobrança já está estornada', [
                'order_id' => $order->id,
                'gateway_id' => $order->gateway_id,
            ]);
            PlatformOrderAdminService::applyGatewayRefund($order);

            return response()->json(['received' => true]);
        }

        if ($remote === 'pending') {
            Log::info('XflowWebhook: refund_failed com estorno ainda pending na API', [
                'order_id' => $order->id,
                'gateway_id' => $order->gateway_id,
            ]);

            return response()->json(['received' => true, 'ignored' => true]);
        }

        Log::warning('XflowWebhook: estorno recusado pelo PSP', [
            'order_id' => $order->id,
            'gateway_id' => $order->gateway_id,
            'status' => $order->status,
            'reason' => $failureReason,
        ]);

        PlatformOrderAdminService::abortPendingGatewayRefund($order, $patch);

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function refundFailureReason(array $data): ?string
    {
        $refund = is_array($data['refund'] ?? null) ? $data['refund'] : [];
        $reason = $refund['failureReason'] ?? $data['failureReason'] ?? $data['failure_reason'] ?? null;

        if (! is_string($reason) || trim($reason) === '') {
            return null;
        }

        return mb_substr(trim($reason), 0, 280);
    }

    private function remoteRefundStatus(Order $order): ?string
    {
        $chargeId = is_string($order->gateway_id) ? trim($order->gateway_id) : '';
        if ($chargeId === '') {
            return null;
        }

        $credentials = GatewayPaymentCredentials::resolve((int) $order->tenant_id, 'xflow', $order);
        if ($credentials === null) {
            return null;
        }

        return (new XflowDriver)->getRefundStatus($chargeId, $credentials);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private function handlePayout(Request $request, string $event, string $transactionId, array $data, array $payload): JsonResponse
    {
        $withdrawal = Withdrawal::query()
            ->where('payout_provider', 'xflow')
            ->where('payout_external_id', $transactionId)
            ->first();

        if ($withdrawal === null) {
            Log::debug('XflowWebhook: withdrawal not found', [
                'payout_external_id' => $transactionId,
                'event' => $event,
            ]);

            return response()->json(['received' => true]);
        }

        if (! $this->verifySignature($request, $withdrawal->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($this->shouldIgnoreSandboxLivemode($withdrawal->tenant_id, $data)) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        if ($event === 'withdrawal.processing') {
            $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
            $meta['webhook_last_event'] = $event;
            $meta['webhook_last_at'] = now()->toIso8601String();
            $withdrawal->update(['payout_meta' => $meta]);

            return response()->json(['received' => true]);
        }

        if ($event === 'withdrawal.completed' && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());

            return response()->json(['received' => true]);
        }

        if ($event === 'withdrawal.failed' && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout Xflow falhou (webhook withdrawal.failed).'
            );

            return response()->json(['received' => true]);
        }

        return response()->json(['received' => true, 'ignored' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private function handleDispute(Request $request, string $event, array $data, array $payload): JsonResponse
    {
        $order = $this->findXflowOrderForDispute($data, $payload);
        if ($order === null) {
            Log::debug('XflowWebhook: order not found for dispute', [
                'event' => $event,
                'charge_id' => XflowMedService::chargeId($data),
                'dispute_id' => $data['id'] ?? null,
            ]);

            return response()->json(['received' => true, 'ignored' => true]);
        }

        if (! $this->verifySignature($request, $order->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($this->shouldIgnoreSandboxLivemode($order->tenant_id, $data)) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        try {
            app(XflowMedService::class)->handleWebhookEvent($event, $payload, $order);
        } catch (\Throwable $e) {
            Log::warning('XflowWebhook: falha ao processar MED', [
                'event' => $event,
                'order_id' => $order->id,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return response()->json(['message' => 'MED processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private function findXflowOrderForDispute(array $data, array $payload): ?Order
    {
        $chargeId = XflowMedService::chargeId($data);
        if ($chargeId === '') {
            $maybeCharge = trim((string) ($data['id'] ?? ''));
            if (str_starts_with($maybeCharge, 'clx')) {
                $chargeId = $maybeCharge;
            }
        }

        if ($chargeId !== '') {
            $byCharge = Order::query()
                ->where('gateway', 'xflow')
                ->where('gateway_id', $chargeId)
                ->first();
            if ($byCharge !== null) {
                return $byCharge;
            }
        }

        $external = trim((string) ($data['external_reference'] ?? $payload['external_reference'] ?? ''));
        if ($external !== '' && ctype_digit($external)) {
            $byRef = Order::query()
                ->where('gateway', 'xflow')
                ->where('id', (int) $external)
                ->first();
            if ($byRef !== null) {
                return $byRef;
            }
        }

        $disputeId = trim((string) ($data['dispute_id'] ?? $data['id'] ?? ''));
        if ($disputeId !== '') {
            $existing = MedDispute::query()
                ->where('cajupay_dispute_id', XflowMedService::REMOTE_ID_PREFIX.$disputeId)
                ->first();
            if ($existing?->order !== null) {
                return $existing->order;
            }
        }

        $e2e = trim((string) ($data['txid'] ?? $data['e2e_id'] ?? $data['pix_e2e_id'] ?? $data['end_to_end_id'] ?? ''));
        if ($e2e !== '') {
            return Order::query()
                ->where('gateway', 'xflow')
                ->where(function ($q) use ($e2e) {
                    $q->where('metadata->e2e_id', $e2e)
                        ->orWhere('metadata->end_to_end_id', $e2e)
                        ->orWhere('metadata->txid', $e2e);
                })
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventName(Request $request, array $payload): string
    {
        $header = $request->header('X-Webhook-Event');
        if (is_string($header) && trim($header) !== '') {
            return strtolower(trim($header));
        }
        $event = $payload['event'] ?? '';

        return is_string($event) ? strtolower(trim($event)) : '';
    }

    /**
     * @return array{event: string, status: string}|null
     */
    private function mapEvent(string $event, mixed $status): ?array
    {
        if ($event === 'transaction.paid') {
            return ['event' => 'order.paid', 'status' => 'paid'];
        }
        if ($event === 'transaction.refunded') {
            return ['event' => 'order.refunded', 'status' => 'refunded'];
        }

        $mapped = XflowDriver::mapChargeStatus($status);
        if ($mapped === 'paid') {
            return ['event' => 'order.paid', 'status' => 'paid'];
        }
        if ($mapped === 'cancelled' && is_string($status) && strtolower($status) === 'refunded') {
            return ['event' => 'order.refunded', 'status' => 'refunded'];
        }
        if ($mapped === 'cancelled') {
            return ['event' => 'order.cancelled', 'status' => 'cancelled'];
        }

        return null;
    }

    private function verifySignature(Request $request, ?int $tenantId): bool
    {
        if (GatewayInboundWebhookAuth::verifyHmacSha256Body(
            $request,
            'xflow',
            $tenantId,
            'X-Webhook-Signature',
            'x-webhook-signature',
        )) {
            return true;
        }

        $credential = GatewayCredential::resolveForPayment($tenantId, 'xflow');
        if ($credential === null) {
            return false;
        }
        $creds = $credential->getDecryptedCredentials();
        $signature = $request->header('X-Webhook-Signature') ?: $request->header('x-webhook-signature');
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        foreach (['payout_webhook_secret', 'dispute_webhook_secret'] as $secretKey) {
            $secret = trim((string) ($creds[$secretKey] ?? ''));
            if ($secret === '') {
                continue;
            }
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
            $alt = hash_hmac('sha256', $request->getContent(), $secret);
            if (hash_equals($expected, $signature) || hash_equals($alt, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function shouldIgnoreSandboxLivemode(?int $tenantId, array $data): bool
    {
        if (($data['livemode'] ?? null) !== false) {
            return false;
        }

        $credential = GatewayCredential::resolveForPayment($tenantId, 'xflow');
        if ($credential === null) {
            return false;
        }

        return XflowDriver::isLiveCredentials($credential->getDecryptedCredentials());
    }
}
