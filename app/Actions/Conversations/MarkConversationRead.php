<?php

namespace App\Actions\Conversations;

use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Moves the viewer's read marker of a conversation forward, up to the given
 * message (or the latest one). The marker never goes back: a late request
 * from another tab cannot turn read messages into unread ones. Ids that do
 * not belong to the conversation are clamped to its own messages.
 */
class MarkConversationRead
{
    /**
     * @return bool Whether the marker moved.
     */
    public function handle(Conversation $conversation, User $viewer, ?int $upToMessageId = null): bool
    {
        $latest = Message::query()
            ->where('conversation_id', $conversation->id)
            ->when($upToMessageId !== null, fn ($query) => $query->where('id', '<=', $upToMessageId))
            ->max('id');

        if ($latest === null) {
            return false;
        }

        $latest = (int) $latest;

        $moved = $this->advance($conversation, $viewer, $latest);

        if ($moved) {
            ConversationChanged::dispatch($conversation->id, 'read', $viewer->id);
        }

        return $moved;
    }

    private function advance(Conversation $conversation, User $viewer, int $messageId): bool
    {
        try {
            return DB::transaction(function () use ($conversation, $viewer, $messageId): bool {
                $read = ConversationRead::query()
                    ->where('user_id', $viewer->id)
                    ->where('conversation_id', $conversation->id)
                    ->lockForUpdate()
                    ->first();

                if ($read === null) {
                    $read = new ConversationRead;
                    $read->forceFill(['user_id' => $viewer->id, 'conversation_id' => $conversation->id]);
                } elseif ($read->last_read_message_id !== null && $read->last_read_message_id >= $messageId) {
                    return false;
                }

                $read->forceFill(['last_read_message_id' => $messageId, 'read_at' => now()])->save();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Another tab created the marker at the same moment: move that one.
            return $this->advance($conversation, $viewer, $messageId);
        }
    }
}
