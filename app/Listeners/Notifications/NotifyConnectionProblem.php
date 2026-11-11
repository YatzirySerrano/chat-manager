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
 * the people who watch connections, and when there is evidence that it works
 * again (a successful verification, a message received or accepted after the
 * error) the same people are told once: the recovery folds into the unread
 * problem notification, so the bell never keeps a solved problem as current.
 * Configuration steps are not notified: they are visible on the connection.
 */
class NotifyConnectionProblem implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /** States worth interrupting an administrator for. */
    public const PROBLEM_STATES = [ConnectionHealth::Error, ConnectionHealth::CredentialsInvalid];

    /** States that prove a connection left a problem behind (not merely edited or paused). */
    public const RECOVERED_STATES = [ConnectionHealth::CredentialsVerified, ConnectionHealth::Receiving, ConnectionHealth::Operational];

    public function __construct(private NotificationRecipients $recipients) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::NOTIFICATIONS);
    }

    public function handle(ConnectionHealthChanged $event): void
    {
        $health = ConnectionHealth::tryFrom($event->health);

        if ($event->previousHealth === $event->health) {
            return;
        }

        $problem = in_array($health, self::PROBLEM_STATES, true);
        $recovered = in_array($health, self::RECOVERED_STATES, true)
            && in_array(ConnectionHealth::tryFrom((string) $event->previousHealth), self::PROBLEM_STATES, true);

        if (! $problem && ! $recovered) {
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
                $recovered,
            ));
        }
    }
}
