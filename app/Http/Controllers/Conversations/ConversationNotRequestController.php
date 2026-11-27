<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\MarkNotRequest;
use App\Actions\Conversations\UndoNotRequest;
use App\Enums\ConversationStatus;
use App\Http\Requests\Conversations\ConversationOperationRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * «No es solicitud» (a «gracias», an emoji, an «ok» after a finished
 * request) and its «Deshacer», offered in the toast for a few minutes.
 */
class ConversationNotRequestController extends ConversationOperationController
{
    public function store(ConversationOperationRequest $request, Conversation $conversation, MarkNotRequest $mark): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $mark->handle($conversation, $request->user(), $request->version());
        $conversation->refresh();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Marcada como «No es solicitud»: quedó en «Cerradas» sin enviar nada al cliente y no cuenta en los reportes.',
            'action' => [
                'label' => 'Deshacer',
                'href' => route('conversations.not-request.destroy', $conversation),
                'method' => 'delete',
                'data' => ['version' => $conversation->version],
            ],
        ]);

        return $this->back($conversation, $request);
    }

    public function destroy(ConversationOperationRequest $request, Conversation $conversation, UndoNotRequest $undo): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $undo->handle($conversation, $request->user(), $request->version());

        return $this->done($conversation, $request->user(), $conversation->refresh()->status === ConversationStatus::Open
            ? 'Se deshizo «No es solicitud»: la conversación volvió a su agente.'
            : 'Se deshizo «No es solicitud»: la conversación volvió a «Pendientes» con su lugar en la fila.');
    }

    /**
     * Back to the page the user was on (the list, or the closed chat), or to
     * the list when the conversation is no longer within their reach.
     */
    private function back(Conversation $conversation, ConversationOperationRequest $request): RedirectResponse
    {
        return ($request->user()->fresh()?->can('view', $conversation) ?? false)
            ? back()
            : redirect()->to(ConversationController::listUrl());
    }
}
