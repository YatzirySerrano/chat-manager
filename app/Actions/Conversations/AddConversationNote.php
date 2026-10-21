<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * Adds a private note of the team. Notes are stored apart from messages:
 * they never reach the provider, never count as a reply and never touch
 * the customer service window or the conversation's activity dates.
 */
class AddConversationNote
{
    public function __construct(private ConversationLifecycle $lifecycle) {}

    public function handle(Conversation $conversation, User $actor, string $body): ConversationNote
    {
        return DB::transaction(function () use ($conversation, $actor, $body): ConversationNote {
            $actor = $this->lifecycle->authorize($actor, 'addNote', $conversation);

            $note = new ConversationNote(['body' => $body]);
            $note->forceFill(['conversation_id' => $conversation->id, 'user_id' => $actor->id])->save();

            $this->lifecycle->record($conversation, ConversationEventType::NoteAdded, $actor, [
                'metadata' => ['note_id' => $note->id],
            ]);

            return $note;
        });
    }
}
