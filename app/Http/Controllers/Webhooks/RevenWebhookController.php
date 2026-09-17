<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\PaymentWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Callback Reven Payments (callbackUrl enviado em cada cobrança).
 *
 * O formato do payload não é documentado publicamente, então o parser aceita
 * variações comuns (transactionId/id/transaction.id, status no topo ou aninhado).
 * O callback não é assinado: a confirmação de pagamento é SEMPRE reconferida
 * na API (listagem) pelo ProcessPaymentWebhook antes de liberar o pedido.
 */
class RevenWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $transactionId = $this->extractTransactionId($request);
        if ($transactionId === null) {
            return response()->json(['message' => 'transactionId required'], 400);
        }

        $order = $this->findOrder($request, $transactionId);
        if ($order === null) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $status = $this->extractStatus($request);
        if (! in_array($status, ['paid', 'approved', 'completed'], true)) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        PaymentWebhookDispatcher::dispatch(
            'reven',
            (string) $order->gateway_id,
            'order.paid',
            'paid',
            array_merge($request->all(), ['webhook_source' => 'reven_webhook'])
        );

        return response()->json(['received' => true]);
    }

    private function findOrder(Request $request, string $transactionId): ?Order
    {
        $byTx = Order::query()
            ->where('gateway', 'reven')
            ->where('gateway_id', $transactionId)
            ->first();
        if ($byTx !== null) {
            return $byTx;
        }

        // metadata.orderId enviado na criação da cobrança
        foreach (['metadata.orderId', 'transaction.metadata.orderId', 'data.metadata.orderId'] as $path) {
            $orderId = $request->input($path);
            if ((is_string($orderId) || is_int($orderId)) && ctype_digit((string) $orderId)) {
                $byId = Order::query()
                    ->where('gateway', 'reven')
                    ->where('id', (int) $orderId)
                    ->first();
                if ($byId !== null) {
                    return $byId;
                }
            }
        }

        return null;
    }

    private function extractTransactionId(Request $request): ?string
    {
        foreach (['transactionId', 'id', 'transaction.id', 'data.transactionId', 'data.id', 'transaction_id'] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractStatus(Request $request): string
    {
        foreach (['status', 'transaction.status', 'data.status', 'event'] as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return '';
    }
}
