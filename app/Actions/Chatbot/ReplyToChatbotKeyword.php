<?php

namespace App\Actions\Chatbot;

use App\Actions\Automation\QueueAutomaticMessage;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Listeners\Automation\SendAutomaticMessages;
use App\Models\ChatbotKeywordRule;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ChatbotReplyNotification;
use App\Notifications\NotificationRecipients;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\Automation\AutomaticMessagePlan;
use App\Services\Chatbot\ChatbotKeywords;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * «Chatbot · Palabras clave»: a customer message that contains a keyword of
 * an active rule (scoped to its connection and department) is answered
 * automatically with the rule's reply, through the ordinary outbound
 * pipeline (QueueAutomaticMessage: the 24 hour window on WhatsApp Cloud
 * API, QR and web chat as they are), and the collaborators are told: the
 * agent attending the conversation or, when nobody does, the people who
 * can take it from its queue (chatbot.reply notification).
 *
 * Guarantees:
 * - once per (rule, customer message): its reference is deterministic, so
 *   a repeated job stores one reply;
 * - throttle: the same rule answers the same conversation at most once per
 *   its `throttle_minutes` (checked under the conversation lock), so two
 *   messages with the keyword, or a customer repeating it, never loop;
 * - only the first matching rule answers a message;
 * - never for closed or imported conversations, nor for a message processed
 *   more than an hour late (an answer out of context);
 * - a failed notification never undoes the reply (logged, not thrown).
 */
class ReplyToChatbotKeyword
{
    public function __construct(
        private ChatbotKeywords $keywords,
        private ConversationLifecycle $lifecycle,
        private QueueAutomaticMessage $queueMessage,
        private NotificationRecipients $recipients,
    ) {}

    public function handle(int $conversationId, int $messageId): ?Message
    {
        $conversation = Conversation::query()->find($conversationId);
        $inbound = Message::query()
            ->whereKey($messageId)
            ->where('conversation_id', $conversationId)
            ->where('direction', MessageDirection::Inbound)
            ->first();

        if ($conversation === null || $inbound === null || $conversation->status === ConversationStatus::Closed || $conversation->isImported()) {
            return null;
        }

        $writtenAt = $inbound->sent_at ?? $inbound->created_at;

        if ($writtenAt === null || $writtenAt->lessThan(now()->subMinutes(SendAutomaticMessages::MAX_GREETING_DELAY_MINUTES))) {
            return null;
        }

        $found = $this->keywords->find($conversation, (string) $inbound->body);

        if ($found === null) {
            return null;
        }

        try {
            $reply = DB::transaction(fn (): ?Message => $this->reply($conversation, $inbound, $found['rule'], $found['keyword']));
        } catch (UniqueConstraintViolationException) {
            // Another worker stored this reply first.
            return null;
        }

        if ($reply !== null) {
            $this->notify($reply, $found['rule']);
        }

        return $reply;
    }

    private function reply(Conversation $conversation, Message $inbound, ChatbotKeywordRule $rule, string $keyword): ?Message
    {
        $conversation = $this->lifecycle->lock($conversation);

        if ($conversation->status === ConversationStatus::Closed) {
            return null;
        }

        $since = now()->subMinutes(max(1, $rule->throttle_minutes));
        $recent = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('metadata->automatic_kind', AutomaticMessageKind::KeywordReply->value)
            ->where('metadata->chatbot_rule_id', $rule->id)
            ->where('created_at', '>=', $since)
            ->exists();

        if ($recent) {
            Log::info('Automatic message not sent.', [
                'conversation_id' => $conversation->id,
                'connection_id' => $conversation->connection_id,
                'kind' => AutomaticMessageKind::KeywordReply->value,
                'rule_id' => $rule->id,
                'reason' => 'throttled',
            ]);

            return null;
        }

        return $this->queueMessage->queueLocked(
            $conversation,
            new AutomaticMessagePlan(AutomaticMessageKind::KeywordReply, $rule->reply_message, 'keyword'),
            QueueAutomaticMessage::chatbotReference($conversation->id, AutomaticMessageKind::KeywordReply, "{$rule->id}:{$inbound->id}"),
            ['chatbot_rule_id' => $rule->id, 'keyword' => mb_substr($keyword, 0, 80), 'trigger_message_id' => $inbound->id],
        );
    }

    /**
     * The responsible agent, or whoever can take it from its queue.
     */
    private function notify(Message $reply, ChatbotKeywordRule $rule): void
    {
        try {
            $conversation = Conversation::query()->findOrFail($reply->conversation_id);
            $agent = $this->recipients->ifCanView($conversation->assigned_user_id, $conversation);

            /** @var Collection<int, User> $recipients */
            $recipients = $agent !== null
                ? collect([$agent])
                : ($conversation->assigned_user_id === null ? $this->recipients->forPendingConversation($conversation) : collect());

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new ChatbotReplyNotification($conversation->id, $reply->id, $rule->id));
            }
        } catch (Throwable $exception) {
            Log::warning('Chatbot reply notification failed.', [
                'conversation_id' => $reply->conversation_id,
                'message_id' => $reply->id,
                'rule_id' => $rule->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
