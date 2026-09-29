<?php

namespace App\Services;

use App\Events\OrderCompleted;
use App\Models\Order;
use InvalidArgumentException;

class OrderManualApprovalService
{
    /**
     * Marca o pedido como concluído (aprovação manual pela plataforma), concede acesso e dispara OrderCompleted.
     *
     * @throws InvalidArgumentException Se o pedido não estiver pendente
     */
    public static function approve(Order $order): void
    {
        if ($order->status !== 'pending') {
            throw new InvalidArgumentException('Só é possível aprovar pedidos com status pendente.');
        }

        $order->load(['product', 'productOffer', 'subscriptionPlan', 'orderItems.product']);

        $completedPatch = ['status' => 'completed', 'approved_manually' => true];
        if ($order->payment_method === null || $order->payment_method === '') {
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $m = $meta['checkout_payment_method'] ?? null;
            if (in_array($m, ['apple_pay', 'google_pay'], true)) {
                $completedPatch['payment_method'] = 'card';
            } elseif (in_array($m, ['pix', 'card', 'boleto', 'pix_auto'], true)) {
                $completedPatch['payment_method'] = $m;
            } else {
                $completedPatch['payment_method'] = 'pix';
            }
        }
        $order->update($completedPatch);
        $order->refresh();

        app(SubscriptionRenewalService::class)->syncFromPaidOrder($order);

        event(new OrderCompleted($order));
    }
}
