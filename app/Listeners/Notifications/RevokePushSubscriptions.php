<?php

namespace App\Listeners\Notifications;

use App\Events\Users\UserAccessChanged;
use App\Models\PushSubscription;
use App\Models\User;

/**
 * A deactivated or deleted account loses its Web Push subscriptions at once
 * (a single DELETE, run after the change commits), so its browsers stop
 * being woken up. Restoring the account does not bring them back: the
 * person activates push again from their preferences.
 */
class RevokePushSubscriptions
{
    public function handle(UserAccessChanged $event): void
    {
        if ($event->userIds === []) {
            return;
        }

        PushSubscription::query()
            ->whereIn('user_id', User::query()
                ->whereKey(array_values(array_unique($event->userIds)))
                ->where('is_active', false)
                ->select('id'))
            ->delete();
    }
}
