<?php

namespace App\Jobs\PlatformGa4;

use App\Models\Order;
use App\Services\PlatformGa4\PlatformGa4MeasurementClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPlatformGa4PurchaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(public int $orderId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(PlatformGa4MeasurementClient $client): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null) {
            return;
        }

        $client->sendPurchase($order);
    }
}
