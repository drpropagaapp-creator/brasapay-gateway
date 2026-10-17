<?php

namespace App\Services\PlatformGa4;

use App\Models\Order;
use App\Support\PlatformGa4Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PlatformGa4MeasurementClient
{
    public function sendPurchase(Order $order): void
    {
        if (! PlatformGa4Settings::canSendServerPurchase()) {
            return;
        }

        $order->refresh();
        if ((string) $order->status !== 'completed') {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! empty($meta['ga4_mp_purchase_sent'])) {
            return;
        }

        $measurementId = PlatformGa4Settings::measurementId();
        $secret = PlatformGa4Settings::apiSecret();
        $clientId = $this->clientId($order, $meta);
        $value = round((float) $order->amount + (float) ($order->shipping_amount ?? 0), 2);
        $currency = strtoupper((string) ($order->product?->currency ?: 'BRL'));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'BRL';
        }

        $transactionId = (string) ($order->public_reference ?: $order->id);
        $itemName = mb_substr((string) ($order->product?->name ?: 'Pedido'), 0, 100);

        $response = Http::timeout(8)
            ->acceptJson()
            ->asJson()
            ->post(
                'https://www.google-analytics.com/mp/collect?'.http_build_query([
                    'measurement_id' => $measurementId,
                    'api_secret' => $secret,
                ]),
                [
                    'client_id' => $clientId,
                    'events' => [[
                        'name' => 'purchase',
                        'params' => [
                            'transaction_id' => $transactionId,
                            'value' => $value,
                            'currency' => $currency,
                            'engagement_time_msec' => 1,
                            'items' => [[
                                'item_id' => (string) $order->product_id,
                                'item_name' => $itemName,
                                'price' => $value,
                                'quantity' => 1,
                            ]],
                        ],
                    ]],
                ]
            );

        if (! $response->successful()) {
            Log::warning('platform_ga4.purchase_failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Falha ao enviar purchase para o GA4.');
        }

        $order->refresh();
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! empty($meta['ga4_mp_purchase_sent'])) {
            return;
        }
        $meta['ga4_mp_purchase_sent'] = true;
        $order->metadata = $meta;
        $order->save();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function clientId(Order $order, array $meta): string
    {
        $stored = trim((string) ($meta['ga_client_id'] ?? ''));
        if (preg_match('/^\d+\.\d+$/', $stored) === 1) {
            return $stored;
        }

        $stamp = $order->created_at?->timestamp ?? time();

        return $order->id.'.'.$stamp;
    }
}
