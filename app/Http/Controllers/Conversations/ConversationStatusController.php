<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ContinueImportedConversation;
use App\Actions\Conversations\ReopenConversation;
use App\Enums\ConnectionChannel;
use App\Http\Requests\Conversations\CloseConversationRequest;
use App\Http\Requests\Conversations\ConversationOperationRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Messaging\ConnectionModality;
use App\Support\OutcomeText;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ConversationStatusController extends ConversationOperationController
{
    public function close(CloseConversationRequest $request, Conversation $conversation, CloseConversation $close): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $completed = $request->boolean('completed');
        // «Completar sin despedida»: the connection's farewell is skipped.
        $farewell = $request->boolean('farewell', true);

        // Optional credit result («Crédito aprobado» / «Rechazada»), completion only.
        $outcome = $completed ? $request->outcome() : null;

        $close->handle($conversation, $request->user(), $request->reason(), $request->version(), $completed, $farewell, $outcome, $request->outcomeAmount());

        return $this->done($conversation, $request->user(), ($completed
            ? 'Solicitud completada'.($outcome === null ? '' : ' · '.OutcomeText::of($conversation->refresh())).'. Si el cliente vuelve a escribir, entrará como solicitud nueva a «Pendientes».'
            : 'Conversación cerrada. Su historial se conserva.').($farewell ? '' : ' No se envió mensaje de despedida.'));
    }

    public function reopen(ConversationOperationRequest $request, Conversation $conversation, ReopenConversation $reopen): RedirectResponse
    {
        $this->ensureVisible($conversation);

        // Imported history (Whaticket) is read-only: «Reabrir» continues with
        // the customer in a live conversation of the same connection.
        if ($conversation->isImported()) {
            return $this->continueImported($conversation, $request->user());
        }

        $reopen->handle($conversation, $request->user(), $request->version());

        return $this->done($conversation, $request->user(), $this->reopenedMessage($conversation));
    }

    private function continueImported(Conversation $imported, User $actor): RedirectResponse
    {
        ['conversation' => $live, 'outcome' => $outcome] = app(ContinueImportedConversation::class)->handle($imported, $actor);

        Inertia::flash('toast', ['type' => $outcome === 'queued' ? 'warning' : 'success', 'message' => match ($outcome) {
            'existing' => 'Este cliente ya tiene una conversación abierta en esta conexión: te llevamos a ella. El historial importado se conserva de solo consulta.',
            'reopened' => $this->reopenedMessage($live),
            'created' => 'Conversación reabierta con el cliente y asignada a ti. El historial importado de Whaticket se conserva de solo consulta.',
            'queued' => 'Conversación reabierta con el cliente; quedó en «Pendientes» de su departamento porque las reglas de la cola no te la asignan ahora.',
        }]);

        return $actor->can('view', $live)
            ? redirect()->route('conversations.show', $live)
            : redirect()->to(ConversationController::listUrl());
    }

    /**
     * Reopening never extends the 24 hour window; only channels that have
     * one (WhatsApp) mention it.
     */
    private function reopenedMessage(Conversation $conversation): string
    {
        $channel = $conversation->channelConnection()->value('channel');

        if (($channel instanceof ConnectionChannel && ! $channel->usesCustomerServiceWindow()) || app(ConnectionModality::class)->isWhatsAppWeb($conversation)) {
            return 'Conversación reabierta.';
        }

        $label = ($channel instanceof ConnectionChannel ? $channel : ConnectionChannel::Whatsapp)->label();

        return "Conversación reabierta. La ventana de 24 horas de {$label} no cambia.";
    }
}
