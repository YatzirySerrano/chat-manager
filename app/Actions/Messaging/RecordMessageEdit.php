<?php

namespace App\Actions\Messaging;

use App\Events\Conversations\ConversationChanged;
use App\Models\Connection;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The sender edited a message they had already sent: the stored message
 * takes the new text and is marked as edited, instead of a second message
 * appearing in the conversation.
 *
 * - only a message of the same connection is touched, found by its
 *   provider id; an edit of a message that was never stored changes nothing;
 * - the texts it had before are kept (metadata.edits, the latest ten), so
 *   the conversation stays a record of what was said;
 * - edits can arrive repeated or out of order: one that is not newer than
 *   the stored one changes nothing and dispatches nothing;
 * - a message its sender deleted is not edited any more.
 *
 * The open conversation refreshes that one message (ConversationChanged
 * "message"); no notification, unread counter or automatic message follows
 * an edit.
 */
class RecordMessageEdit
{
    /** Earlier texts kept per message. */
    private const KEPT_EDITS = 10;

    public function handle(Connection $connection, string $storedMessageId, ?string $text, CarbonImmutable $editedAt): ?Message
    {
        $text = $text === null ? null : trim($text);
        $text = $text === null || $text === '' ? null : Str::limit($text, 4096, '');

        return DB::transaction(function () use ($connection, $storedMessageId, $text, $editedAt): ?Message {
            $message = Message::query()
                ->where('whatsapp_message_id', $storedMessageId)
                ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id))
                ->lockForUpdate()
                ->first();

            if ($message === null || $message->revoked_at !== null) {
                return null;
            }

            if ($message->edited_at !== null && $editedAt->lessThanOrEqualTo($message->edited_at)) {
                return null;
            }

            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $content = is_array($metadata['content'] ?? null) ? $metadata['content'] : [];

            if ($text !== $message->body) {
                $edits = is_array($metadata['edits'] ?? null) ? array_values($metadata['edits']) : [];
                $edits[] = ['body' => $message->body, 'until' => $editedAt->toIso8601String()];
                $metadata['edits'] = array_slice($edits, -self::KEPT_EDITS);
            }

            // The text lives in "body" for a text and in "caption" for a file.
            if (array_key_exists('caption', $content) || array_key_exists('source', $content)) {
                $content['caption'] = $text;
                $metadata['content'] = array_filter($content, fn (mixed $value): bool => $value !== null);
            } elseif (array_key_exists('body', $content) || $content === []) {
                $metadata['content'] = $text === null ? null : [...$content, 'body' => $text];
            }

            $message->forceFill([
                'body' => $text,
                'metadata' => array_filter($metadata, fn (mixed $value): bool => $value !== null),
                'edited_at' => $editedAt,
            ])->save();

            ConversationChanged::dispatch($message->conversation_id, 'message', messageId: $message->id);

            return $message;
        });
    }
}
