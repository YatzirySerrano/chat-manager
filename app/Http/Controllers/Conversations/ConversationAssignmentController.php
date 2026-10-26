<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AssignConversation;
use App\Actions\Conversations\ReleaseConversation;
use App\Enums\ConversationConflict as ConversationConflictReason;
use App\Exceptions\ConversationStateConflict;
use App\Http\Requests\Conversations\AssignConversationRequest;
use App\Http\Requests\Conversations\ConversationOperationRequest;
use App\Models\Conversation;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationStateGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ConversationAssignmentController extends ConversationOperationController
{
    /**
     * Take a pending conversation from the queue and open it.
     */
    public function accept(Request $request, Conversation $conversation, AcceptConversation $accept, ConversationAccess $access, ConversationStateGuard $state): RedirectResponse
    {
        // Whoever could take conversations from this queue learns that a
        // colleague was faster; anyone else gets the usual "not found".
        if (! Gate::allows('view', $conversation)) {
            abort_unless(! $conversation->isHistoryOnly() && in_array($conversation->department_id, $access->acceptableDepartmentIds($request->user()), true), 404);

            throw ConversationStateConflict::because($state->conflict($request->user(), 'accept', $conversation) ?? ConversationConflictReason::StateChanged);
        }

        $accept->handle($conversation, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aceptaste la conversación. Ahora eres responsable de atenderla.']);

        // From the "Pendientes" chip the row would leave the list: the general
        // inbox keeps it in place, now in attention (listUrlAfterAccepting()).
        $afterAccepting = ConversationController::listUrlAfterAccepting($conversation);

        return $afterAccepting === null
            ? redirect()->route('conversations.show', $conversation)
            : redirect()->to($afterAccepting);
    }

    public function assign(AssignConversationRequest $request, Conversation $conversation, AssignConversation $assign): RedirectResponse
    {
        $this->ensureVisible($conversation);
        $this->ensurePermitted($request->user(), 'assign', $conversation);

        $agent = $this->destinationAgent($request->integer('user_id'), $request->user(), $conversation->department);

        $assign->handle($conversation, $agent, $request->user(), $request->version(), $request->confirmsOverCapacity());

        return $this->done($conversation, $request->user(), "Conversación asignada a {$agent->name}.");
    }

    /**
     * Return the conversation to its department queue.
     */
    public function release(ConversationOperationRequest $request, Conversation $conversation, ReleaseConversation $release): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $release->handle($conversation, $request->user(), $request->reason(), $request->version());

        return $this->done($conversation, $request->user(), 'La conversación volvió a «Pendientes» de su departamento.');
    }
}
