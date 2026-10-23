<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\InAppNotification;
use App\Notifications\NotificationSignal;
use App\Support\ReadCommittedTransaction;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores in-app notifications idempotently and with aggregation:
 *
 * 1. the (recipient, source key) receipt is inserted first; when it already
 *    exists the event was notified before and nothing happens;
 * 2. with an aggregation key, the recipient's unread notification with that
 *    key is created or, when it exists, counted (occurrences + 1, newest
 *    payload, moved to the top) in one atomic upsert on the database's
 *    single-unread-per-key unique index; otherwise a new notification is
 *    created. Two workers never create two, and nothing locks a row that
 *    may not exist (no gap locks);
 * 3. after commit the recipient receives a content-free real-time signal.
 *
 * It runs at READ COMMITTED with bounded, logged deadlock retries
 * (ReadCommittedTransaction), so a burst for the same recipient does not
 * fail the listener job and restart the whole fan-out.
 */
class InAppChannel
{
    public function __construct(private ReadCommittedTransaction $transaction) {}

    public function send(User $notifiable, InAppNotification $notification): ?DatabaseNotification
    {
        return $this->transaction->run('notification.in_app', fn (): ?DatabaseNotification => $this->store($notifiable, $notification));
    }

    private function store(User $notifiable, InAppNotification $notification): ?DatabaseNotification
    {
        $sourceKey = mb_substr($notification->sourceKey(), 0, 191);

        $inserted = DB::table('notification_receipts')->insertOrIgnore([
            'user_id' => $notifiable->id,
            'source_key' => $sourceKey,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return null;
        }

        $aggregateKey = $notification->aggregateKey();

        $record = $aggregateKey === null
            ? $notifiable->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $notification::class,
                'kind' => $notification->kind()->value,
                'level' => $notification->kind()->level()->value,
                'aggregate_key' => null,
                'data' => $notification->payload(),
                'read_at' => null,
            ])
            : $this->fold($notifiable, $notification, $aggregateKey);

        DB::table('notification_receipts')
            ->where('user_id', $notifiable->id)
            ->where('source_key', $sourceKey)
            ->update(['notification_id' => $record->id]);

        NotificationSignal::dispatch($notifiable->id, (string) $record->id, $notification->kind()->value);

        return $record;
    }

    /**
     * Inserts the unread aggregate, or counts the occurrence on the existing
     * one when the unique (recipient, unread aggregation key) index already
     * has it. Either way the statement leaves that row locked by this
     * transaction, so merging the payload afterwards cannot lose another
     * worker's occurrence.
     */
    private function fold(User $notifiable, InAppNotification $notification, string $aggregateKey): DatabaseNotification
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('notifications')->upsert([
            'id' => $id,
            'type' => $notification::class,
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->id,
            'kind' => $notification->kind()->value,
            'level' => $notification->kind()->level()->value,
            'aggregate_key' => $aggregateKey,
            'occurrences' => 1,
            'data' => json_encode($notification->payload(), JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['notifiable_type', 'notifiable_id', 'unread_aggregate_key'], [
            'occurrences' => DB::raw('occurrences + 1'),
            'updated_at',
        ]);

        /** @var DatabaseNotification $record */
        $record = $notifiable->notifications()->getQuery()->where('unread_aggregate_key', $aggregateKey)->firstOrFail();

        if ($record->id !== $id) {
            /** @var array<string, mixed> $data */
            $data = $record->data;

            $record->forceFill(['data' => $notification->aggregate($data)])->save();
        }

        return $record;
    }
}
