<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ReopenConversation;
use App\Enums\ConnectionChannel;
use App\Http\Requests\Conversations\ConversationOperationRequest;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;

class ConversationStatusController extends ConversationOperationController
{
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

        return $this->done($conversation, $request->user(), $this->reopenedMessage($conversation));
    }

    /**
     * Reopening never extends the 24 hour window; only channels that have
     * one (WhatsApp) mention it.
     */
    private function reopenedMessage(Conversation $conversation): string
    {
        $channel = $conversation->channelConnection()->value('channel');

        if ($channel instanceof ConnectionChannel && ! $channel->usesCustomerServiceWindow()) {
            return 'Conversación reabierta.';
        }

        $label = ($channel instanceof ConnectionChannel ? $channel : ConnectionChannel::Whatsapp)->label();

        return "Conversación reabierta. La ventana de 24 horas de {$label} no cambia.";
    }
}
