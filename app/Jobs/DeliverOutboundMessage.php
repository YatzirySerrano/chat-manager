<?php

namespace App\Jobs;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\ConnectionChannel;
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
use App\Services\Meta\MetaCloudWhatsAppTransport;
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
 * sent, waits: the job is dispatched again a moment later (every
 * ORDER_WAIT_DELAY seconds; a new job, so the wait never uses up the
 * attempts of this one), and the waiting message's updated_at is refreshed
 * so it keeps holding the ones written after it for as long as it waits.
 * That re-check is only the fallback: as soon as a delivery ends (accepted,
 * failed, blocked or left unconfirmed) it dispatches at once a job for the
 * next queued message of its conversation (releaseNext()), so a burst of
 * replies leaves one right after the other instead of polling. Two jobs for
 * the same message are harmless: only the one that claims it (queued →
 * sending, under the row lock) sends; the other finds it claimed and stops.
 * A job dispatched that way never starts a second wait chain, and a message
 * put back in the queue after a retryable error is claimed only once its
 * backoff ended (delivery.retry_not_before), whichever job of it comes first.
 * Only the same conversation waits; other conversations of the same number
 * are never held back. The wait is bounded: an earlier message whose queued
 * row was not refreshed for longer than the longest backoff (its job was
 * lost), "sending" for longer than one attempt may take (TEXT_SENDING_HOLD
 * for messages without a file, the job timeout otherwise: its worker died),
 * or left unconfirmed after an ambiguous answer (never re-sent
 * automatically: it either already reached Meta or never will), no longer
 * holds the next ones. Without an asynchronous queue (sync), messages are
 * delivered as they come.
 *
 * Queue: deliveries that must upload a file to the provider first (a native
 * attachment without an upload id, outside the web chat) run on the media
 * queue, whose workers allow the long upload (worker --timeout 180 s >
 * TIMEOUT); text, templates, links and already uploaded files stay on the
 * messages queue, so an upload never holds back replies or webhooks.
 * Known limits: the order only sees messages already committed. Jobs are
 * dispatched after commit, but if two people reply to the same conversation
 * at once and the earlier row (lower id) commits after the later job has
 * run, the later one may leave first. An earlier queued message whose job
 * is delayed longer than the bound above stops holding the next ones too.
 * A wait longer than LONG_ORDER_WAITS checks is logged once to spot them.
 *
 * Timing: an accepted message keeps in metadata.delivery.timing when each
 * stage happened (UTC, milliseconds) and how long it took (request_received_at
 * of the HTTP request that wrote it, queued_at, started_at, claimed_at, the
 * provider request and answer, accepted_at; wait_ms, claim_ms, provider_ms,
 * upload_ms for a file uploaded during the attempt, total_ms, order_waits and
 * whether a finished earlier delivery released it), to measure the outbound
 * path. It holds no personal data and no credentials.
 */
