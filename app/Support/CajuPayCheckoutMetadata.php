<?php

namespace App\Support;

use App\Models\Order;

/**
 * Chaves de metadata do fluxo SDK/draft CajuPay (cartão / wallets).
 */
final class CajuPayCheckoutMetadata
{
    public static function publicSessionToken(Order $order): ?string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        foreach (['cajupay_session_token', 'cajupay_sdk_token'] as $key) {
            $v = $meta[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    public static function checkoutSessionId(Order $order): ?string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $v = $meta['cajupay_checkout_session_id'] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /**
     * N de parcelas no payload Caju (webhook / sessão). Null se o campo não vier.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function installmentsFromPayload(array $payload): ?int
    {
        $object = $payload['data']['object'] ?? $payload['object'] ?? $payload;
        if (! is_array($object)) {
            $object = $payload;
        }
        foreach (['installments', 'card_installments', 'installment_count'] as $key) {
            if (! array_key_exists($key, $object)) {
                continue;
            }
            $n = (int) $object[$key];
            if ($n >= 1) {
                return max(1, min(12, $n));
            }
        }

        return null;
    }
}
