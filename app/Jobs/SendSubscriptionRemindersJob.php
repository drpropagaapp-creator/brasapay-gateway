<?php

namespace App\Jobs;

use App\Services\SubscriptionReminderService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs synchronously when scheduled (no ShouldQueue) so renewal e-mails do not depend on queue workers.
 */
class SendSubscriptionRemindersJob
{
    use Dispatchable;

    public function handle(SubscriptionReminderService $reminders): void
    {
        $reminders->sendScheduledReminders();
    }
}
