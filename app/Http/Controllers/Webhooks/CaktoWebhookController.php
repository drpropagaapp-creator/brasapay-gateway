<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/gateways/cakto — corpo { secret, event, data }.
 *
 * Origem: X-Cakto-Signature = v1=HMAC-SHA256(secret, "{X-Cakto-Timestamp}.{corpo cru}").
 * Pagamento e reembolso são sempre reconferidos na API (GET /public_api/orders/{id}/)
 * pelo ProcessPaymentWebhook; contestação (chargeback/MED) só com assinatura válida.
 *
 * @see https://docs.cakto.com.br/conceitos/webhooks
 */
class CaktoWebhookController extends Controller
{
    private const SLUG = 'cakto';

    private const TOLERANCE_SECONDS = 300;

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $event = strtolower(trim((string) ($payload['event'] ?? '')));
        $data = $payload['data'] ?? null;
        // Webhook V2 manda data como lista (pedido principal + order bumps); o BrasaPay usa só um item.
        if (is_array($data) && array_is_list($data)) {
            $data = $data[0] ?? null;
        }
        if (! is_array($data)) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $caktoOrderId = trim((string) ($data['id'] ?? ''));
        if ($caktoOrderId === '') {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $order = Order::query()->where('gateway', self::SLUG)->where('gateway_id', $caktoOrderId)->first();
        if (! $order) {
            // Vendas feitas direto no checkout da Cakto (fora do BrasaPay) também chegam aqui.
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $credential = GatewayCredential::resolveForPayment($order->tenant_id, self::SLUG);
        $secret = $credential ? trim((string) ($credential->getDecryptedCredentials()['webhook_secret'] ?? '')) : '';
        $signed = false;
        if ($secret !== '') {
            if (! self::verifySignature(
                $request->getContent(),
                (string) $request->header('X-Cakto-Timestamp', ''),
                (string) $request->header('X-Cakto-Signature', ''),
                $secret
            )) {
                Log::warning('CaktoWebhook: assinatura inválida', ['order_id' => $order->id, 'event' => $event]);

                return response()->json(['message' => 'Unauthorized'], 401);
            }
            $signed = true;
        }

        $status = strtolower(trim((string) ($data['status'] ?? '')));

        if ($event === 'purchase_approved' || $status === 'paid') {
            PaymentWebhookDispatcher::dispatch(self::SLUG, $caktoOrderId, 'order.paid', 'paid', $payload);

            return response()->json(['received' => true]);
        }

        if ($event === 'refund' || $status === 'refunded') {
            PaymentWebhookDispatcher::dispatch(self::SLUG, $caktoOrderId, 'order.refunded', 'refunded', $payload);

            return response()->json(['received' => true]);
        }

        if ($signed && ($event === 'chargeback' || $status === 'chargedback')) {
            PaymentWebhookDispatcher::dispatch(self::SLUG, $caktoOrderId, 'order.disputed', 'chargeback', $payload);

            return response()->json(['received' => true]);
        }

        if ($event === 'purchase_refused' || in_array($status, ['refused', 'declined', 'canceled', 'cancelled'], true)) {
            PaymentWebhookDispatcher::dispatch(self::SLUG, $caktoOrderId, 'order.rejected', 'rejected', $payload);

            return response()->json(['received' => true]);
        }

        return response()->json(['received' => true, 'ignored' => true]);
    }

    public static function verifySignature(string $rawBody, string $timestamp, string $signatureHeader, string $secret, ?int $now = null): bool
    {
        if ($timestamp === '' || ! ctype_digit($timestamp) || $signatureHeader === '') {
            return false;
        }
        $now ??= time();
        if (abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        foreach (explode(',', $signatureHeader) as $candidate) {
            if (hash_equals($expected, trim($candidate))) {
                return true;
            }
        }

        return false;
    }
}
