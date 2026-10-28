<?php

namespace App\Listeners;

use App\Events\SubscriptionCancelled;
use App\Services\LinaOpenx\LinaOpenxPixAutoLifecycleService;

class CancelLinaOpenxPixAutoOnSubscriptionCancelled
{
    public function handle(SubscriptionCancelled $event): void
    {
        app(LinaOpenxPixAutoLifecycleService::class)->cancelRemote($event->subscription);
    }
}
