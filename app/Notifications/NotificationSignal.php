<?php

namespace App\Notifications;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "You have a new or updated notification": dispatched by the in-app channel
 * once the notification is committed, and published at once by
 * App\Listeners\Realtime\BroadcastNotificationSignal as `.notification`
 * {id, kind} on the recipient's private channel `users.{id}`. It carries the
 * notification id and kind, never its content: the client fetches the list
 * again from the server, which checks access.
 *
 * It is a best-effort hint published without a queue job of its own (the
 * notification is already stored inside a queued listener): when
 * broadcasting is down the failure is logged, the notification stays stored
 * and the bell keeps working through its periodic refresh.
 */
class NotificationSignal implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly string $notificationId,
        public readonly string $kind,
    ) {}
}
