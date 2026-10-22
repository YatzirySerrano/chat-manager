<?php

namespace App\Actions\Messaging;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Connection;
use App\Models\Message;
use App\Services\Meta\MetaErrorCatalog;
use App\Services\Meta\ProviderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies Meta's evidence about an outbound message (a "statuses" webhook
 * entry) to the stored message:
 *
 * - only outbound messages of the same connection are touched, matched by
 *   provider id or, when the answer to the send call never arrived, by the
 *   biz_opaque_callback_data echoed back (the message's client reference);
 * - the status only moves forward (accepted → sent → delivered → read);
 *   failed is terminal, except a failure recorded locally as
 *   "delivery_unknown", which Meta's evidence overrides;
 * - events can arrive in any order: a "read" before its "delivered" sets
 *   both moments, and an earlier timestamp reported later replaces a
 *   moment that was only inferred;
 * - repeating an event changes nothing and dispatches nothing;
 * - pricing and conversation blocks are kept as metadata, never as costs.
 */
class RecordMessageStatus
{
    /**
     * Local failure codes that only mean "not confirmed": Meta's evidence wins.
     *
     * @var list<string>
     */
    public const UNCONFIRMED_CODES = ['delivery_unknown'];

    public function handle(Connection $connection, ProviderStatus $status): ?Message
    {
        $next = match ($status->status) {
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'read' => MessageStatus::Read,
            'failed' => MessageStatus::Failed,
            default => null,
        };

        if ($next === null) {
            Log::info('WhatsApp status ignored: not a delivery status.', ['connection_id' => $connection->id, 'status' => Str::limit($status->status, 30)]);

            return null;
        }

        return DB::transaction(function () use ($connection, $status, $next): ?Message {
            $message = $this->find($connection, $status);

            if ($message === null) {
                Log::info('WhatsApp status ignored: no outbound message of this connection matches it.', ['connection_id' => $connection->id, 'message_id' => $status->messageId]);

                return null;
            }

            $previous = $message->status;
            $at = $status->occurredAt;

            if ($message->whatsapp_message_id === null) {
                $message->forceFill(['whatsapp_message_id' => $status->messageId, 'accepted_at' => $message->accepted_at ?? $at]);
            }

            if ($previous === MessageStatus::Failed && in_array($message->error_code, self::UNCONFIRMED_CODES, true) && $next !== MessageStatus::Failed) {
                $message->forceFill(['status' => MessageStatus::Accepted, 'error_code' => null, 'error_message' => null, 'failed_at' => null]);
            }

            match ($next) {
                MessageStatus::Sent => $this->moment($message, 'sent_at', $at),
                MessageStatus::Delivered => $this->moments($message, $at, ['sent_at', 'delivered_at']),
                MessageStatus::Read => $this->moments($message, $at, ['sent_at', 'delivered_at', 'read_at']),
                default => null,
            };

            if ($message->status->canAdvanceTo($next)) {
                $message->status = $next;

                if ($next === MessageStatus::Failed) {
                    $this->fail($message, $status);
                }
            } elseif ($next === MessageStatus::Failed && $message->status !== MessageStatus::Failed) {
                // Already delivered or read: the late failure is kept as evidence only.
                $message->metadata = [...($message->metadata ?? []), 'late_failure' => $this->firstError($status)];
            }

            $message->metadata = $this->withProviderData($message->metadata ?? [], $status);

            if (! $message->isDirty()) {
                return $message;
            }

            $message->save();

            if ($message->status !== $previous) {
                MessageStatusChanged::dispatch($message->id, $message->conversation_id, $message->status->value, $previous->value);
            }

            return $message;
        });
    }

    private function find(Connection $connection, ProviderStatus $status): ?Message
    {
        $scoped = fn () => Message::query()
            ->where('direction', MessageDirection::Outbound)
            ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id))
            ->lockForUpdate();

        $message = $scoped()->where('whatsapp_message_id', $status->messageId)->first();

        if ($message !== null || $status->callbackData === null) {
            return $message;
        }

        // The send call's answer was lost (timeout) or the worker stopped
        // before storing it: Meta's callback data identifies the message.
        $reference = $status->callbackData;
        $query = $scoped()->whereNull('whatsapp_message_id');

        if (preg_match('/^message-(\d+)$/', $reference, $match) === 1) {
            $query->whereKey((int) $match[1])->whereNull('client_reference');
        } elseif (Str::isUuid($reference)) {
            $query->where('client_reference', $reference);
        } else {
            return null;
        }

        return $query
            ->where(fn ($pending) => $pending
                ->whereIn('status', [MessageStatus::Sending, MessageStatus::Queued])
                ->orWhere(fn ($unconfirmed) => $unconfirmed->where('status', MessageStatus::Failed)->whereIn('error_code', self::UNCONFIRMED_CODES)))
            ->first();
    }

    private function moment(Message $message, string $column, CarbonImmutable $at): void
    {
        $current = $message->getAttribute($column);

        if ($current === null || $at->lessThan($current)) {
            $message->setAttribute($column, $at);
        }
    }

    /**
     * A later stage proves the earlier ones happened no later than it.
     *
     * @param  list<string>  $columns
     */
    private function moments(Message $message, CarbonImmutable $at, array $columns): void
    {
        foreach ($columns as $column) {
            $this->moment($message, $column, $at);
        }
    }

    private function fail(Message $message, ProviderStatus $status): void
    {
        $error = $this->firstError($status);
        $code = $error['code'] ?? null;
        $explanation = MetaErrorCatalog::messageFor($code)
            ?? ($error['title'] ?? null)
            ?? 'WhatsApp informó que el mensaje no se pudo entregar.';

        $message->forceFill([
            'error_code' => $code === null ? 'provider_failed' : 'meta_'.$code,
            'error_message' => Str::limit($explanation.($code !== null ? " (código {$code})" : ''), 500, ''),
            'failed_at' => $status->occurredAt,
        ]);
    }

    /**
     * @return array{code: int|null, title: string|null, message: string|null, details: string|null}|null
     */
    private function firstError(ProviderStatus $status): ?array
    {
        return $status->errors[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withProviderData(array $metadata, ProviderStatus $status): array
    {
        $provider = is_array($metadata['provider'] ?? null) ? $metadata['provider'] : [];

        if ($status->conversation !== null) {
            $provider['conversation'] = array_filter([
                'id' => is_string($status->conversation['id'] ?? null) ? $status->conversation['id'] : null,
                'origin_type' => is_string($status->conversation['origin']['type'] ?? null) ? $status->conversation['origin']['type'] : null,
                'expiration_timestamp' => is_scalar($status->conversation['expiration_timestamp'] ?? null) ? (string) $status->conversation['expiration_timestamp'] : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        if ($status->pricing !== null) {
            $provider['pricing'] = array_filter([
                'billable' => is_bool($status->pricing['billable'] ?? null) ? $status->pricing['billable'] : null,
                'pricing_model' => is_string($status->pricing['pricing_model'] ?? null) ? $status->pricing['pricing_model'] : null,
                'category' => is_string($status->pricing['category'] ?? null) ? $status->pricing['category'] : null,
                'type' => is_string($status->pricing['type'] ?? null) ? $status->pricing['type'] : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        if ($status->errors !== []) {
            $provider['errors'] = $status->errors;
        }

        if ($provider === []) {
            return $metadata;
        }

        return [...$metadata, 'provider' => $provider];
    }
}
