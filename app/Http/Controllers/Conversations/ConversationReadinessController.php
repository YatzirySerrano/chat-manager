<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\SetConversationReadiness;
use App\Enums\ConversationReadiness;
use App\Http\Requests\Conversations\ConversationReadinessRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;

/**
 * «Confirmar solicitud completa» / «Marcar que necesita información». The
 * engine authorizes the actor again under lock and records the change.
 */
class ConversationReadinessController extends ConversationOperationController
{
    public function update(ConversationReadinessRequest $request, Conversation $conversation, SetConversationReadiness $setReadiness): RedirectResponse
    {
        $this->ensureVisible($conversation);
        $this->ensurePermitted($request->user(), 'confirmReadiness', $conversation);

        $target = $request->readiness();
        $setReadiness->handle($conversation, $target, $request->user(), $request->reason(), $request->version());

        return $this->done($conversation, $request->user(), $target === ConversationReadiness::Ready
            ? 'Solicitud confirmada como completa. Ya puede tomarse cuando las reglas de la cola lo permitan.'
            : 'La solicitud quedó marcada como «Necesita información».');
    }
}