class DeliverOutboundMessage implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds one attempt may take: the media upload (up to 100 MB, capped
     * by MetaGraphClient::uploadTimeout() so the message send still fits)
     * plus POST /messages. Below the worker's --timeout (150 s, deploy/) and
     * every queue connection's retry_after (900 s, config/queue.php).
     */
    public const TIMEOUT = 140;

    public int $tries;

    public int $timeout = self::TIMEOUT;

    /** When the delivery was first queued (Unix time with microseconds); null on jobs queued before it existed. */
    public ?float $queuedAt = null;

    /** Times this delivery waited for an earlier message of its conversation. */
    public int $orderWaits = 0;

    /** Start of the HTTP request that queued the message (Unix time with microseconds), when it came from one. */
    public ?float $requestReceivedAt = null;

    /**
     * Dispatched by the end of the previous delivery of the conversation
     * (releaseNext()): it never starts a wait chain of its own, the job
     * queued with the message keeps that role.
     */
    public bool $releasedByPrevious = false;

    /**
     * Pause (seconds) between two checks of a delivery waiting for an earlier
     * one. Only a fallback: the end of the earlier delivery releases it at once.
     */
    private const int ORDER_WAIT_DELAY = 3;

    /** Waits (about 45 seconds) after which a delivery held by an earlier message is logged. */
    private const int LONG_ORDER_WAITS = 15;

    /**
     * Seconds a message without a file may stay "sending" (no provider id)
     * and still hold the next ones: POST /messages (Graph timeout plus the
     * connection timeout) and a margin. Longer means its worker died.
     */
    private const int TEXT_SENDING_MARGIN = 15;

    /** @var list<MessageType> Types delivered with a file (uploaded before the send). */
    private const array MEDIA_TYPES = [MessageType::Image, MessageType::Video, MessageType::Audio, MessageType::Document, MessageType::Sticker];

    public function __construct(public int $messageId, ?float $queuedAt = null)
    {
        $this->onQueue(Queues::name(self::uploadsMedia($messageId) ? Queues::MEDIA : Queues::MESSAGES));
        $this->tries = self::maxAttempts() + 1;
        $this->queuedAt = $queuedAt ?? microtime(true);
        $this->requestReceivedAt = self::requestStart();
    }

    /**
     * Whether delivering the message starts with uploading its file to the
     * provider: a native attachment (not a download link) that has no upload
     * id yet, on a channel other than the web chat (whose transport only
     * accepts the message). One indexed lookup when the job is created.
     */
    public static function uploadsMedia(int $messageId): bool
    {
        return Message::query()
            ->whereKey($messageId)
            ->whereIn('type', array_map(fn (MessageType $type): string => $type->value, self::MEDIA_TYPES))
            ->whereHas('attachments', fn (Builder $attachment) => $attachment
                ->where('delivery_method', AttachmentDeliveryMethod::Native)
                ->whereNull('whatsapp_media_id'))
            ->whereHas('conversation.channelConnection', fn (Builder $connection) => $connection->where('channel', '!=', ConnectionChannel::WebChat))
            ->exists();
    }

    /**
     * Start of the current HTTP request; null in the console and in workers.
     */
    private static function requestStart(): ?float
    {
        if (app()->runningInConsole()) {
            return null;
        }

        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return is_float($start) || is_int($start) ? (float) $start : null;
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
        $blocked = false;
        $message = $this->claim($window, $lifecycle, $mustWait, $blocked);

        if ($mustWait) {
            // A job released by the previous delivery leaves the waiting to
            // the chain of the job queued with the message.
            if (! $this->releasedByPrevious) {
                $this->waitForEarlierMessage();
            }

            return;
        }

        if ($blocked) {
            $this->releaseNext();

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
            $this->releaseNext($message->conversation_id);

            return;
        } catch (MetaGraphException $exception) {
            // Put back in the queue (retryable), the message still holds the next ones.
            if ($this->handleProviderFailure($lifecycle, $monitor, $message, $exception)) {
                $this->releaseNext($message->conversation_id);
            }

            return;
        } catch (Throwable $exception) {
            // Unknown outcome: the request may have left. Leave it for reconciliation.
            Log::error('Outbound message ended with an unexpected error; left for reconciliation.', [
                'message_id' => $message->id,
                'channel' => $connection->channel->value,
                'exception' => $exception::class,
            ]);
            $this->leaveUnconfirmed($message, "Ocurrió un error inesperado durante el envío; se espera la confirmación de {$connection->channel->label()}.");
            $this->releaseNext($message->conversation_id);

            return;
        }

        $respondedAt = microtime(true);
        $uploadMs = $transport instanceof MetaCloudWhatsAppTransport ? $transport->lastUploadMs : null;

        $this->accept($message, $result, $monitor, $this->timing($message, $startedAt, $claimedAt, $requestStartedAt, $respondedAt, $uploadMs));
        $this->releaseNext($message->conversation_id);
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
     * Dispatch at once (no delay) a job for the next queued outbound message
     * of the conversation, now that this delivery ended. It goes to that
     * message's own queue (media or messages); its claim still checks the
     * order and the status under the row lock, so a message is never sent
     * twice nor before an earlier one that is still pending.
     */
    private function releaseNext(?int $conversationId = null): void
    {
        if (! $this->runsOnAsynchronousQueue()) {
            return;
        }

        $conversationId ??= Message::query()->whereKey($this->messageId)->value('conversation_id');

        if (! is_int($conversationId)) {
            return;
        }

        $next = Message::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', MessageDirection::Outbound)
            ->where('status', MessageStatus::Queued)
            ->where('id', '>', $this->messageId)
            ->orderBy('id')
            ->first(['id', 'created_at']);

        if ($next === null) {
            return;
        }

        $job = new self($next->id, $next->created_at === null ? null : (float) $next->created_at->getTimestamp());
        $job->releasedByPrevious = true;
        $job->requestReceivedAt = null;

        dispatch($job->onConnection($this->connection));
    }

    /**
     * @param  bool  $mustWait  Set when the message must wait for an earlier one of its conversation.
     * @param  bool  $blocked  Set when the checks refused (failed) the message.
     */
    private function claim(CustomerServiceWindow $window, ConversationLifecycle $lifecycle, bool &$mustWait, bool &$blocked): ?Message
    {
        return DB::transaction(function () use ($window, $lifecycle, &$mustWait, &$blocked): ?Message {
            $message = Message::query()->lockForUpdate()->find($this->messageId);

            if ($message === null || $message->status !== MessageStatus::Queued || $message->direction !== MessageDirection::Outbound) {
                return null;
            }

            // Put back after a retryable error: the job released with the
            // backoff claims it; an earlier one (released by the previous
            // delivery, or a wait check) leaves it alone.
            if ($this->inRetryBackoff($message)) {
                return null;
            }

            $blocked = true;

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

            $blocked = false;

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
            ]);

            if (isset($message->metadata['delivery']['retry_not_before'])) {
                $metadata = $message->metadata;
                unset($metadata['delivery']['retry_not_before']);
                $message->metadata = $metadata;
            }

            $message->save();

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
        $sendingSince = now()->subSeconds(self::TIMEOUT);
        $textSendingSince = now()->subSeconds(self::textSendingHold());
        $mediaTypes = array_map(fn (MessageType $type): string => $type->value, self::MEDIA_TYPES);

        return Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', MessageDirection::Outbound)
            ->where('id', '<', $message->id)
            ->where(fn (Builder $pending) => $pending
                ->where(fn (Builder $queued) => $queued->where('status', MessageStatus::Queued)->where('updated_at', '>=', $queuedSince))
                ->orWhere(fn (Builder $sending) => $sending
                    ->where('status', MessageStatus::Sending)
                    ->whereNull('whatsapp_message_id')
                    // Left unconfirmed after an ambiguous answer: never re-sent
                    // automatically, so it no longer holds the next ones.
                    ->whereNull('metadata->delivery->unconfirmed_at')
                    ->where(fn (Builder $recent) => $recent
                        ->where(fn (Builder $media) => $media->whereIn('type', $mediaTypes)->where('sending_started_at', '>=', $sendingSince))
                        ->orWhere(fn (Builder $text) => $text->whereNotIn('type', $mediaTypes)->where('sending_started_at', '>=', $textSendingSince)))))
            ->exists();
    }

    /**
     * Longest time (seconds) a message without a file may legitimately stay
     * "sending": one POST /messages (Graph timeout plus connection timeout)
     * and a margin.
     */
    public static function textSendingHold(): int
    {
        $graph = max(1, (int) config('services.meta.timeout'));

        return min(self::TIMEOUT, $graph + min(5, $graph) + self::TEXT_SENDING_MARGIN);
    }

    /**
     * Whether the message was put back in the queue after a retryable error
     * and its backoff has not ended yet (one second of tolerance for the
     * queue's clock granularity).
     */
    private function inRetryBackoff(Message $message): bool
    {
        $notBefore = $message->metadata['delivery']['retry_not_before'] ?? null;

        if (! is_string($notBefore) || $notBefore === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($notBefore)->subSecond()->isFuture();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Try again shortly with a new job (same message, same queue), keeping
     * when the delivery was first queued. A flat, short pause: the end of the
     * earlier delivery normally releases the message first (releaseNext()).
     */
    private function waitForEarlierMessage(): void
    {
        $next = new self($this->messageId, $this->queuedAt);
        $next->orderWaits = $this->orderWaits + 1;
        $next->requestReceivedAt = $this->requestReceivedAt;

        if ($next->orderWaits === self::LONG_ORDER_WAITS) {
            Log::info('Outbound delivery held by an earlier message of its conversation.', [
                'message_id' => $this->messageId,
                'order_waits' => $next->orderWaits,
            ]);
        }

        dispatch($next->onConnection($this->connection)->onQueue($this->queue)->delay(self::ORDER_WAIT_DELAY));
    }

    /**
     * Stages of this attempt (UTC with milliseconds) and their durations.
     *
     * @return array<string, int|string>
     */
    private function timing(Message $message, float $startedAt, float $claimedAt, float $requestStartedAt, float $respondedAt, ?int $uploadMs): array
    {
        $milliseconds = fn (float $from, float $to): int => max(0, (int) round(($to - $from) * 1000));

        return array_filter([
            'request_received_at' => $this->requestReceivedAt === null ? null : self::stamp($this->requestReceivedAt),
            'queued_at' => $this->queuedAt === null ? null : self::stamp($this->queuedAt),
            'started_at' => self::stamp($startedAt),
            'claimed_at' => self::stamp($claimedAt),
            'graph_request_started_at' => self::stamp($requestStartedAt),
            'graph_response_at' => self::stamp($respondedAt),
            'wait_ms' => $this->queuedAt === null ? null : $milliseconds($this->queuedAt, $startedAt),
            'claim_ms' => $milliseconds($startedAt, $claimedAt),
            'provider_ms' => $milliseconds($requestStartedAt, $respondedAt),
            'upload_ms' => $uploadMs,
            'attempt' => $message->delivery_attempts,
            'order_waits' => $this->orderWaits,
            'released_by_previous' => $this->releasedByPrevious ? 1 : null,
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

        // A template is an administrative send: its sender must still hold templates.send.
        $ability = $message->type === MessageType::Template ? 'sendTemplate' : 'reply';

        return $sender !== null && Gate::forUser($sender)->allows($ability, $conversation);
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

            if ($result->warning !== null) {
                $current->metadata = [...($current->metadata ?? []), 'delivery_warning' => mb_substr($result->warning, 0, 500)];
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

    /**
     * @return bool Whether the delivery ended (failed or left unconfirmed), false when it was put back in the queue.
     */
    private function handleProviderFailure(ConversationLifecycle $lifecycle, ConnectionMonitor $monitor, Message $message, MetaGraphException $exception): bool
    {
        if ($exception->isRetryable()) {
            if (self::attemptsInCycle($message) < self::maxAttempts()) {
                $this->requeue($message, $exception);

                return false;
            }

            $code = match ($exception->kind) {
                MetaGraphException::RATE_LIMITED => 'rate_limited',
                MetaGraphException::UNREACHABLE => 'provider_unreachable',
                default => 'provider_unavailable',
            };

            $this->failMessage($lifecycle, $message, $code, $exception->getMessage().' Se agotaron los reintentos automáticos.');

            return true;
        }

        if ($exception->isAmbiguous()) {
            $this->leaveUnconfirmed($message, $exception->getMessage());

            return true;
        }

        $connection = $message->conversation->channelConnection;

        if ($connection !== null) {
            if ($exception->kind === MetaGraphException::TOKEN_INVALID) {
                $monitor->credentialsRejected($connection, $exception->getMessage());
            } elseif ($exception->kind === MetaGraphException::PERMISSION || in_array($exception->metaCode, [368, 131031, 131042, 131045, 131057, 133010], true)) {
                $monitor->failure($connection, $exception->getMessage(), ConnectionMonitor::ERROR_SEND);
            }
        }

        $code = match (true) {
            $exception->kind === MetaGraphException::TOKEN_INVALID => 'credentials_invalid',
            $exception->kind === MetaGraphException::PERMISSION => 'permission_denied',
            $exception->metaCode !== null && MetaErrorCatalog::isMessageError($exception->metaCode) => 'meta_'.$exception->metaCode,
            default => 'provider_rejected',
        };

        $this->failMessage($lifecycle, $message, $code, $exception->getMessage());

        return true;
    }

    /**
     * Meta never processed the request: the claim is undone and the job
     * waits before trying again.
     */
    private function requeue(Message $message, MetaGraphException $exception): void
    {
        $backoff = (array) config('whatsapp.delivery.backoff', [10, 30, 90, 300]);
        $delay = (int) ($backoff[self::attemptsInCycle($message) - 1] ?? end($backoff) ?: 60);

        $requeued = DB::transaction(function () use ($message, $exception, $delay): bool {
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
                    'retry_not_before' => now()->addSeconds($delay)->toIso8601String(),
                ]],
            ])->save();

            MessageStatusChanged::dispatch($current->id, $current->conversation_id, MessageStatus::Queued->value, MessageStatus::Sending->value);

            return true;
        });

        if ($requeued) {
            // The job now carries this message again, so it must keep its own
            // wait chain should an earlier reply be pending when it returns.
            $this->releasedByPrevious = false;
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
