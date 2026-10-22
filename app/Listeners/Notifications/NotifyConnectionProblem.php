<?php

namespace App\Listeners\Notifications;

use App\Enums\ConnectionHealth;
use App\Events\Connections\ConnectionHealthChanged;
use App\Models\Connection;
use App\Notifications\ConnectionProblemNotification;
use App\Notifications\NotificationRecipients;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * A connection that degrades (provider error, rejected credentials) alerts
 * the people who watch connections. Recoveries and configuration steps are
 * not notified: they are visible on the connection itself.
 */
class NotifyConnectionProblem implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /** States worth interrupting an administrator for. */
    public const PROBLEM_STATES = [ConnectionHealth::Error, ConnectionHealth::CredentialsInvalid];

    public function __construct(private NotificationRecipients $recipients) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    public function handle(ConnectionHealthChanged $event): void
    {
        $health = ConnectionHealth::tryFrom($event->health);

        if (! in_array($health, self::PROBLEM_STATES, true) || $event->previousHealth === $event->health) {
            return;
        }

        if (! Connection::query()->whereKey($event->connectionId)->exists()) {
            return;
        }

        $recipients = $this->recipients->connectionWatchers();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ConnectionProblemNotification(
                $event->connectionId,
                $event->health,
                $event->previousHealth,
                now()->format('YmdH'),
            ));
        }
    }
}
