<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeSignals;
use App\Notifications\NotificationSignal;
use Illuminate\Container\Attributes\Scoped;

/**
 * Publishes the notification hint right away, in the worker that stored the
 * notification (no extra queue job between the database and the bell).
 * RealtimeSignals logs a failed publication instead of throwing, so a stopped
 * Reverb never fails or retries the notification listener.
 *
 * The listener is scoped (one instance per queue job): once a publication
 * fails, the remaining hints of the same job are skipped, so a slow Reverb
 * costs at most one client timeout per job instead of one per recipient and
 * never pushes a large notification fan-out past the worker --timeout. The
 * next job tries again; skipped users catch up through the bell refresh.
 */
#[Scoped]
class BroadcastNotificationSignal
{
    private bool $publishingFailed = false;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(NotificationSignal $event): void
    {
        if ($this->publishingFailed || config('broadcasting.default') === 'null') {
            return;
        }

        $this->publishingFailed = ! $this->signals->toUsers([$event->userId], 'notification', [
            'id' => $event->notificationId,
            'kind' => $event->kind,
        ]);
    }
}
