<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\PutConversationOnHold;
use App\Actions\Conversations\ReopenConversation;
use App\Actions\Conversations\ResumeConversation;
use App\Http\Requests\Conversations\ConversationOperationRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;

class ConversationStatusController extends ConversationOperationController
{
    public function hold(ConversationOperationRequest $request, Conversation $conversation, PutConversationOnHold $hold): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $hold->handle($conversation, $request->user(), $request->reason(), $request->version());

        return $this->done($conversation, $request->user(), 'Conversación puesta en espera.');
    }

    public function resume(ConversationOperationRequest $request, Conversation $conversation, ResumeConversation $resume): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $resume->handle($conversation, $request->user(), $request->version());

        return $this->done($conversation, $request->user(), 'La conversación volvió a estar en atención.');
    }

    public function close(ConversationOperationRequest $request, Conversation $conversation, CloseConversation $close): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $close->handle($conversation, $request->user(), $request->reason(), $request->version());

        return $this->done($conversation, $request->user(), 'Conversación cerrada. Su historial se conserva.');
    }

    public function reopen(ConversationOperationRequest $request, Conversation $conversation, ReopenConversation $reopen): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $reopen->handle($conversation, $request->user(), $request->version());

        return $this->done($conversation, $request->user(), 'Conversación reabierta. La ventana de 24 horas de WhatsApp no cambia.');
    }
}
