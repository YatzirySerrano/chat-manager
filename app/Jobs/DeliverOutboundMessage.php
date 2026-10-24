<?php

namespace App\Jobs;

use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Messaging\ChannelTransports;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaErrorCatalog;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\SendResult;
use App\Services\Meta\WhatsAppTransportUnavailable;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers one queued outbound message (text, media, template) through the
 * transport of its connection's channel (ChannelTransports): WhatsApp, or the
 * web chat, whose transport only accepts the message.
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
 *
 * Order per conversation: replies of one conversation leave in the order they
 * were written (by id). Right before the claim, a message whose earlier
 * outbound message of the same conversation is still queued, or still being
 * sent, waits: the job is dispatched again a moment later (1, 2, 4 and then
 * every 5 seconds; a new job, so the wait never uses up the attempts of this
 * one), and the waiting message's updated_at is refreshed so it keeps holding
 * the ones written after it for as long as it waits. Only the same
 * conversation waits; other conversations of the same number are never held
 * back. The wait is bounded: an earlier message whose queued row was not
 * refreshed for longer than the longest backoff (its job was lost), or
 * "sending" for longer than the job timeout (an ambiguous outcome waiting for
 * reconciliation), no longer holds the next ones. Without an
 * asynchronous queue (sync), messages are delivered as they come.
 * Known limits: the order only sees messages already committed. Jobs are
 * dispatched after commit, but if two people reply to the same conversation
 * at once and the earlier row (lower id) commits after the later job has
 * run, the later one may leave first. An earlier queued message whose job
 * is delayed longer than the bound above stops holding the next ones too.
 * A wait longer than LONG_ORDER_WAITS checks is logged once to spot them.
 *
 * Timing: an accepted message keeps in metadata.delivery.timing when each
 * stage happened (UTC, milliseconds) and how long it took, to measure the
 * outbound path. It holds no personal data and no credentials.
 */
class DeliverOutboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 90;

    /** When the delivery was first queued (Unix time with microseconds); null on jobs queued before it existed. */
    public ?float $queuedAt = null;

    /** Times this delivery waited for an earlier message of its conversation. */
    public int $orderWaits = 0;

    /** Longest pause (seconds) between two checks of a delivery waiting for an earlier one. */
    private const int MAX_ORDER_WAIT_DELAY = 5;

    /** Waits (about one minute) after which a delivery held by an earlier message is logged. */
    private const int LONG_ORDER_WAITS = 15;

    public function __construct(public int $messageId, ?float $queuedAt = null)
    {
        $this->onQueue(Queues::name(Queues::MESSAGES));
        $this->tries = self::maxAttempts() + 1;
        $this->queuedAt = $queuedAt ?? microtime(true);
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

    public function handle(CustomerServiceWindow $window, ConversationLifecycle $lifecycle, ChannelTransports $transports, ConnectionMonitor $monitor): void
    {
        $startedAt = microtime(true);
        $mustWait = false;
        $message = $this->claim($window, $lifecycle, $mustWait);

        if ($mustWait) {
            $this->waitForEarlierMessage();

            return;
        }

        if ($message === null) {
            return;
        }

        $claimedAt = microtime(true);

        /** @var Connection $connection claim() only returns a message whose connection is operational. */
        $connection = $message->conversation->channelConnection;
        $transport = $transports->for($connection);

        try {
            $requestStartedAt = microtime(true);
            $result = $transport->send($message);
        } catch (WhatsAppTransportUnavailable $exception) {
            $this->failMessage($lifecycle, $message, 'transport_unavailable', $exception->getMessage());

            return;
        } catch (MetaGraphException $exception) {
            $this->handleProviderFailure($lifecycle, $monitor, $message, $exception);

            return;
        } catch (Throwable $exception) {
            // Unknown outcome: the request may have left. Leave it for reconciliation.
            Log::error('Outbound message ended with an unexpected error; left for reconciliation.', [
                'message_id' => $message->id,
                'channel' => $connection->channel->value,
                'exception' => $exception::class,
            ]);
            $this->leaveUnconfirmed($message, "Ocurrió un error inesperado durante el envío; se espera la confirmación de {$connection->channel->label()}.");

            return;
        }

        $this->accept($message, $result, $monitor, $this->timing($message, $startedAt, $claimedAt, $requestStartedAt, microtime(true)));
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

    /**
     * @param  bool  $mustWait  Set when the message must wait for an earlier one of its conversation.
     */
    private function claim(CustomerServiceWindow $window, ConversationLifecycle $lifecycle, bool &$mustWait): ?Message
    {
        return DB::transaction(function () use ($window, $lifecycle, &$mustWait): ?Message {
            $message = Message::query()->lockForUpdate()->find($this->messageId);

            if ($message === null || $message->status !== MessageStatus::Queued || $message->direction !== MessageDirection::Outbound) {
                return null;
            }

            $conversation = Conversation::query()->with('channelConnection')->findOrFail($message->conversation_id);

            // Nothing leaves through a connection that was deleted or deactivated
            // after the message was queued.
            if (! ($conversation->channelConnection?->isOperational() ?? false)) {
                $channel = $conversation->channelConnection?->channel->label() ?? 'WhatsApp';
                $this->block($lifecycle, $conversation, $message, 'connection_unavailable', "La conexión de {$channel} de esta conversación no está disponible.");

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

            if ($this->runsOnAsynchronousQueue() && $this->hasEarlierPendingMessage($message)) {
                // Still alive while it waits: a later message of the conversation
                // must keep counting it as pending, however long the wait lasts.
                Message::query()->whereKey($message->id)->update(['updated_at' => now()]);
                $mustWait = true;

                return null;
            }

            // The transport and accept() reuse what was just checked.
            $message->setRelation('conversation', $conversation);

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

    private function runsOnAsynchronousQueue(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }

    /**
     * An earlier outbound message of the same conversation that has not
     * reached Meta yet and is still expected to (see the class summary).
     */
    private function hasEarlierPendingMessage(Message $message): bool
    {
        $backoff = array_map('intval', (array) config('whatsapp.delivery.backoff', [10, 30, 90, 300]));
        $queuedSince = now()->subSeconds(max([0, ...$backoff]) + 60);
        $sendingSince = now()->subSeconds($this->timeout);

        return Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', MessageDirection::Outbound)
            ->where('id', '<', $message->id)
            ->where(fn (Builder $pending) => $pending
                ->where(fn (Builder $queued) => $queued->where('status', MessageStatus::Queued)->where('updated_at', '>=', $queuedSince))
                ->orWhere(fn (Builder $sending) => $sending->where('status', MessageStatus::Sending)->whereNull('whatsapp_message_id')->where('sending_started_at', '>=', $sendingSince)))
            ->exists();
    }

    /**
     * Try again shortly with a new job (same message, same queue), keeping
     * when the delivery was first queued. The pause grows with each wait
     * (1, 2, 4 and then 5 seconds) so a long wait, such as an earlier message
     * in backoff while Meta is degraded, does not poll every second.
     */
    private function waitForEarlierMessage(): void
    {
        $next = new self($this->messageId, $this->queuedAt);
        $next->orderWaits = $this->orderWaits + 1;

        if ($next->orderWaits === self::LONG_ORDER_WAITS) {
            Log::info('Outbound delivery held by an earlier message of its conversation.', [
                'message_id' => $this->messageId,
                'order_waits' => $next->orderWaits,
            ]);
        }

        dispatch($next->onConnection($this->connection)->onQueue($this->queue)->delay(self::orderWaitDelay($this->orderWaits)));
    }

    /**
     * Seconds before checking again, after the given number of earlier waits.
     */
    private static function orderWaitDelay(int $previousWaits): int
    {
        return min(self::MAX_ORDER_WAIT_DELAY, 2 ** min(max(0, $previousWaits), 3));
    }

    /**
     * Stages of this attempt (UTC with milliseconds) and their durations.
     *
     * @return array<string, int|string>
     */
    private function timing(Message $message, float $startedAt, float $claimedAt, float $requestStartedAt, float $respondedAt): array
    {
        $milliseconds = fn (float $from, float $to): int => max(0, (int) round(($to - $from) * 1000));

        return array_filter([
            'queued_at' => $this->queuedAt === null ? null : self::stamp($this->queuedAt),
            'started_at' => self::stamp($startedAt),
            'claimed_at' => self::stamp($claimedAt),
            'graph_request_started_at' => self::stamp($requestStartedAt),
            'graph_response_at' => self::stamp($respondedAt),
            'wait_ms' => $this->queuedAt === null ? null : $milliseconds($this->queuedAt, $startedAt),
            'claim_ms' => $milliseconds($startedAt, $claimedAt),
            'provider_ms' => $milliseconds($requestStartedAt, $respondedAt),
            'attempt' => $message->delivery_attempts,
            'order_waits' => $this->orderWaits,
        ], fn (int|string|null $value): bool => $value !== null);
    }

    private static function stamp(float $at): string
    {
        return CarbonImmutable::createFromTimestampMs((int) round($at * 1000), 'UTC')->format('Y-m-d\TH:i:s.v\Z');
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

    /**
     * @param  array<string, int|string>  $timing
     */
    private function accept(Message $message, SendResult $result, ConnectionMonitor $monitor, array $timing): void
    {
        $connection = DB::transaction(function () use ($message, $result, $timing) {
            $current = Message::query()->lockForUpdate()->findOrFail($message->id);
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

            $acceptedAt = microtime(true);
            $start = isset($timing['queued_at']) ? $this->queuedAt : null;
            $current->metadata = [...($current->metadata ?? []), 'delivery' => [
                ...(is_array($current->metadata['delivery'] ?? null) ? $current->metadata['delivery'] : []),
                'timing' => [
                    ...$timing,
                    'accepted_at' => self::stamp($acceptedAt),
                    ...($start === null ? [] : ['total_ms' => max(0, (int) round(($acceptedAt - $start) * 1000))]),
                ],
            ]];

            $current->save();

            if ($current->status !== $previous) {
                MessageStatusChanged::dispatch($current->id, $current->conversation_id, $current->status->value, $previous->value);
            }

            return $message->conversation->channelConnection;
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

        $connection = $message->conversation->channelConnection;

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

        Log::warning('Outbound message left unconfirmed; it will be reconciled.', ['message_id' => $message->id]);
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
