<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\SendMissingInformation;
use App\Actions\Conversations\SetConversationReadiness;
use App\Enums\ConversationReadiness;
use App\Http\Requests\Conversations\ConversationReadinessRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;

/**
 * «Confirmar solicitud completa» / «Marcar que necesita información». The
 * engine authorizes the actor again under lock and records the change.
 * Marking that information is missing may also send the customer the list
 * (SendMissingInformation): a message that cannot be sent never undoes the
 * change, the toast says why it was not sent. A conversation in attention
 * then goes back to «Pendiente» in its queue (SetConversationReadiness).
 */
class ConversationReadinessController extends ConversationOperationController
{
    public function update(ConversationReadinessRequest $request, Conversation $conversation, SetConversationReadiness $setReadiness, SendMissingInformation $sendMissing): RedirectResponse
    {
        $this->ensureVisible($conversation);
        $this->ensurePermitted($request->user(), 'confirmReadiness', $conversation);

        $target = $request->readiness();
        $setReadiness->handle($conversation, $target, $request->user(), $request->reason(), $request->version(), listed: $request->missingList() !== [], returnToQueue: ! $request->sendsMessage());

        if ($target === ConversationReadiness::Ready) {
            return $this->done($conversation, $request->user(), 'Solicitud confirmada como completa. Ya puede tomarse cuando las reglas de la cola lo permitan.');
        }

        if (! $request->sendsMessage()) {
            return $this->done($conversation, $request->user(), 'La solicitud quedó marcada como «Necesita información».');
        }

        $notSent = $sendMissing->handle($conversation, $request->user(), $request->missingList(), $request->clientReference());

        // Sent (or not) while still in attention; now it waits as pending.
        $setReadiness->returnToQueue($conversation, $request->user());

        return $notSent === null
            ? $this->done($conversation, $request->user(), 'La solicitud quedó marcada como «Necesita información» y se envió al cliente lo que falta.')
            : $this->done($conversation, $request->user(), 'La solicitud quedó marcada como «Necesita información», pero el mensaje al cliente no se envió. '.$notSent, 'warning');
    }
}
