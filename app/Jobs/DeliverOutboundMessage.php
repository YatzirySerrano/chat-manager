<?php

namespace App\Jobs;

use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaErrorCatalog;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\SendResult;
use App\Services\Meta\WhatsAppTransport;
use App\Services\Meta\WhatsAppTransportUnavailable;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers one queued outbound message (text, media, template) to WhatsApp.
 *
 * 1. Claim: under the message row lock, a "queued" message becomes
 *    "sending". A second worker holding the same job finds it claimed and
 *    does nothing, so one message is never sent twice by two workers.
 *    Right before claiming, the connection must still be operational, the
 *    human author must still be allowed to reply (ConversationPolicy::reply;
 *    automatic messages have no author), the customer service window must
 *    allow free-form messages (templates are exempt) and a template must
 *    still be approved; otherwise the message
 *    fails without reaching Meta.
 * 2. Send, outside any transaction.
 * 3. Record: Meta's message id makes it "accepted" (never "delivered": only
 *    status webhooks prove that).
 *
 * Failures: errors proven not to have reached Meta (connection refused,
 * DNS, connect timeout) or explicit throttling/temporary errors put the
 * message back in the queue with bounded backoff. A refusal fails it with a
 * Spanish explanation. An ambiguous outcome (the request may have been
 * processed) leaves it "sending": a status webhook carrying its callback
 * data reconciles it, otherwise `whatsapp:maintenance reconcile-deliveries` marks it
 * failed as "delivery_unknown". It is never re-sent automatically.
 */
class DeliverOutboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 90;

    public function __construct(public int $messageId)
    {
        $this->onQueue(Queues::name(Queues::MESSAGES));
        $this->tries = self::maxAttempts() + 1;
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('whatsapp.delivery.max_attempts', 5));
    }

    /**
     * Provider calls since the message was last queued by an agent (a manual
     * retry starts a new cycle of automatic attempts).
     */
    public static function attemptsInCycle(Message $message): int
    {
        $base = $message->metadata['delivery']['retry_base'] ?? 0;

        return $message->delivery_attempts - (is_int($base) ? $base : 0);
    }

    public function handle(CustomerServiceWindow $window, ConversationLifecycle $lifecycle, WhatsAppTransport $transport, ConnectionMonitor $monitor): void
    {
        $message = $this->claim($window, $lifecycle);

        if ($message === null) {
            return;
        }

        try {
            $result = $transport->send($message);
        } catch (WhatsAppTransportUnavailable $exception) {
            $this->failMessage($lifecycle, $message, 'transport_unavailable', $exception->getMessage());

            return;
        } catch (MetaGraphException $exception) {
            $this->handleProviderFailure($lifecycle, $monitor, $message, $exception);

            return;
        } catch (Throwable $exception) {
            // Unknown outcome: the request may have left. Leave it for reconciliation.
            Log::error('Outbound WhatsApp message ended with an unexpected error; left for reconciliation.', [
                'message_id' => $message->id,
                'exception' => $exception::class,
            ]);
            $this->leaveUnconfirmed($message, 'Ocurrió un error inesperado durante el envío; se espera la confirmación de WhatsApp.');

            return;
        }

        $this->accept($message, $result, $monitor);
    }

    /**
     * After every attempt of the queue failed (unexpected errors before the
     * claim or a killed worker): a message still queued fails explicitly; a
     * message already sending is left for reconciliation.
     */
    public function failed(?Throwable $exception): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message !== null && $message->status === MessageStatus::Queued) {
            $this->failMessage(app(ConversationLifecycle::class), $message, 'delivery_error', 'No fue posible procesar el envío del mensaje.');
        }
    }

    private function claim(CustomerServiceWindow $window, ConversationLifecycle $lifecycle): ?Message
    {
        return DB::transaction(function () use ($window, $lifecycle): ?Message {
            $message = Message::query()->lockForUpdate()->find($this->messageId);

            if ($message === null || $message->status !== MessageStatus::Queued || $message->direction !== MessageDirection::Outbound) {
                return null;
            }

            $conversation = Conversation::query()->with('channelConnection')->findOrFail($message->conversation_id);

            // Nothing leaves through a connection that was deleted or deactivated
            // after the message was queued.
            if (! ($conversation->channelConnection?->isOperational() ?? false)) {
                $this->block($lifecycle, $conversation, $message, 'connection_unavailable', 'La conexión de WhatsApp de esta conversación no está disponible.');

                return null;
            }

            // The person who queued (or last retried) a reply must still be
            // able to reply now: the conversation may have been transferred,
            // closed or paused, or the account deactivated, while it waited.
            if (! $this->senderMayStillReply($message, $conversation)) {
                $this->block($lifecycle, $conversation, $message, 'sender_not_allowed', 'Quien escribió el mensaje ya no puede responder esta conversación (cambió de responsable, se cerró o se pausó, o la cuenta perdió el permiso); no se envió.');

                return null;
            }

            if ($message->type === MessageType::Template) {
                $reason = $this->templateRefusal($message, $conversation);

                if ($reason !== null) {
                    $this->block($lifecycle, $conversation, $message, 'template_unavailable', $reason);

                    return null;
                }
            } else {
                $state = $window->for($conversation);

                if (! $state->allowsFreeFormMessages()) {
                    $this->block($lifecycle, $conversation, $message, 'customer_window_closed', $state->blockReason ?? 'La ventana de atención venció.');

                    return null;
                }
            }

            $message->forceFill([
                'status' => MessageStatus::Sending,
                'sending_started_at' => now(),
                'delivery_attempts' => $message->delivery_attempts + 1,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            MessageStatusChanged::dispatch($message->id, $message->conversation_id, MessageStatus::Sending->value, MessageStatus::Queued->value);

            return $message;
        });
    }

    /**
     * Messages without a human author (automatic system messages) are exempt;
     * a manual retry makes the person who retried it the author.
     */
    private function senderMayStillReply(Message $message, Conversation $conversation): bool
    {
        $history = $message->metadata['delivery']['history'] ?? null;
        $lastRetry = is_array($history) && $history !== [] ? end($history) : null;
        $senderId = is_array($lastRetry) && is_int($lastRetry['retried_by_user_id'] ?? null)
            ? $lastRetry['retried_by_user_id']
            : $message->sender_user_id;

        if ($senderId === null) {
            return true;
        }

        $sender = User::query()->with(['roles', 'departments'])->find($senderId);

        return $sender !== null && Gate::forUser($sender)->allows('reply', $conversation);
    }

    private function templateRefusal(Message $message, Conversation $conversation): ?string
    {
        $templateId = $message->metadata['template']['id'] ?? null;
        $template = is_int($templateId) ? MessageTemplate::query()->find($templateId) : null;

        if ($template === null || $template->connection_id !== $conversation->connection_id) {
            return 'La plantilla del mensaje ya no existe para esta conexión.';
        }

        if (! $template->isUsable()) {
            return "La plantilla «{$template->name}» ya no está aprobada por Meta (estado: {$template->status->label()}).";
        }

        return null;
    }

    private function accept(Message $message, SendResult $result, ConnectionMonitor $monitor): void
    {
        $connection = DB::transaction(function () use ($message, $result) {
            $current = Message::query()->with('conversation.channelConnection')->lockForUpdate()->findOrFail($message->id);
            $previous = $current->status;

            // A status webhook may have reconciled the message first (callback data).
            $current->forceFill([
                'whatsapp_message_id' => $current->whatsapp_message_id ?? $result->providerMessageId,
                'accepted_at' => $current->accepted_at ?? now(),
            ]);

            if ($current->status === MessageStatus::Sending || ($current->status === MessageStatus::Failed && $current->error_code === 'delivery_unknown')) {
                $current->forceFill(['status' => MessageStatus::Accepted, 'error_code' => null, 'error_message' => null, 'failed_at' => null]);
            }

            if ($result->simulated) {
                $current->metadata = [...($current->metadata ?? []), 'simulated' => true];
            }

            $current->save();

            if ($current->status !== $previous) {
                MessageStatusChanged::dispatch($current->id, $current->conversation_id, $current->status->value, $previous->value);
            }

            return $current->conversation->channelConnection;
        });

        if ($connection !== null && ! $result->simulated) {
            $monitor->outboundAccepted($connection);
        }
    }

    private function handleProviderFailure(ConversationLifecycle $lifecycle, ConnectionMonitor $monitor, Message $message, MetaGraphException $exception): void
    {
        if ($exception->isRetryable()) {
            if (self::attemptsInCycle($message) < self::maxAttempts()) {
                $this->requeue($message, $exception);

                return;
            }

            $code = match ($exception->kind) {
                MetaGraphException::RATE_LIMITED => 'rate_limited',
                MetaGraphException::UNREACHABLE => 'provider_unreachable',
                default => 'provider_unavailable',
            };

            $this->failMessage($lifecycle, $message, $code, $exception->getMessage().' Se agotaron los reintentos automáticos.');

            return;
        }

        if ($exception->isAmbiguous()) {
            $this->leaveUnconfirmed($message, $exception->getMessage());

            return;
        }

        $connection = $message->conversation()->with('channelConnection')->first()?->channelConnection;

        if ($connection !== null) {
            if ($exception->kind === MetaGraphException::TOKEN_INVALID) {
                $monitor->credentialsRejected($connection, $exception->getMessage());
            } elseif ($exception->kind === MetaGraphException::PERMISSION || in_array($exception->metaCode, [368, 131031, 131042, 131045, 131057, 133010], true)) {
                $monitor->failure($connection, $exception->getMessage());
            }
        }

        $code = match (true) {
            $exception->kind === MetaGraphException::TOKEN_INVALID => 'credentials_invalid',
            $exception->kind === MetaGraphException::PERMISSION => 'permission_denied',
            $exception->metaCode !== null && MetaErrorCatalog::isMessageError($exception->metaCode) => 'meta_'.$exception->metaCode,
            default => 'provider_rejected',
        };

        $this->failMessage($lifecycle, $message, $code, $exception->getMessage());
    }

    /**
     * Meta never processed the request: the claim is undone and the job
     * waits before trying again.
     */
    private function requeue(Message $message, MetaGraphException $exception): void
    {
        $requeued = DB::transaction(function () use ($message, $exception): bool {
            $current = Message::query()->lockForUpdate()->find($message->id);

            if ($current === null || $current->status !== MessageStatus::Sending || $current->whatsapp_message_id !== null) {
                return false;
            }

            $current->forceFill([
                'status' => MessageStatus::Queued,
                'sending_started_at' => null,
                'metadata' => [...($current->metadata ?? []), 'delivery' => [
                    ...(is_array($current->metadata['delivery'] ?? null) ? $current->metadata['delivery'] : []),
                    'last_retryable_error' => Str::limit($exception->getMessage(), 300, ''),
                    'last_retryable_at' => now()->toIso8601String(),
                ]],
            ])->save();

            MessageStatusChanged::dispatch($current->id, $current->conversation_id, MessageStatus::Queued->value, MessageStatus::Sending->value);

            return true;
        });

        if ($requeued) {
            $backoff = (array) config('whatsapp.delivery.backoff', [10, 30, 90, 300]);
            $delay = (int) ($backoff[self::attemptsInCycle($message) - 1] ?? end($backoff) ?: 60);

            $this->release($delay);
        }
    }

    private function leaveUnconfirmed(Message $message, string $reason): void
    {
        DB::transaction(function () use ($message, $reason): void {
            $current = Message::query()->lockForUpdate()->find($message->id);

            if ($current === null || $current->status !== MessageStatus::Sending) {
                return;
            }

            $current->forceFill(['metadata' => [...($current->metadata ?? []), 'delivery' => [
                ...(is_array($current->metadata['delivery'] ?? null) ? $current->metadata['delivery'] : []),
                'unconfirmed' => Str::limit($reason, 300, ''),
                'unconfirmed_at' => now()->toIso8601String(),
            ]]])->save();
        });

        Log::warning('Outbound WhatsApp message left unconfirmed; it will be reconciled.', ['message_id' => $message->id]);
    }

    /**
     * The checks right before the claim refused the message.
     */
    private function block(ConversationLifecycle $lifecycle, Conversation $conversation, Message $message, string $code, string $reason): void
    {
        $previous = $message->status;
        $this->markFailed($message, $code, $reason);
        $lifecycle->record($conversation, ConversationEventType::OutboundBlocked, null, [
            'metadata' => ['message_id' => $message->id, 'reason' => $code],
        ]);
        MessageStatusChanged::dispatch($message->id, $message->conversation_id, MessageStatus::Failed->value, $previous->value);
    }

    /**
     * The provider (or this installation) refused the claimed message.
     */
    private function failMessage(ConversationLifecycle $lifecycle, Message $message, string $code, string $reason): void
    {
        DB::transaction(function () use ($lifecycle, $message, $code, $reason): void {
            $current = Message::query()->lockForUpdate()->find($message->id);

            if ($current === null || ! in_array($current->status, [MessageStatus::Sending, MessageStatus::Queued], true)) {
                return;
            }

            $previous = $current->status;
            $this->markFailed($current, $code, $reason);
            $conversation = Conversation::query()->findOrFail($current->conversation_id);
            $lifecycle->record($conversation, ConversationEventType::MessageFailed, null, [
                'metadata' => ['message_id' => $current->id, 'reason' => $code],
            ]);
            MessageStatusChanged::dispatch($current->id, $current->conversation_id, MessageStatus::Failed->value, $previous->value);
            $message->setRawAttributes($current->getAttributes(), true);
        });
    }

    private function markFailed(Message $message, string $code, string $reason): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'error_code' => $code,
            'error_message' => mb_substr($reason, 0, 500),
            'failed_at' => now(),
        ])->save();
    }
}
