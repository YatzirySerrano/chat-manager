<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\MissingInformation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * After «Marcar que necesita información», the list of what is missing is
 * sent to the customer as an ordinary reply of the acting agent, through
 * the same pipeline as the composer (QueueOutboundMessage: who may reply,
 * the connection, the customer service window, idempotency by client
 * reference).
 *
 * Never fails the readiness change: when the message cannot be sent now
 * (pending or someone else's conversation, connection blocked, window
 * closed, imported history) nothing is sent and the explanation is returned
 * for the agent.
 */
class SendMissingInformation
{
    public function __construct(
        private QueueOutboundMessage $queue,
        private MissingInformation $missingInformation,
    ) {}

    /**
     * @param  list<string>  $missing
     * @return string|null Why the message was not sent; null once queued.
     */
    public function handle(Conversation $conversation, User $actor, array $missing, string $clientReference): ?string
    {
        $conversation->refresh();

        if (Gate::forUser($actor)->denies('reply', $conversation)) {
            return match (true) {
                $conversation->isImported() => 'La conversación es historial importado.',
                $conversation->status === ConversationStatus::Closed => 'La conversación está cerrada.',
                $conversation->status === ConversationStatus::Pending => 'La conversación todavía no está en atención: solo quien la atiende puede escribirle al cliente.',
                default => 'Solo quien atiende la conversación puede escribirle al cliente.',
            };
        }

        try {
            $this->queue->handle($conversation, $actor, $this->missingInformation->message($missing), $clientReference);
        } catch (ConversationConflict|AuthorizationException $exception) {
            return $exception->getMessage();
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first() ?? 'El mensaje no es válido.';
        }

        return null;
    }
}
