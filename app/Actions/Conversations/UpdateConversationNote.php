<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Models\ConversationNote;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Corrects a note. Only its author can, and the edition is recorded in the
 * conversation timeline (without the text) so it stays traceable.
 */
class UpdateConversationNote
{
    public function __construct(private ConversationLifecycle $lifecycle) {}

    public function handle(ConversationNote $note, User $actor, string $body): ConversationNote
    {
        return DB::transaction(function () use ($note, $actor, $body): ConversationNote {
            $note = ConversationNote::query()->lockForUpdate()->findOrFail($note->id);
            $actor = $this->lifecycle->freshUser($actor->id, lock: false);

            Gate::forUser($actor)->authorize('update', $note);

            if ($note->body === $body) {
                return $note;
            }

            $note->forceFill(['body' => $body, 'edited_at' => now()])->save();

            $this->lifecycle->record($note->conversation()->firstOrFail(), ConversationEventType::NoteEdited, $actor, [
                'metadata' => ['note_id' => $note->id],
            ]);

            return $note;
        });
    }
}
