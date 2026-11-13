<?php

namespace App\Actions\Messaging;

use App\Events\Conversations\ConversationChanged;
use App\Models\Connection;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The sender deleted a message for everyone ("Eliminar para todos"): the
 * stored message is marked as deleted by its sender (revoked_at) and stays
 * where it was. Nothing is erased: its text, its files and its place in
 * the conversation are kept, because the conversation is the record of
 * what the customer and the business said; the interface decides how a
 * deleted message is shown.
 *
 * Only a message of the same connection is touched, found by its provider
 * id; repeating the event changes nothing and dispatches nothing. The open
 * conversation refreshes that one message (ConversationChanged "message").
 */
class RecordMessageRevocation
{
    public function handle(Connection $connection, string $storedMessageId, CarbonImmutable $revokedAt): ?Message
    {
        return DB::transaction(function () use ($connection, $storedMessageId, $revokedAt): ?Message {
            $message = Message::query()
                ->where('whatsapp_message_id', $storedMessageId)
                ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id))
                ->lockForUpdate()
                ->first();

            if ($message === null || $message->revoked_at !== null) {
                return null;
            }

            $message->forceFill(['revoked_at' => $revokedAt])->save();

            ConversationChanged::dispatch($message->conversation_id, 'message', messageId: $message->id);

            return $message;
        });
    }
}
