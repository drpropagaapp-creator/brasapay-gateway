<?php

namespace App\Listeners;

use App\Events\SubscriptionCancelled;
use App\Services\Versell\VersellPixAutoLifecycleService;

class CancelVersellPixAutoOnSubscriptionCancelled
{
    public function handle(SubscriptionCancelled $event): void
    {
        app(VersellPixAutoLifecycleService::class)->cancelRemote($event->subscription);
    }
}
