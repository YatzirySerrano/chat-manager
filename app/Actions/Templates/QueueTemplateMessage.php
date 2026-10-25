<?php

namespace App\Actions\Templates;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageRecorded;
use App\Exceptions\ConversationConflict;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The responsible agent, holding the administrative templates.send
 * permission (checked again under lock, ConversationPolicy::sendTemplate()),
 * sends an approved WhatsApp template. Unlike free
 * text, a template may be sent while the customer service window is closed
 * (Meta's rule for starting or resuming a conversation). It is accepted
 * only when Meta reports it APPROVED for the conversation's own connection
 * and every variable passes Meta's rules; the rendered text is stored as the
 * message body so the history shows what the customer received. The
 * delivery job checks the template status again right before sending.
 */
class QueueTemplateMessage
{
    public function __construct(private ConversationLifecycle $lifecycle) {}

    /**
     * @param  array<string, mixed>  $values  Variables keyed "header.1", "body.name", "button.0.1", "header.media".
     */
    public function handle(Conversation $conversation, User $actor, MessageTemplate $template, array $values, string $clientReference): Message
    {
        return DB::transaction(function () use ($conversation, $actor, $template, $values, $clientReference): Message {
            $conversation = $this->lifecycle->lock($conversation);
            $actor = $this->lifecycle->authorize($actor, 'sendTemplate', $conversation);

            $existing = Message::query()->where('client_reference', $clientReference)->first();

            if ($existing !== null) {
                if ($existing->conversation_id !== $conversation->id) {
                    throw new ConversationConflict('La referencia del mensaje ya se usó en otra conversación.');
                }

                return $existing;
            }

            // Same rule as a free-form reply (QueueOutboundMessage); checked again by the delivery job.
            $connection = $conversation->channelConnection;
            $connectionBlock = match (true) {
                $connection === null => 'La conversación no tiene una conexión disponible para enviar mensajes.',
                ! $connection->channel->supportsTemplates() => "Las plantillas no están disponibles en las conversaciones de {$connection->channel->label()}.",
                default => $connection->sendingBlockReason(),
            };

            if ($connectionBlock !== null) {
                throw new ConversationConflict($connectionBlock);
            }

            $template = MessageTemplate::query()->whereKey($template->id)->sharedLock()->firstOrFail();

            if ($template->connection_id !== $conversation->connection_id) {
                throw ValidationException::withMessages(['template_id' => 'La plantilla no pertenece a la conexión de esta conversación.']);
            }

            if (! $template->isUsable()) {
                throw ValidationException::withMessages(['template_id' => $template->structure()->unsupportedReason()
                    ?? "La plantilla «{$template->name}» no está aprobada por Meta (estado: {$template->status->label()})."]);
            }

            $structure = $template->structure();
            $errors = $structure->errors($values);

            if ($errors !== []) {
                throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $error, string $key): array => ["parameters.{$key}" => $error])->all());
            }

            $values = array_map(fn (mixed $value): string => trim((string) $value), $values);

            $message = new Message([
                'conversation_id' => $conversation->id,
                'client_reference' => $clientReference,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Template,
                'status' => MessageStatus::Queued,
                'body' => mb_substr($structure->preview($values), 0, 4096),
                'sender_user_id' => $actor->id,
                'metadata' => ['template' => [
                    'id' => $template->id,
                    'meta_template_id' => $template->meta_template_id,
                    'name' => $template->name,
                    'language' => $template->language,
                    'category' => $template->category,
                    'parameters' => $values,
                    'components' => $structure->sendComponents($values),
                ]],
            ]);

            try {
                $message->save();
            } catch (UniqueConstraintViolationException) {
                throw new ConversationConflict('La referencia del mensaje ya se usó en otra conversación.');
            }

            $conversation->forceFill([
                'last_outbound_at' => now(),
                'last_message_at' => $conversation->last_message_at === null || $conversation->last_message_at->lessThan(now()) ? now() : $conversation->last_message_at,
                'first_response_at' => $conversation->first_response_at ?? now(),
            ])->save();

            DeliverOutboundMessage::dispatch($message->id)->afterCommit();
            MessageRecorded::dispatch($message->id, $conversation->id, MessageDirection::Outbound->value);

            return $message;
        });
    }
}
