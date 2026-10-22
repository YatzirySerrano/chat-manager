<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\AddConversationNote;
use App\Actions\Conversations\UpdateConversationNote;
use App\Http\Requests\Conversations\ConversationNoteRequest;
use App\Http\Resources\ConversationNoteResource;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Support\SaveToast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ConversationNoteController extends ConversationOperationController
{
    /**
     * The inbox composer (note mode) asks for JSON to show the note at once;
     * other forms get the usual redirect with a toast.
     */
    public function store(ConversationNoteRequest $request, Conversation $conversation, AddConversationNote $addNote): RedirectResponse|JsonResponse
    {
        $this->ensureVisible($conversation);

        $note = $addNote->handle($conversation, $request->user(), $request->string('body')->value());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            $note->load('author');

            return (new ConversationNoteResource($note))->response()->setStatusCode(201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nota interna agregada. Solo la ve el equipo.']);

        return back();
    }

    public function update(ConversationNoteRequest $request, Conversation $conversation, ConversationNote $note, UpdateConversationNote $updateNote): RedirectResponse
    {
        $this->ensureVisible($conversation);
        abort_unless($note->conversation_id === $conversation->id, 404);

        $changed = $updateNote->handle($note, $request->user(), $request->string('body')->value());

        SaveToast::flash($changed, 'Nota actualizada.');

        return back();
    }
}
