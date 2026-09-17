<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\GatewayInboundWebhookAuth;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AsaasWebhookController extends Controller
{
    /**
     * Handle Asaas webhook (POST /webhooks/gateways/asaas).
     * Auth: header asaas-access-token = authToken do webhook no painel Asaas.
     * Always respond 200 when order not found to avoid retries.
     */
    public function handle(Request $request): JsonResponse
    {
        $payment = $request->input('payment');
        if (! is_array($payment)) {
            return response()->json(['received' => true]);
        }
        $transactionId = $payment['id'] ?? null;
        if ($transactionId === null || $transactionId === '') {
            return response()->json(['received' => true]);
        }
        $transactionId = (string) $transactionId;

        $order = Order::where('gateway', 'asaas')->where('gateway_id', $transactionId)->first();
        if (! $order) {
            Log::debug('AsaasWebhook: order not found', ['gateway_id' => $transactionId]);

            return response()->json(['received' => true]);
        }

        if (! GatewayInboundWebhookAuth::verifyAsaas($request, $order->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        [$event, $mappedStatus] = $this->mapEvent(strtoupper((string) $request->input('event', '')));

        PaymentWebhookDispatcher::dispatch('asaas', $transactionId, $event, $mappedStatus, $request->all());

        return response()->json(['received' => true]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function mapEvent(string $eventType): array
    {
        if (in_array($eventType, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true)) {
            return ['order.paid', 'paid'];
        }

        if (in_array($eventType, ['PAYMENT_DELETED'], true)) {
            return ['order.cancelled', 'cancelled'];
        }

        if (in_array($eventType, ['PAYMENT_REFUNDED', 'PAYMENT_PARTIALLY_REFUNDED'], true)) {
            return ['order.refunded', 'refunded'];
        }

        if (in_array($eventType, ['PAYMENT_CHARGEBACK_REQUESTED', 'PAYMENT_CHARGEBACK_DISPUTE'], true)) {
            return ['order.disputed', 'disputed'];
        }

        if (in_array($eventType, ['PAYMENT_REPROVED_BY_RISK_ANALYSIS', 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED'], true)) {
            return ['order.rejected', 'rejected'];
        }

        return ['order.pending', 'pending'];
    }
}
