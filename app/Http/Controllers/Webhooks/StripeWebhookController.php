<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Support\PaymentWebhookDispatcher;
use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    /**
     * Handle Stripe webhook (POST /webhooks/gateways/stripe).
     * Verifies signature using the tenant's webhook secret (from credential).
     *
     * Eventos tratados (habilite só estes no endpoint da Stripe):
     * payment_intent.succeeded, charge.refunded, charge.dispute.created, charge.dispute.closed.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        if (! is_string($payload) || $payload === '' || ! is_string($sigHeader) || $sigHeader === '') {
            return response()->json(['message' => 'Invalid request'], 400);
        }

        $payloadData = json_decode($payload, true);
        if (! is_array($payloadData)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $mapped = self::mapEvent($payloadData);
        if ($mapped === null) {
            return response()->json(['received' => true]);
        }

        $paymentIntentId = $mapped['payment_intent'];

        $order = Order::where('gateway', 'stripe')->where('gateway_id', $paymentIntentId)->first();
        if (! $order) {
            Log::warning('StripeWebhook: order not found', ['gateway_id' => $paymentIntentId, 'event' => $mapped['event']]);
            return response()->json(['received' => true]);
        }

        $credential = GatewayCredential::resolveForPayment($order->tenant_id, 'stripe');

        if (! $credential) {
            return response()->json(['message' => 'Credential not found'], 400);
        }

        $credentials = $credential->getDecryptedCredentials();
        $webhookSecret = trim($credentials['webhook_secret'] ?? '');
        if ($webhookSecret === '') {
            Log::warning('StripeWebhook: webhook_secret not configured', ['tenant_id' => $order->tenant_id]);
            return response()->json(['message' => 'Webhook not configured'], 400);
        }

        try {
            Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            Log::warning('StripeWebhook: invalid payload', ['message' => $e->getMessage()]);
            return response()->json(['message' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('StripeWebhook: signature verification failed', ['message' => $e->getMessage()]);
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        PaymentWebhookDispatcher::dispatch('stripe', $paymentIntentId, $mapped['event'], $mapped['status'], $payloadData);

        return response()->json(['received' => true]);
    }

    /**
     * Converte o evento da Stripe no evento/status interno usado por ProcessPaymentWebhook.
     * Retorna null para eventos ignorados (inclusive reembolso parcial e disputa ainda sem desfecho).
     *
     * @param  array<string, mixed>  $payload
     * @return array{event: string, status: string, payment_intent: string}|null
     */
    public static function mapEvent(array $payload): ?array
    {
        $type = $payload['type'] ?? null;
        $object = $payload['data']['object'] ?? null;
        if (! is_string($type) || ! is_array($object)) {
            return null;
        }

        switch ($type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $object['id'] ?? null;
                $event = 'payment_intent.succeeded';
                $status = 'paid';
                break;

            case 'charge.refunded':
                // Só reembolso total desfaz a venda; parcial é ignorado (tratar manualmente).
                $amount = (int) ($object['amount'] ?? 0);
                $refunded = (int) ($object['amount_refunded'] ?? 0);
                $full = ($object['refunded'] ?? false) === true || ($amount > 0 && $refunded >= $amount);
                if (! $full) {
                    return null;
                }
                $paymentIntent = $object['payment_intent'] ?? null;
                $event = 'payment.refunded';
                $status = 'refunded';
                break;

            case 'charge.dispute.created':
                $paymentIntent = $object['payment_intent'] ?? null;
                $event = 'payment.disputed';
                $status = 'disputed';
                break;

            case 'charge.dispute.closed':
                $outcome = (string) ($object['status'] ?? '');
                if (in_array($outcome, ['won', 'warning_closed'], true)) {
                    $status = 'won';
                } elseif ($outcome === 'lost') {
                    $status = 'lost';
                } else {
                    return null;
                }
                $paymentIntent = $object['payment_intent'] ?? null;
                $event = 'payment.dispute_closed';
                break;

            default:
                return null;
        }

        if (! is_string($paymentIntent) || ! str_starts_with($paymentIntent, 'pi_')) {
            return null;
        }

        return ['event' => $event, 'status' => $status, 'payment_intent' => $paymentIntent];
    }
}
