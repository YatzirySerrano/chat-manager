<?php

namespace App\Services\Ai;

use App\Actions\Automation\QueueAutomaticMessage;
use App\Actions\Conversations\AssignAutomatically;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Jobs\ReplyWithAi;
use App\Models\Ai\AiConnectionSetting;
use App\Models\Ai\AiConversationState;
use App\Models\Ai\AiSetting;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\Automation\AutomaticMessagePlan;
use App\Services\BusinessHours;
use App\Services\Chatbot\ChatbotConversations;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\QueueRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Inteligencia artificial»: the assistant that answers customers while the
 * conversation waits in a queue, on the connections where the administrator
 * turned it on (never on Meta connections: supports()).
 *
 * 1. afterInbound(): inside the inbound receipt, a customer message of a
 *    pending, unassigned conversation that the chatbot menu did not take
 *    queues ReplyWithAi a few seconds later (after commit).
 * 2. answer(): the job. Bursts are coalesced: only the customer's latest
 *    message is answered, with the whole recent conversation. It stops when
 *    a person accepted or was assigned, the conversation closed, the
 *    chatbot menu awaits a choice, a keyword rule already answered, the
 *    connection answers only outside hours and it is open, or the assistant
 *    already handed off in this cycle.
 * 3. The provider is asked outside any transaction (it may take seconds);
 *    then, with the conversation locked and everything checked again, the
 *    reply is queued as an automatic message (AutomaticMessageKind::AiReply,
 *    through QueueAutomaticMessage, so channels, windows and idempotency are
 *    the ones of every automatic message) with its model, tokens and latency
 *    on the metadata.
 * 4. Handoff: when the model says so (a person is asked for, a complaint, a
 *    payment, something not in the business information), refuses, answers
 *    something unreadable, or the replies of the cycle reach the configured
 *    limit, the farewell (the model's or the configured handoff message) is
 *    sent, the event «La IA la pasó a un asesor» is recorded, the
 *    conversation moves to the connection's handoff department (when set
 *    and active) and the department may distribute it. The assistant never
 *    answers that conversation again until it is reopened.
 *
 * Failures of the provider are logged by type (never the key nor the
 * customer's words) and kept on the settings for the administrator; the
 * conversation simply waits for a person, as without the assistant.
 */
class AiAssistant
{
    /** Seconds the job waits, so a burst of messages gets one answer. */
    public const DEBOUNCE_SECONDS = 5;

    /** A message processed later than this is not answered (out of context). */
    public const MAX_DELAY_MINUTES = 60;

    public function __construct(
        private AiProviders $providers,
        private AiPrompt $prompt,
        private ConversationLifecycle $lifecycle,
        private QueueRules $queueRules,
        private QueueAutomaticMessage $queueMessage,
        private AssignAutomatically $assignAutomatically,
        private ChatbotConversations $chatbot,
        private BusinessHours $hours,
    ) {}

    /**
     * Connections the assistant may answer: web chat and WhatsApp Web (QR).
     * Meta's channels (WhatsApp Cloud API, Messenger, Instagram) are not
     * offered yet.
     */
    public static function supports(Connection $connection): bool
    {
        return ! $connection->usesMetaApi();
    }

    /**
     * A customer message just stored (inside the inbound transaction). Queues
     * the answer when the assistant may take it; costs one indexed query on
     * connections without the assistant.
     */
    public function afterInbound(Connection $connection, Conversation $conversation, Message $message, bool $takenByChatbot): void
    {
        if ($takenByChatbot || ! self::supports($connection) || $conversation->isImported() || $conversation->isHistoryOnly()
            || ! $this->waitingInQueue($conversation)) {
            return;
        }

        $enabled = AiConnectionSetting::query()->where('connection_id', $connection->id)->where('is_enabled', true)->exists();

        if ($enabled) {
            ReplyWithAi::dispatch($conversation->id, $message->id)->delay(now()->addSeconds(self::DEBOUNCE_SECONDS))->afterCommit();
        }
    }

    /**
     * Answers the customer's message (the job). Returns the queued reply, or
     * null when nothing was sent.
     */
    public function answer(int $conversationId, int $messageId): ?Message
    {
        $settings = AiSetting::current();
        $conversation = Conversation::query()->find($conversationId);
        $message = Message::query()->whereKey($messageId)->where('conversation_id', $conversationId)->where('direction', MessageDirection::Inbound)->first();

        if (! $settings->isReady() || $conversation === null || $message === null) {
            return null;
        }

        $connection = $conversation->channelConnection()->first();
        $connectionSetting = $connection === null ? null : AiConnectionSetting::query()->where('connection_id', $connection->id)->first();

        if ($connection === null || $connectionSetting === null || ! $connectionSetting->is_enabled || ! self::supports($connection)) {
            return null;
        }

        if ($this->skipReason($conversation, $message, $connectionSetting) !== null) {
            return null;
        }

        $state = AiConversationState::forCycle($conversation->id, $conversation->reopen_count);

        if ($state->handedOff()) {
            return null;
        }

        $reply = $state->replies >= $settings->max_replies
            ? new AiReply('', true, 'limit', '')
            : $this->ask($settings, $conversation, $message);

        if ($reply === null) {
            return null;
        }

        try {
            return DB::transaction(fn (): ?Message => $this->deliver($conversation, $message, $reply, $settings, $connectionSetting));
        } catch (UniqueConstraintViolationException) {
            // Another worker stored this answer first.
            return null;
        }
    }

    /**
     * Why the message is not answered (null: it is).
     */
    private function skipReason(Conversation $conversation, Message $message, AiConnectionSetting $connectionSetting): ?string
    {
        $writtenAt = $message->sent_at ?? $message->created_at;

        return match (true) {
            ! $this->waitingInQueue($conversation) => 'taken',
            $conversation->isImported() || $conversation->isHistoryOnly() => 'imported',
            $writtenAt === null || $writtenAt->lessThan(now()->subMinutes(self::MAX_DELAY_MINUTES)) => 'late',
            $this->latestInboundId($conversation) !== $message->id => 'superseded',
            $this->chatbot->isAwaiting($conversation->id) => 'chatbot_menu',
            $this->answeredByKeyword($conversation, $message) => 'keyword_reply',
            $connectionSetting->only_outside_hours && $this->hours->isOpenAt($conversation->department()->first(), now()) => 'open_hours',
            default => null,
        };
    }

    private function ask(AiSetting $settings, Conversation $conversation, Message $message): ?AiReply
    {
        $transcript = $this->prompt->conversation($conversation, $message);

        if ($transcript['messages'] === []) {
            return null;
        }

        $request = new AiRequest(
            (string) $settings->api_key,
            $settings->resolvedModel(),
            $this->prompt->instructions($settings),
            $transcript['context'],
            $transcript['messages'],
        );

        try {
            return $this->providers->for($settings->provider)->reply($request);
        } catch (AiProviderException $exception) {
            AiSetting::query()->whereKey($settings->id)->update(['last_error_type' => $exception->type, 'last_error_at' => now()]);

            Log::warning('AI reply failed.', [
                'conversation_id' => $conversation->id,
                'connection_id' => $conversation->connection_id,
                'provider' => $settings->provider->value,
                'model' => $request->model,
                'error' => $exception->type,
                'status' => $exception->status,
            ]);

            return null;
        }
    }

    /**
     * Queues the reply (and hands off) with the conversation locked, once
     * everything still holds.
     */
    private function deliver(Conversation $conversation, Message $message, AiReply $reply, AiSetting $settings, AiConnectionSetting $connectionSetting): ?Message
    {
        $conversation = $this->lifecycle->lock($conversation);
        $state = AiConversationState::forCycle($conversation->id, $conversation->reopen_count, lock: true);

        if ($state->handedOff() || ! $this->waitingInQueue($conversation) || $this->latestInboundId($conversation) !== $message->id
            || $this->chatbot->isAwaiting($conversation->id)
            || ($reply->reason !== 'limit' && $state->replies >= $settings->max_replies)) {
            return null;
        }

        // The model's own farewell when it chose to hand off; the configured
        // message when it could not (limit, refusal, unreadable answer).
        $text = $reply->handoff && ($reply->reply === '' || self::isSystemReason($reply->reason))
            ? $settings->handoffMessage()
            : $reply->reply;

        $sent = $this->queueMessage->queueLocked(
            $conversation,
            new AutomaticMessagePlan(AutomaticMessageKind::AiReply, $text, 'ai'),
            QueueAutomaticMessage::chatbotReference($conversation->id, AutomaticMessageKind::AiReply, "{$conversation->reopen_count}:{$message->id}"),
            array_filter([
                'trigger_message_id' => $message->id,
                'ai_provider' => $settings->provider->value,
                'ai_model' => $reply->model !== '' ? $reply->model : null,
                'ai_input_tokens' => $reply->inputTokens,
                'ai_output_tokens' => $reply->outputTokens,
                'ai_cached_tokens' => $reply->cachedTokens,
                'ai_latency_ms' => $reply->latencyMs,
                'ai_handoff' => $reply->handoff ? 1 : null,
            ], fn (int|string|null $value): bool => $value !== null),
        );

        if ($sent !== null) {
            $state->forceFill(['replies' => $state->replies + 1, 'last_reply_at' => now()]);
        }

        if ($reply->handoff) {
            $this->handOff($conversation, $state, $reply, $message, $connectionSetting);
        }

        $state->save();

        if ($sent !== null) {
            AiSetting::query()->whereKey($settings->id)->update(['last_reply_at' => now(), 'last_error_type' => null, 'last_error_at' => null]);
        }

        Log::info('AI reply processed.', [
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'message_id' => $sent?->id,
            'model' => $reply->model,
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
            'cached_tokens' => $reply->cachedTokens,
            'latency_ms' => $reply->latencyMs,
            'handoff' => $reply->handoff,
        ]);

        return $sent;
    }

    /**
     * A person takes over: the event, the handoff department (when set and
     * active) and the ordinary distribution, as when the chatbot routes.
     */
    private function handOff(Conversation $conversation, AiConversationState $state, AiReply $reply, Message $message, AiConnectionSetting $connectionSetting): void
    {
        $fromId = $conversation->department_id;
        $destinationId = $connectionSetting->handoff_department_id;
        $destination = null;

        // Lock order: departments in id order, the destination exclusively
        // (its queue may be distributed right after).
        foreach (collect([$fromId, $destinationId ?? $fromId])->filter()->unique()->sort() as $departmentId) {
            $locked = $this->lifecycle->lockDepartment($departmentId, exclusive: $departmentId === ($destinationId ?? $fromId));
            $destination = $departmentId === $destinationId ? $locked : $destination;
        }

        if ($destination !== null && $destination->is_active && ! $destination->isDeleted() && $destination->id !== $fromId) {
            $born = $this->queueRules->born($destination->id);
            $ready = $born['readiness'] === ConversationReadiness::Ready;

            $conversation->forceFill([
                'department_id' => $destination->id,
                'readiness' => $born['readiness'],
                'queued_at' => $ready ? ($conversation->queued_at ?? now()) : null,
                'version' => $conversation->version + 1,
            ])->save();
        }

        $this->lifecycle->record($conversation, ConversationEventType::AiHandoff, null, [
            'from_department_id' => $fromId,
            'to_department_id' => $conversation->department_id,
            'metadata' => array_filter([
                'reason' => self::isSystemReason($reply->reason) ? $reply->reason : 'model',
                'detail' => $reply->reason !== '' && ! self::isSystemReason($reply->reason) ? $reply->reason : null,
                'message_id' => $message->id,
            ], fn (mixed $value): bool => $value !== null),
        ]);

        $state->forceFill([
            'handed_off_at' => now(),
            'handoff_reason' => mb_substr($reply->reason !== '' ? $reply->reason : 'model', 0, 40),
        ]);

        $this->assignAutomatically->handle($conversation);
    }

    /**
     * A handoff the platform decided (not the model): limit, refusal or an
     * unreadable answer.
     */
    private static function isSystemReason(string $reason): bool
    {
        return in_array($reason, ['limit', 'refusal', 'invalid_output'], true);
    }

    /**
     * Waiting in a queue: nobody accepted, was assigned or closed it.
     */
    private function waitingInQueue(Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Pending && $conversation->assigned_user_id === null;
    }

    private function latestInboundId(Conversation $conversation): ?int
    {
        $id = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound)
            ->max('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * A keyword rule already answered this message: one automatic answer is
     * enough.
     */
    private function answeredByKeyword(Conversation $conversation, Message $message): bool
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $message->id)
            ->where('metadata->automatic_kind', AutomaticMessageKind::KeywordReply->value)
            ->where('metadata->trigger_message_id', $message->id)
            ->exists();
    }
}
