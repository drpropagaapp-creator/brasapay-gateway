<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\PlatformGa4\SendPlatformGa4PurchaseJob;
use App\Support\PlatformGa4Settings;

class SendPlatformGa4PurchaseOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        if (! PlatformGa4Settings::canSendServerPurchase()) {
            return;
        }

        SendPlatformGa4PurchaseJob::dispatch((int) $event->order->id);
    }
}
