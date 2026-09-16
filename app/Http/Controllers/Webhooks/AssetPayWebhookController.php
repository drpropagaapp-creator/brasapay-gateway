<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
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
 * Webhooks AssetPay (postbackUrl e webhooks do painel).
 *
 * Cash In:  transaction.created/paid/failed/refunded/cancelled/expired
 *           + MED: transaction.infraction / transaction.blocked / transaction.infraction.closed
 * Cash Out: withdrawal.created/processing/completed/failed/cancelled
 *
 * Postbacks (via postbackUrl) não são assinados; a confirmação de pagamento é
 * sempre reconferida na API (GET /transactions/{id}) pelo ProcessPaymentWebhook.
 * Webhooks cadastrados no painel AssetPay trazem X-Webhook-Signature (HMAC-SHA256).
 */
class AssetPayWebhookController extends Controller
{
    private const MED_EVENTS = [
        'transaction.infraction',
        'transaction.blocked',
        'transaction.infraction.closed',
    ];

    public function handle(Request $request): JsonResponse
    {
        $event = $this->eventName($request);

        if (str_starts_with($event, 'withdrawal.')) {
            return $this->handleCashout($request, $event);
        }

        $transactionId = $this->transactionField($request, 'id');
        if ($transactionId === null) {
            return response()->json(['message' => 'transaction.id required'], 400);
        }

        $order = $this->findOrder($request, $transactionId);
        if ($order === null) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (! GatewayInboundWebhookAuth::verifyAssetPay($request, $order->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (in_array($event, self::MED_EVENTS, true)
            || ($event === 'transaction.refunded' && is_array($request->input('infraction')))) {
            return $this->handleMed($request, $event, $order);
        }

        $isPaid = $event === 'transaction.paid'
            || strtolower((string) $this->transactionField($request, 'status')) === 'paid';
        if (! $isPaid) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        PaymentWebhookDispatcher::dispatch(
            'assetpay',
            (string) $order->gateway_id,
            'order.paid',
            'paid',
            array_merge($request->all(), ['webhook_source' => 'assetpay_webhook'])
        );

        return response()->json(['received' => true]);
    }

    private function handleMed(Request $request, string $event, Order $order): JsonResponse
    {
        try {
            app(AssetPayMedService::class)->handleWebhookEvent($event, $request->all(), $order);
        } catch (\Throwable $e) {
            Log::warning('AssetPay webhook: falha ao processar MED', [
                'event' => $event,
                'order_id' => $order->id,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return response()->json(['message' => 'MED processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function handleCashout(Request $request, string $event): JsonResponse
    {
        $withdrawal = $this->findWithdrawal($request);
        if ($withdrawal === null) {
            Log::warning('AssetPay webhook: withdrawal not found', [
                'event' => $event,
                'external_id' => $this->withdrawalField($request, 'external_id'),
                'client_reference_id' => $this->withdrawalField($request, 'client_reference_id'),
            ]);

            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if (! GatewayInboundWebhookAuth::verifyAssetPay($request, $withdrawal->tenant_id)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($event === 'withdrawal.completed' && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markPaid($withdrawal->fresh());
        } elseif (in_array($event, ['withdrawal.failed', 'withdrawal.cancelled'], true)
            && in_array($withdrawal->status, ['pending', 'processing'], true)) {
            MerchantWithdrawalService::markFailed(
                $withdrawal->fresh(),
                'Payout AssetPay falhou (webhook '.$event.').'
            );
        }

        return response()->json(['received' => true]);
    }

    private function findOrder(Request $request, string $transactionId): ?Order
    {
        $byTx = Order::query()
            ->where('gateway', 'assetpay')
            ->where('gateway_id', $transactionId)
            ->first();
        if ($byTx !== null) {
            return $byTx;
        }

        $externalRef = $this->transactionField($request, 'external_ref')
            ?? $this->transactionField($request, 'externalRef')
            ?? $this->scalar($request, 'external_ref');
        if ($externalRef !== null && ctype_digit($externalRef)) {
            return Order::query()
                ->where('gateway', 'assetpay')
                ->where('id', (int) $externalRef)
                ->first();
        }

        return null;
    }

    private function findWithdrawal(Request $request): ?Withdrawal
    {
        $externalId = $this->withdrawalField($request, 'external_id');
        if ($externalId !== null) {
            $byTx = Withdrawal::query()
                ->where('payout_provider', 'assetpay')
                ->where('payout_external_id', $externalId)
                ->first();
            if ($byTx !== null) {
                return $byTx;
            }
        }

        $reference = $this->withdrawalField($request, 'client_reference_id');
        if ($reference !== null && ctype_digit($reference)) {
            return Withdrawal::query()
                ->where('payout_provider', 'assetpay')
                ->where('id', (int) $reference)
                ->first();
        }

        return null;
    }

    private function eventName(Request $request): string
    {
        $header = $request->header('X-Webhook-Event');
        if (is_string($header) && trim($header) !== '') {
            return strtolower(trim($header));
        }

        $event = $this->scalar($request, 'event');

        return $event !== null ? strtolower($event) : '';
    }

    private function transactionField(Request $request, string $key): ?string
    {
        return $this->scalarAt($request, 'transaction.'.$key) ?? $this->scalar($request, $key);
    }

    private function withdrawalField(Request $request, string $key): ?string
    {
        return $this->scalarAt($request, 'withdrawal.'.$key) ?? $this->scalar($request, $key);
    }

    private function scalar(Request $request, string $key): ?string
    {
        return $this->scalarAt($request, $key) ?? $this->scalarAt($request, 'data.'.$key);
    }

    private function scalarAt(Request $request, string $path): ?string
    {
        if (! $request->has($path)) {
            return null;
        }
        $value = $request->input($path);
        if (is_string($value)) {
            $trim = trim($value);

            return $trim !== '' ? $trim : null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
