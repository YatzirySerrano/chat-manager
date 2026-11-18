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
    /** Metadata key that marks the message as the list of missing information. */
    public const MESSAGE_FLAG = 'missing_information';

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

        // Whoever may mark the request may send the list, also while it waits
        // in the queue: the conversation goes back to «Pendiente» right after,
        // and the delivery job checks this same ability (MESSAGE_FLAG).
        if (Gate::forUser($actor)->denies('confirmReadiness', $conversation)) {
            return match (true) {
                $conversation->isImported() => 'La conversación es historial importado.',
                $conversation->status === ConversationStatus::Closed => 'La conversación está cerrada.',
                default => 'No puedes escribirle al cliente en esta conversación.',
            };
        }

        try {
            $this->queue->handle($conversation, $actor, $this->missingInformation->message($missing), $clientReference, ability: 'confirmReadiness', metadata: [self::MESSAGE_FLAG => true]);
        } catch (ConversationConflict|AuthorizationException $exception) {
            return $exception->getMessage();
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first() ?? 'El mensaje no es válido.';
        }

        return null;
    }
}
