<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\InAppNotification;
use App\Notifications\NotificationSignal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores in-app notifications idempotently and with aggregation:
 *
 * 1. the (recipient, source key) receipt is inserted first; when it already
 *    exists the event was notified before and nothing happens;
 * 2. with an aggregation key, the recipient's unread notification with that
 *    key is updated (occurrences + 1, newest payload, moved to the top);
 *    otherwise a new notification is created. The database allows a single
 *    unread notification per key, so two workers never create two;
 * 3. after commit the recipient receives a content-free real-time signal.
 */
class InAppChannel
{
    public function send(User $notifiable, InAppNotification $notification): ?DatabaseNotification
    {
        try {
            return $this->store($notifiable, $notification);
        } catch (UniqueConstraintViolationException) {
            // Another worker created the unread aggregate meanwhile: fold into it.
            return $this->store($notifiable, $notification);
        }
    }

    private function store(User $notifiable, InAppNotification $notification): ?DatabaseNotification
    {
        $stored = DB::transaction(function () use ($notifiable, $notification): ?DatabaseNotification {
            $inserted = DB::table('notification_receipts')->insertOrIgnore([
                'user_id' => $notifiable->id,
                'source_key' => mb_substr($notification->sourceKey(), 0, 191),
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return null;
            }

            $aggregateKey = $notification->aggregateKey();
            $existing = $aggregateKey === null ? null : $notifiable->notifications()
                ->getQuery()
                ->where('unread_aggregate_key', $aggregateKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof DatabaseNotification) {
                /** @var array<string, mixed> $data */
                $data = $existing->data;

                $existing->forceFill([
                    'data' => $notification->aggregate($data),
                    'occurrences' => $existing->getAttribute('occurrences') + 1,
                    'updated_at' => now(),
                ])->save();

                $record = $existing;
            } else {
                $record = $notifiable->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => $notification::class,
                    'kind' => $notification->kind()->value,
                    'level' => $notification->kind()->level()->value,
                    'aggregate_key' => $aggregateKey,
                    'data' => $notification->payload(),
                    'read_at' => null,
                ]);
            }

            DB::table('notification_receipts')
                ->where('user_id', $notifiable->id)
                ->where('source_key', mb_substr($notification->sourceKey(), 0, 191))
                ->update(['notification_id' => $record->id]);

            NotificationSignal::dispatch($notifiable->id, (string) $record->id, $notification->kind()->value);

            return $record;
        });

        return $stored;
    }
}
