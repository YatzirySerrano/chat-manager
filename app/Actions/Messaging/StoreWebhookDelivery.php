<?php

namespace App\Actions\Messaging;

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Connection;
use App\Models\WebhookEvent;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaInboundPayload;
use App\Services\Meta\MetaMessagingPayload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Stores an authenticated webhook body and queues its processing, doing
 * nothing expensive during the HTTP request. The same body delivered again
 * for the same connection is stored once: it is queued again only when its
 * previous processing failed, or when it is still waiting after
 * STALE_AFTER_SECONDS (its job was lost, e.g. the queue was down when it was
 * dispatched and Meta is now redelivering it). Processing is idempotent, so
 * a second job for the same event changes nothing.
 */
class StoreWebhookDelivery
{
    /** A stored delivery still "received" after this long is considered lost by the queue. */
    public const STALE_AFTER_SECONDS = 120;

    public function __construct(private ConnectionMonitor $monitor) {}

    /**
     * @param  array<array-key, mixed>  $payload  The decoded body (already authenticated).
     */
    public function handle(Connection $connection, string $body, array $payload): WebhookEvent
    {
        $hash = hash('sha256', $body);
        // Messenger/Instagram bodies have messaging items instead of changes.
        $fields = implode(',', MetaMessagingPayload::channelOf($payload['object'] ?? null) !== null
            ? MetaMessagingPayload::fields($payload)
            : array_values(array_unique(array_column(MetaInboundPayload::changes($payload), 'field'))));

        $event = WebhookEvent::query()->where('connection_id', $connection->id)->where('payload_hash', $hash)->first();
        $dispatch = false;

        if ($event === null) {
            try {
                $event = WebhookEvent::query()->create([
                    'connection_id' => $connection->id,
                    'payload_hash' => $hash,
                    'fields' => $fields === '' ? null : Str::limit($fields, 191, ''),
                    'payload' => $body,
                    'status' => WebhookEventStatus::Received,
                    'received_at' => now(),
                ]);
                $dispatch = true;
            } catch (UniqueConstraintViolationException) {
                $event = WebhookEvent::query()->where('connection_id', $connection->id)->where('payload_hash', $hash)->firstOrFail();
            }
        } elseif ($event->status === WebhookEventStatus::Failed) {
            $event->forceFill(['status' => WebhookEventStatus::Received])->save();
            $dispatch = true;
        } elseif ($event->status === WebhookEventStatus::Received && $event->updated_at?->lessThan(now()->subSeconds(self::STALE_AFTER_SECONDS))) {
            $event->touch();
            $dispatch = true;
        }

        $this->monitor->webhookReceived($connection);

        if ($dispatch) {
            ProcessWhatsAppWebhook::dispatch($event->id);
        }

        return $event;
    }
}
