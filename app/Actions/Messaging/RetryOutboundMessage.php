<?php

namespace App\Actions\Messaging;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageStatusChanged;
use App\Exceptions\ConversationConflict;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\CustomerServiceWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The responsible agent asks to send a failed message again.
 *
 * Model: the same message row is queued again (its client reference stays,
 * so the composer's idempotency holds); the failed attempt is kept in
 * metadata.delivery.history (error, provider id, moment) and a
 * "message_retried" conversation event records who asked for it.
 *
 * Rules:
 * - only failed outbound messages of this conversation;
 * - "delivery_unknown" means Meta may have accepted the first attempt: the
 *   agent must confirm the risk of a duplicate (confirm flag), and that
 *   decision is also kept in the central audit log;
 * - the conversation's connection must be able to send
 *   (Connection::sendingBlockReason(), the same rule as a new reply);
 * - the conversation rules apply now: the agent must be able to reply, the
 *   window must allow free text (templates are exempt) and a template must
 *   still be approved and the agent hold templates.send (templates are an
 *   administrative send); the delivery job checks them again.
 */
class RetryOutboundMessage
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private CustomerServiceWindow $window,
        private AuditLogger $audit,
    ) {}

    public function handle(Conversation $conversation, Message $message, User $actor, bool $confirmDuplicateRisk = false): Message
    {
        return DB::transaction(function () use ($conversation, $message, $actor, $confirmDuplicateRisk): Message {
            $conversation = $this->lifecycle->lock($conversation);
            $actor = $this->lifecycle->authorize($actor, 'reply', $conversation);
            $message = Message::query()->lockForUpdate()->findOrFail($message->id);

            if ($message->conversation_id !== $conversation->id || $message->direction !== MessageDirection::Outbound) {
                throw new ConversationConflict('El mensaje no pertenece a esta conversación.');
            }

            if ($message->status !== MessageStatus::Failed) {
                throw new ConversationConflict('Solo se pueden reintentar mensajes fallidos.');
            }

            $connectionBlock = $conversation->channelConnection === null
                ? 'La conversación no tiene una conexión disponible para enviar mensajes.'
                : $conversation->channelConnection->sendingBlockReason();

            if ($connectionBlock !== null) {
                throw new ConversationConflict($connectionBlock);
            }

            if ($message->error_code === 'delivery_unknown' && ! $confirmDuplicateRisk) {
                throw ValidationException::withMessages([
                    'confirm' => 'No se pudo confirmar si WhatsApp recibió el primer intento. Confirma que aceptas el riesgo de que el cliente reciba el mensaje dos veces.',
                ]);
            }

            if ($message->type === MessageType::Template) {
                Gate::forUser($actor)->authorize('sendTemplate', $conversation);

                $templateId = $message->metadata['template']['id'] ?? null;
                $template = is_int($templateId) ? MessageTemplate::query()->find($templateId) : null;

                if ($template === null || $template->connection_id !== $conversation->connection_id || ! $template->isUsable()) {
                    throw new ConversationConflict('La plantilla de este mensaje ya no está aprobada por Meta; elige otra plantilla.');
                }
            } else {
                $state = $this->window->for($conversation);

                if (! $state->allowsFreeFormMessages()) {
                    throw new ConversationConflict($state->blockReason ?? 'La ventana de atención no permite reenviar este mensaje; usa una plantilla aprobada.');
                }
            }

            $metadata = $message->metadata ?? [];
            $delivery = is_array($metadata['delivery'] ?? null) ? $metadata['delivery'] : [];
            $history = is_array($delivery['history'] ?? null) ? $delivery['history'] : [];
            $history[] = array_filter([
                'error_code' => $message->error_code,
                'error_message' => $message->error_message,
                'provider_message_id' => $message->whatsapp_message_id,
                'failed_at' => $message->failed_at?->toIso8601String(),
                'retried_by_user_id' => $actor->id,
                'retried_at' => now()->toIso8601String(),
                'duplicate_risk_confirmed' => $message->error_code === 'delivery_unknown' ? true : null,
            ], fn (mixed $value): bool => $value !== null);

            $code = $message->error_code;
            unset($delivery['unconfirmed'], $delivery['unconfirmed_at']);

            $message->forceFill([
                'status' => MessageStatus::Queued,
                'whatsapp_message_id' => null,
                'error_code' => null,
                'error_message' => null,
                'failed_at' => null,
                'accepted_at' => null,
                'sending_started_at' => null,
                'sent_at' => null,
                'delivered_at' => null,
                'read_at' => null,
                'metadata' => [...$metadata, 'delivery' => [...$delivery, 'history' => array_slice($history, -10), 'retry_base' => $message->delivery_attempts, 'retries' => (int) ($delivery['retries'] ?? count($history) - 1) + 1]],
            ])->save();

            $this->lifecycle->record($conversation, ConversationEventType::MessageRetried, $actor, [
                'metadata' => ['message_id' => $message->id, 'previous_error' => $code],
            ]);

            if ($code === 'delivery_unknown') {
                $this->audit->record(AuditAction::MessageRetriedWithDuplicateRisk, $conversation, [
                    'message_id' => $message->id,
                    'previous_error' => $code,
                    'duplicate_risk_confirmed' => true,
                ], $actor);
            }

            MessageStatusChanged::dispatch($message->id, $conversation->id, MessageStatus::Queued->value, MessageStatus::Failed->value);
            DeliverOutboundMessage::dispatch($message->id)->afterCommit();

            return $message;
        });
    }
}
