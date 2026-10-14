<?php

namespace App\Services\Meta;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Subscription;

class MetaEventContextResolver
{
    public function forCheckoutSession(CheckoutSession $session, array $overrides = []): MetaEventContext
    {
        $session->loadMissing('product');

        $product = $session->product;
        $contentIds = [];
        if ($session->checkout_slug) {
            $contentIds[] = (string) $session->checkout_slug;
        } elseif ($product?->checkout_slug) {
            $contentIds[] = (string) $product->checkout_slug;
        }

        $value = isset($overrides['value']) ? (float) $overrides['value'] : null;
        $currency = isset($overrides['currency']) && is_string($overrides['currency'])
            ? strtoupper(trim($overrides['currency']))
            : 'BRL';

        [$firstName, $lastName] = $this->splitName($session->name ?? ($overrides['name'] ?? null));
        $email = $this->firstNonEmpty($session->email ?? null, $overrides['email'] ?? null);
        $externalId = $this->sellerCustomerKey($session->tenant_id, $email);
        $resolvedContentIds = $this->normalizeContentIds($overrides['content_ids'] ?? $contentIds);

        return new MetaEventContext(
            fbp: $this->firstNonEmpty($overrides['fbp'] ?? null, $session->meta_fbp),
            fbc: $this->resolveFbcForSession($session, $overrides),
            clientIp: $session->customer_ip,
            clientUserAgent: $this->firstNonEmpty($overrides['user_agent'] ?? null, $session->meta_user_agent),
            eventSourceUrl: $this->firstNonEmpty($overrides['event_source_url'] ?? null, $session->meta_page_url),
            email: $email,
            phone: $session->phone ?? ($overrides['phone'] ?? null),
            firstName: $firstName,
            lastName: $lastName,
            externalId: $externalId,
            externalIds: $externalId !== null ? [$externalId] : [],
            value: $value,
            currency: $currency,
            contentIds: $resolvedContentIds,
            contentName: $this->firstNonEmpty($overrides['content_name'] ?? null, $product?->name),
            contents: $this->contentsFromIds($resolvedContentIds, $value),
            numItems: max(1, count($resolvedContentIds)),
            eventTime: isset($overrides['event_time']) ? (int) $overrides['event_time'] : time(),
        );
    }

