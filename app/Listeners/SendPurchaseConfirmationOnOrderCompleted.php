<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\SendPurchaseConfirmationMailJob;
use App\Services\MemberModuleAccessService;
use Illuminate\Support\Facades\Log;

/**
 * Enfileira a confirmação de compra padrão da plataforma (não atrasa checkout/webhook).
 */
class SendPurchaseConfirmationOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        $order = $event->order;
        if (MemberModuleAccessService::isRenewalOrder($order) || ! SendPurchaseConfirmationMailJob::enabled()) {
            return;
        }

        try {
            SendPurchaseConfirmationMailJob::dispatch((int) $order->id);
        } catch (\Throwable $e) {
            Log::warning('PurchaseConfirmation: falha ao enfileirar.', ['order_id' => $order->id, 'message' => $e->getMessage()]);
            report($e);
        }
    }
}
