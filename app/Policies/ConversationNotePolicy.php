<?php

namespace App\Policies;

use App\Models\ConversationNote;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ConversationNotePolicy
{
    /**
     * A note is edited only by its author, while they can still write notes
     * on the conversation. Nobody edits a colleague's note.
     */
    public function update(User $actor, ConversationNote $note): bool
    {
        return $note->user_id === $actor->id
            && Gate::forUser($actor)->allows('addNote', $note->conversation()->firstOrFail());
    }
}
