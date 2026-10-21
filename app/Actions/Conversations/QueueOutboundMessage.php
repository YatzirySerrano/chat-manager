<?php

namespace App\Actions\Conversations;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Exceptions\ConversationConflict;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\CustomerServiceWindow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The responsible agent writes an ordinary text reply. It is accepted only
 * while the customer service window is open, stored once per client
 * reference (double submissions are harmless) and handed to the delivery
 * job, which checks the window again right before calling the provider.
 * Templates cannot be sent from here. Replies never extend the window.
 */
class QueueOutboundMessage
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private CustomerServiceWindow $window,
    ) {}

    public function handle(Conversation $conversation, User $actor, string $body, string $clientReference): Message
    {
        return DB::transaction(function () use ($conversation, $actor, $body, $clientReference): Message {
            $conversation = $this->lifecycle->lock($conversation);
            $actor = $this->lifecycle->authorize($actor, 'reply', $conversation);

            $existing = Message::query()->where('client_reference', $clientReference)->first();

            if ($existing !== null) {
                if ($existing->conversation_id !== $conversation->id) {
                    throw new ConversationConflict('La referencia del mensaje ya se usó en otra conversación.');
                }

                return $existing;
            }

            $window = $this->window->for($conversation);

            if (! $window->allowsFreeFormMessages()) {
                throw new ConversationConflict($window->blockReason ?? 'La ventana de atención no permite enviar mensajes.');
            }

            $message = new Message([
                'conversation_id' => $conversation->id,
                'client_reference' => $clientReference,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Text,
                'status' => MessageStatus::Queued,
                'body' => $body,
                'sender_user_id' => $actor->id,
            ]);

            try {
                $message->save();
            } catch (UniqueConstraintViolationException) {
                // Another conversation stored the same reference meanwhile.
                throw new ConversationConflict('La referencia del mensaje ya se usó en otra conversación.');
            }

            $conversation->forceFill([
                'last_outbound_at' => now(),
                'last_message_at' => $conversation->last_message_at === null || $conversation->last_message_at->lessThan(now()) ? now() : $conversation->last_message_at,
                'first_response_at' => $conversation->first_response_at ?? now(),
            ])->save();

            DeliverOutboundMessage::dispatch($message->id)->afterCommit();

            return $message;
        });
    }
}