    public function forOrder(Order $order, array $overrides = []): MetaEventContext
    {
        $order->loadMissing([
            'product',
            'productOffer',
            'subscriptionPlan',
            'user',
            'checkoutSession',
            'orderItems.product',
            'orderItems.productOffer',
        ]);

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $session = $order->checkoutSession;

        $shipping = is_array($order->shipping_address) ? $order->shipping_address : [];
        $hasShipping = $this->shippingHasAddress($shipping);

        [$firstName, $lastName] = $this->splitName($order->user?->name ?? ($meta['customer_name'] ?? ($session?->name ?? null)));

        $contents = $this->contentsForOrder($order);
        $contentIds = array_column($contents, 'id');
        if ($contentIds === []) {
            $contentIds = $this->normalizeContentIds($overrides['content_ids'] ?? []);
            $contents = $this->contentsFromIds($contentIds, (float) $order->amount);
        }

        $sessionFbc = $session ? $this->resolveFbcForSession($session, []) : null;
        $email = $this->firstNonEmpty($order->email ?: null, $order->user?->email, $session?->email);
        $externalId = $this->sellerCustomerKey($order->tenant_id, $email);
        $purchaseEventTime = isset($meta['meta_capi_purchase_event_time'])
            ? (int) $meta['meta_capi_purchase_event_time']
            : (int) ($order->created_at?->timestamp ?? time());

        return new MetaEventContext(
            fbp: $this->firstNonEmpty($overrides['fbp'] ?? null, $meta['fbp'] ?? null, $session?->meta_fbp),
            fbc: $this->firstNonEmpty($overrides['fbc'] ?? null, $meta['fbc'] ?? null, $sessionFbc),
            clientIp: $order->customer_ip ?: ($session?->customer_ip),
            clientUserAgent: $this->firstNonEmpty($overrides['user_agent'] ?? null, $meta['user_agent'] ?? null, $session?->meta_user_agent),
            eventSourceUrl: $this->firstNonEmpty(
                $overrides['event_source_url'] ?? null,
                $meta['event_source_url'] ?? null,
                $session?->meta_page_url,
            ),
            email: $email,
            phone: $order->phone ?: ($session?->phone),
            firstName: $firstName,
            lastName: $lastName,
            city: $hasShipping && isset($shipping['city']) ? (string) $shipping['city'] : null,
            state: $hasShipping ? $this->firstNonEmpty($shipping['state'] ?? null, $shipping['federal_unit'] ?? null) : null,
            country: $hasShipping
                ? $this->firstNonEmpty(isset($shipping['country']) ? (string) $shipping['country'] : null, 'br')
                : 'br',
            zip: $hasShipping
                ? $this->firstNonEmpty($shipping['cep'] ?? null, $shipping['zip'] ?? null, $shipping['zipcode'] ?? null)
                : null,
            externalId: $externalId,
            externalIds: $externalId !== null ? [$externalId] : [],
            subscriptionId: $this->subscriptionIdForOrder($order),
            value: isset($overrides['value']) ? (float) $overrides['value'] : (float) $order->amount,
            currency: 'BRL',
            contentIds: $contentIds,
            contentName: $this->firstNonEmpty($overrides['content_name'] ?? null, $order->product?->name),
            contents: $contents,
            orderId: (string) $order->id,
            customerSegmentation: $this->customerSegmentation($order, $email),
            originalPurchaseEventTime: $purchaseEventTime,
            numItems: max(1, count($contents)),
            eventTime: isset($overrides['event_time'])
                ? (int) $overrides['event_time']
                : (int) ($order->updated_at?->timestamp ?? time()),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resolveFbcForSession(CheckoutSession $session, array $overrides): ?string
    {
        $fbc = $this->firstNonEmpty($overrides['fbc'] ?? null, $session->meta_fbc);
        if ($fbc !== null) {
            return $fbc;
        }

        $fbclid = is_string($session->meta_fbclid) ? trim($session->meta_fbclid) : '';
        if ($fbclid === '') {
            return null;
        }

        $built = CheckoutSession::buildFbcFromFbclid($fbclid);
        if ($session->exists && trim((string) $session->meta_fbc) === '') {
            $session->update(['meta_fbc' => $built]);
            $session->meta_fbc = $built;
        }

        return $session->meta_fbc !== null && trim((string) $session->meta_fbc) !== ''
            ? trim((string) $session->meta_fbc)
            : $built;
    }

    /**
     * @param  array<int, mixed>|null  $raw
     * @return array<int, string>
     */
    private function normalizeContentIds(?array $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($id) => is_scalar($id) ? trim((string) $id) : '',
            $raw
        ), fn ($id) => $id !== ''));
    }

    /**
     * Chave estável do comprador neste seller. O hash vai em external_id.
     * CPF não entra.
     */
    private function sellerCustomerKey(mixed $tenantId, ?string $email): ?string
    {
        $tenant = (int) $tenantId;
        $normalized = strtolower(trim((string) $email));
        if ($tenant <= 0 || $normalized === '' || ! str_contains($normalized, '@')) {
            return null;
        }

        return $tenant.':'.$normalized;
    }

    /**
     * @param  array<int, string>  $contentIds
     * @return array<int, array{id: string, quantity: int, item_price?: float}>
     */
    private function contentsFromIds(array $contentIds, ?float $value): array
    {
        $contents = [];
        foreach ($contentIds as $id) {
            $row = [
                'id' => $id,
                'quantity' => 1,
            ];
            if ($value !== null && count($contentIds) === 1) {
                $row['item_price'] = round(max(0, $value), 2);
            }
            $contents[] = $row;
        }

        return $contents;
    }

    /**
     * IDs de catálogo não existem no produto. O identificador estável compartilhado
     * com o pixel é o checkout_slug da oferta ou do produto. Sem slug, product:{id}.
     *
     * @return array<int, array{id: string, quantity: int, item_price: float}>
     */
    public function contentsForOrder(Order $order): array
    {
        $order->loadMissing(['orderItems.product', 'orderItems.productOffer']);
        $contents = [];
        foreach ($order->orderItems as $item) {
            if (! $item instanceof OrderItem) {
                continue;
            }
            $id = $this->contentIdForItem($item, $order);
            if ($id === null) {
                continue;
            }
            $contents[] = [
                'id' => $id,
                'quantity' => 1,
                'item_price' => round(max(0, (float) $item->amount), 2),
            ];
        }

        if ($contents === []) {
            $slug = trim($order->getCheckoutSlug());
            if ($slug !== '') {
                $contents[] = [
                    'id' => $slug,
                    'quantity' => 1,
                    'item_price' => round(max(0, (float) $order->amount), 2),
                ];
            }
        }

        return $contents;
    }

    private function contentIdForItem(OrderItem $item, Order $order): ?string
    {
        $offerSlug = trim((string) ($item->productOffer?->checkout_slug ?? ''));
        if ($offerSlug !== '') {
            return $offerSlug;
        }

        $productSlug = trim((string) ($item->product?->checkout_slug ?? ''));
        if ($productSlug !== '') {
            return $productSlug;
        }

        if ((int) $item->position === 0) {
            $orderSlug = trim($order->getCheckoutSlug());
            if ($orderSlug !== '') {
                return $orderSlug;
            }
        }

        if ($item->product_id) {
            return 'product:'.$item->product_id;
        }

        return null;
    }

    private function subscriptionIdForOrder(Order $order): ?string
    {
        if (! $order->user_id) {
            return null;
        }
        if (! $order->subscription_plan_id && ! $order->is_renewal) {
            return null;
        }

        $subscription = Subscription::query()
            ->where('user_id', $order->user_id)
            ->where('product_id', $order->product_id)
            ->orderByDesc('id')
            ->first();

        return $subscription ? (string) $subscription->id : null;
    }

    private function customerSegmentation(Order $order, ?string $email): ?string
    {
        if ($order->is_renewal) {
            return 'existing_customer_to_business';
        }
        if ($email === null || $email === '') {
            return null;
        }
        if (! $order->exists) {
            return 'new_customer_to_business';
        }

        $previous = Order::query()
            ->where('status', 'completed')
            ->where('id', '!=', $order->id)
            ->when($order->tenant_id, fn ($query) => $query->where('tenant_id', $order->tenant_id))
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->exists();

        return $previous
            ? 'existing_customer_to_business'
            : 'new_customer_to_business';
    }

    /**
     * @param  array<string, mixed>  $shipping
     */
    private function shippingHasAddress(array $shipping): bool
    {
        foreach (['city', 'state', 'federal_unit', 'cep', 'zip', 'zipcode', 'street', 'neighborhood'] as $key) {
            if (isset($shipping[$key]) && is_string($shipping[$key]) && trim($shipping[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(mixed $name): array
    {
        if (! is_string($name) || trim($name) === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            $parts[0] ?? null,
            $parts[1] ?? null,
        ];
    }

    private function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
