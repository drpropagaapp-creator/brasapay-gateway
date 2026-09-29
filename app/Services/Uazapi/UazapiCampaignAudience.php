<?php

namespace App\Services\Uazapi;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\UazapiCampaign;
use App\Models\UazapiInstance;
use App\Models\UazapiOptOut;

class UazapiCampaignAudience
{
    public function __construct(
        private UazapiClient $client,
        private UazapiMessageBuilder $messageBuilder,
    ) {}

    /**
     * @return array<string, int>
     */
    public function counts(int $tenantId, ?UazapiInstance $instance = null): array
    {
        return [
            UazapiCampaign::AUDIENCE_ABANDONED_CART => count($this->recipients($tenantId, UazapiCampaign::AUDIENCE_ABANDONED_CART, $instance)),
            UazapiCampaign::AUDIENCE_PENDING_PIX => count($this->recipients($tenantId, UazapiCampaign::AUDIENCE_PENDING_PIX, $instance)),
            UazapiCampaign::AUDIENCE_BUYERS => count($this->recipients($tenantId, UazapiCampaign::AUDIENCE_BUYERS, $instance)),
        ];
    }

    /**
     * @return list<array{
     *     phone: string,
     *     checkout_session_id: int|null,
     *     order_id: int|null,
     *     vars: array<string, string>,
     *     image_url: string|null
     * }>
     */
    public function recipients(int $tenantId, string $audience, ?UazapiInstance $instance = null): array
    {
        $max = (int) config('uazapi.campaign.max_recipients', 200);
        $optedOut = UazapiOptOut::query()
            ->where('tenant_id', $tenantId)
            ->pluck('phone')
            ->all();
        $blocked = array_fill_keys($optedOut, true);

        $rows = match ($audience) {
            UazapiCampaign::AUDIENCE_ABANDONED_CART => $this->abandonedCarts($tenantId, $instance),
            UazapiCampaign::AUDIENCE_PENDING_PIX => $this->pendingPix($tenantId, $instance),
            UazapiCampaign::AUDIENCE_BUYERS => $this->buyers($tenantId, $instance),
            default => [],
        };

        $unique = [];
        foreach ($rows as $row) {
            $phone = $row['phone'];
            if (isset($blocked[$phone]) || isset($unique[$phone])) {
                continue;
            }
            $unique[$phone] = $row;
            if (count($unique) >= $max) {
                break;
            }
        }

        return array_values($unique);
    }

    /**
     * @return list<array{phone: string, checkout_session_id: int|null, order_id: int|null, vars: array<string, string>, image_url: string|null}>
     */
    private function abandonedCarts(int $tenantId, ?UazapiInstance $instance = null): array
    {
        $days = (int) config('uazapi.campaign.abandoned_days', 7);
        $sessions = CheckoutSession::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('step', [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED])
            ->whereNull('order_id')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('created_at', '>=', now()->subDays($days))
            ->with('product:id,name,checkout_slug,image,tenant_id')
            ->orderByDesc('id')
            ->limit(800)
            ->get();

        $rows = [];
        foreach ($sessions as $session) {
            $phone = $this->client->normalizePhone($session->phone);
            if ($phone === null) {
                continue;
            }
            if ($instance && ! $instance->appliesToProduct($session->product_id !== null ? (string) $session->product_id : null)) {
                continue;
            }

            $vars = $this->messageBuilder->fromCheckoutSession($session);
            $rows[] = [
                'phone' => $phone,
                'checkout_session_id' => $session->id,
                'order_id' => null,
                'vars' => $vars,
                'image_url' => UazapiProductMedia::publicUrl($session->product),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{phone: string, checkout_session_id: int|null, order_id: int|null, vars: array<string, string>, image_url: string|null}>
     */
    private function pendingPix(int $tenantId, ?UazapiInstance $instance = null): array
    {
        $days = (int) config('uazapi.campaign.abandoned_days', 7);
        $orders = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->whereNull('api_application_id')
            ->whereNull('api_checkout_session_id')
            ->where('created_at', '>=', now()->subDays($days))
            ->with('product:id,name,checkout_slug,image,tenant_id')
            ->orderByDesc('id')
            ->limit(800)
            ->get();

        return $this->mapOrders($orders, $instance);
    }

    /**
     * @return list<array{phone: string, checkout_session_id: int|null, order_id: int|null, vars: array<string, string>, image_url: string|null}>
     */
    private function buyers(int $tenantId, ?UazapiInstance $instance = null): array
    {
        $days = (int) config('uazapi.campaign.buyers_days', 30);
        $orders = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->whereNull('api_application_id')
            ->whereNull('api_checkout_session_id')
            ->where('created_at', '>=', now()->subDays($days))
            ->with(['product:id,name,checkout_slug,image,tenant_id', 'user'])
            ->orderByDesc('id')
            ->limit(800)
            ->get();

        return $this->mapOrders($orders, $instance);
    }

    /**
     * @param  iterable<int, Order>  $orders
     * @return list<array{phone: string, checkout_session_id: int|null, order_id: int|null, vars: array<string, string>, image_url: string|null}>
     */
    private function mapOrders(iterable $orders, ?UazapiInstance $instance = null): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $raw = trim((string) ($order->phone ?? ''));
            if ($raw === '') {
                $metadata = is_array($order->metadata) ? $order->metadata : [];
                $raw = trim((string) ($metadata['phone'] ?? $metadata['customer_phone'] ?? ''));
            }
            $phone = $this->client->normalizePhone($raw);
            if ($phone === null) {
                continue;
            }
            if ($instance && ! $instance->appliesToOrder($order)) {
                continue;
            }

            $rows[] = [
                'phone' => $phone,
                'checkout_session_id' => null,
                'order_id' => (int) $order->id,
                'vars' => $this->messageBuilder->fromOrder($order),
                'image_url' => UazapiProductMedia::publicUrl($order->product),
            ];
        }

        return $rows;
    }
}
