<?php

namespace App\Listeners\Notifications;

use App\Events\Users\UserAccessChanged;
use App\Models\User;
use App\Notifications\AccessChangedNotification;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Tells users that an administrator changed their role, permissions,
 * departments or account state, so a section that disappears or appears is
 * not a surprise. No detail of the change is included. Changes within the
 * same few minutes fold into one notification.
 */
class NotifyAccessChanged implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /** Upper bound of users notified for one change (a role held by many). */
    private const MAX_RECIPIENTS = 1000;

    public function viaQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    public function handle(UserAccessChanged $event): void
    {
        if ($event->userIds === []) {
            return;
        }

        $users = User::query()
            ->active()
            ->whereKey(array_slice(array_values(array_unique($event->userIds)), 0, self::MAX_RECIPIENTS))
            ->get();

        if ($users->isNotEmpty()) {
            $bucket = now()->format('YmdH').intdiv((int) now()->format('i'), 5);

            Notification::send($users, new AccessChangedNotification($event->reason, $bucket));
        }
    }
}
