<?php

namespace App\Actions\Messaging;

use App\Events\Conversations\ConversationChanged;
use App\Models\Connection;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A reaction (an emoji on a message) is kept on the message it reacts to,
 * not as a message of its own: metadata.reactions holds at most one per
 * side of a one-to-one chat, "customer" and "business" (a reaction made on
 * the linked phone). A new emoji of the same side replaces the earlier one
 * and an empty one removes it, exactly as WhatsApp shows it.
 *
 * Only a message of the same connection is touched, found by its provider
 * id; a reaction to a message that was never stored changes nothing, and
 * neither does one older than the reaction already kept. With $signal the
 * open conversation refreshes that one message; a reaction never notifies,
 * counts as unread or moves the conversation.
 */
class RecordMessageReaction
{
    public const CUSTOMER = 'customer';

    public const BUSINESS = 'business';

    public function handle(Connection $connection, string $storedMessageId, bool $fromMe, string $emoji, CarbonImmutable $at, bool $signal = true): ?Message
    {
        return DB::transaction(function () use ($connection, $storedMessageId, $fromMe, $emoji, $at, $signal): ?Message {
            $message = Message::query()
                ->where('whatsapp_message_id', $storedMessageId)
                ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id))
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return null;
            }

            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $reactions = is_array($metadata['reactions'] ?? null) ? $metadata['reactions'] : [];
            $side = $fromMe ? self::BUSINESS : self::CUSTOMER;
            $current = is_array($reactions[$side] ?? null) ? $reactions[$side] : null;

            if ($current !== null && is_string($current['at'] ?? null) && $at->lessThan(CarbonImmutable::parse($current['at']))) {
                return null;
            }

            // Nothing to remove, or the same emoji again (a repeated event).
            if (($current['emoji'] ?? null) === ($emoji === '' ? null : $emoji)) {
                return null;
            }

            // A removal keeps its moment (emoji null), so an older reaction
            // delivered late does not bring it back.
            $reactions[$side] = ['emoji' => $emoji === '' ? null : $emoji, 'at' => $at->toIso8601String()];
            $metadata['reactions'] = $reactions;

            // updated_at moves: it is the version the chat compares before repainting a message.
            $message->forceFill(['metadata' => $metadata])->save();

            if ($signal) {
                ConversationChanged::dispatch($message->conversation_id, 'message', messageId: $message->id);
            }

            return $message;
        });
    }

    /**
     * The reactions of a message as the chat shows them.
     *
     * @param  array<array-key, mixed>|null  $metadata
     * @return list<array{emoji: string, from_me: bool}>
     */
    public static function of(?array $metadata): array
    {
        $reactions = is_array($metadata['reactions'] ?? null) ? $metadata['reactions'] : [];
        $shown = [];

        foreach ([self::CUSTOMER => false, self::BUSINESS => true] as $side => $fromMe) {
            $emoji = is_array($reactions[$side] ?? null) ? ($reactions[$side]['emoji'] ?? null) : null;

            if (is_string($emoji) && $emoji !== '') {
                $shown[] = ['emoji' => $emoji, 'from_me' => $fromMe];
            }
        }

        return $shown;
    }
}
