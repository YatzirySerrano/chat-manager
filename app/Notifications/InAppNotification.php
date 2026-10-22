<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\InAppChannel;
use Illuminate\Notifications\Notification;

/**
 * Base of every in-app notification. Subclasses describe WHAT happened with
 * identifiers only (never message bodies, phone numbers or notes); the
 * visible text is built by NotificationPresenter when the recipient reads
 * it, after checking that they can still see the record.
 *
 * Delivery is idempotent: the channel records sourceKey() per recipient and
 * ignores repetitions (webhook retries, re-dispatched listeners). When
 * aggregateKey() is not null, a new occurrence updates the recipient's
 * unread notification with the same key instead of adding another one.
 */
abstract class InAppNotification extends Notification
{
    abstract public function kind(): NotificationKind;

    /**
     * Identifier of the source event, unique per kind.
     */
    abstract public function sourceKey(): string;

    /**
     * Identifiers and safe metadata stored with the notification.
     *
     * @return array<string, scalar|list<int>|null>
     */
    abstract public function payload(): array;

    /**
     * Key under which bursts fold into a single unread notification, or null
     * to always create a new one.
     */
    public function aggregateKey(): ?string
    {
        return null;
    }

    /**
     * Combine a new occurrence into the payload of the unread notification
     * that shares the aggregation key.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public function aggregate(array $existing): array
    {
        return [...$existing, ...$this->payload()];
    }

    /**
     * @return list<class-string>
     */
    public function via(User $notifiable): array
    {
        return [InAppChannel::class];
    }

    /**
     * Inactive accounts and users who turned the kind off receive nothing.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return $notifiable->is_active
            && app(NotificationPreferences::class)->allowsInApp($notifiable, $this->kind());
    }
}
