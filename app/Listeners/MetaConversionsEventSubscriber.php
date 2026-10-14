<?php

namespace App\Listeners;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\OrderRefunded;
use App\Events\PixGenerated;
use App\Jobs\MetaConversionsSendPurchaseJob;
use App\Services\Meta\MetaTrackingService;
use App\Services\MetaPurchaseTrackingDiagnostics;
use App\Support\IntegrationJobDispatch;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

class MetaConversionsEventSubscriber
{
    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCompleted::class => 'handleOrderCompleted',
            PixGenerated::class => 'handlePixGenerated',
            BoletoGenerated::class => 'handleBoletoGenerated',
            OrderRefunded::class => 'handleOrderRefunded',
        ];
    }

    public function handlePixGenerated(PixGenerated $event): void
    {
        $this->queueAddPaymentInfo($event->order);
    }

    public function handleBoletoGenerated(BoletoGenerated $event): void
    {
        $this->queueAddPaymentInfo($event->order);
    }

    public function handleOrderRefunded(OrderRefunded $event): void
    {
        $orderId = (int) $event->order->id;

        try {
            app(MetaTrackingService::class)->queueRefundForOrder($event->order);
        } catch (\Throwable $e) {
            Log::warning('MetaConversionsEventSubscriber: refund queue failed', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function handleOrderCompleted(OrderCompleted $event): void
    {
        $orderId = (int) $event->order->id;

        try {
            app(MetaTrackingService::class)->queueAddPaymentInfoForOrder($event->order);
        } catch (\Throwable $e) {
            Log::warning('MetaConversionsEventSubscriber: add payment info queue failed', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            if (IntegrationJobDispatch::shouldDispatchSync()) {
                MetaConversionsSendPurchaseJob::dispatchSync($orderId);
            } else {
                MetaConversionsSendPurchaseJob::dispatch($orderId)
                    ->onQueue((string) config('meta_tracking.queue', 'meta-tracking'))
                    ->afterResponse();
            }

            app(MetaPurchaseTrackingDiagnostics::class)->logQueueHintOnDispatch($orderId);
        } catch (\Throwable $e) {
            // Não abortar a cadeia de listeners (métricas, NF, Cademi, etc.).
            Log::warning('MetaConversionsEventSubscriber: purchase queue failed (listeners continue)', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function queueAddPaymentInfo(\App\Models\Order $order): void
    {
        try {
            app(MetaTrackingService::class)->queueAddPaymentInfoForOrder($order);
        } catch (\Throwable $e) {
            Log::warning('MetaConversionsEventSubscriber: add payment info queue failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
