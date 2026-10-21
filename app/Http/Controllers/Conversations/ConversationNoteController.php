<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\AddConversationNote;
use App\Actions\Conversations\UpdateConversationNote;
use App\Http\Requests\Conversations\ConversationNoteRequest;
use App\Models\Conversation;
use App\Models\ConversationNote;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ConversationNoteController extends ConversationOperationController
{
    public function store(ConversationNoteRequest $request, Conversation $conversation, AddConversationNote $addNote): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $addNote->handle($conversation, $request->user(), $request->string('body')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nota interna agregada. Solo la ve el equipo.']);

        return back();
    }

    public function update(ConversationNoteRequest $request, Conversation $conversation, ConversationNote $note, UpdateConversationNote $updateNote): RedirectResponse
    {
        $this->ensureVisible($conversation);
        abort_unless($note->conversation_id === $conversation->id, 404);

        $updateNote->handle($note, $request->user(), $request->string('body')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nota actualizada.']);

        return back();
    }
}
