<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Pedidos criados pelo navegador atual. A página de obrigado recebe o order_id
 * pela URL (sequencial); dados pessoais do comprador só podem ir para quem
 * criou o pedido nesta sessão ou para o próprio dono logado.
 */
final class CheckoutOrderOwnership
{
    public const SESSION_KEY = 'checkout_owned_order_ids';

    private const MAX_REMEMBERED = 20;

    public static function remember(Order $order): void
    {
        if (! app()->bound('request')) {
            return;
        }

        $request = request();
        if (! $request->hasSession()) {
            return;
        }

        $ids = array_map('intval', (array) $request->session()->get(self::SESSION_KEY, []));
        $ids[] = (int) $order->id;
        $ids = array_slice(array_values(array_unique($ids)), -self::MAX_REMEMBERED);
        $request->session()->put(self::SESSION_KEY, $ids);
    }

    public static function owns(Request $request, Order $order): bool
    {
        $user = $request->user();
        if ($user && $order->user_id && (int) $user->id === (int) $order->user_id) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        $ids = array_map('intval', (array) $request->session()->get(self::SESSION_KEY, []));

        return in_array((int) $order->id, $ids, true);
    }
}
