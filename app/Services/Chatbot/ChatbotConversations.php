<?php

namespace App\Services\Chatbot;

use App\Actions\Automation\QueueAutomaticMessage;
use App\Actions\Conversations\AssignAutomatically;
use App\Enums\ChatbotSessionStatus;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Models\ChatbotMenu;
use App\Models\ChatbotMenuOption;
use App\Models\ChatbotSession;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\Automation\AutomaticMessagePlan;
use App\Services\BusinessHours;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\QueueRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The chatbot's welcome menu in a conversation (docs/notificaciones-y-horarios.md,
 * «Chatbot»):
 *
 * 1. offer(): a conversation just created by a customer message, on a
 *    connection whose menu is on, gets a session «awaiting» in the same
 *    transaction that created it, unless the contact has a department of
 *    its own (ConversationRouter routed it by contact). Reopened
 *    conversations, imported history and conversations created by people
 *    never get one.
 * 2. While awaiting, the conversation waits PENDING in the department the
 *    ordinary routing chose (provisional queue: visible there, so nobody
 *    loses it), but it is neither distributed automatically nor announced
 *    as «Nueva conversación pendiente»: whoever would receive it does not
 *    know yet which department it belongs to. A person may still take it;
 *    then the menu stops (cancelled).
 * 3. sendMenu(): the greeting and the numbered options, queued by
 *    SendAutomaticMessages when the conversation is created.
 * 4. answer(): the customer's next messages. An option (its number or its
 *    text) moves the conversation to the option's department, records the
 *    event «El chatbot la envió a X», sends the option's reply (or the
 *    department's welcome, or away message outside hours) and lets the
 *    department distribute and announce it. Anything else gets the short
 *    retry with the options again, up to the menu's attempts; one more
 *    and the ordinary routing takes over (fallback): the conversation stays
 *    where it was, now distributed and announced.
 *
 * Every step runs with the conversation row locked (the inbound receipt
 * already holds it), so two messages processed at once never send the menu
 * twice nor route the conversation twice; the session row is unique per
 * conversation.
 */
class ChatbotConversations
{
    public function __construct(
        private ChatbotMenus $menus,
        private ConversationLifecycle $lifecycle,
        private QueueRules $queueRules,
        private AssignAutomatically $assignAutomatically,
        private QueueAutomaticMessage $queueMessage,
        private BusinessHours $hours,
    ) {}

    /**
     * Opens the menu for a conversation just created by a customer message.
     * Must run inside the transaction that created it. Returns whether the
     * menu was opened (the caller then skips the automatic distribution).
     *
     * @param  'contact'|'connection_default'|'platform_default'|'unrouted'  $routedBy
     */
    public function offer(Connection $connection, Conversation $conversation, string $routedBy): bool
    {
        // A contact with a department of their own already knows where to go.
        if ($routedBy === 'contact' || $conversation->isImported() || $conversation->isHistoryOnly()) {
            return false;
        }

        if ($this->menus->availableFor($connection) === null) {
            return false;
        }

        $session = new ChatbotSession;
        $session->forceFill([
            'conversation_id' => $conversation->id,
            'connection_id' => $connection->id,
            'status' => ChatbotSessionStatus::Awaiting,
            'attempts' => 0,
        ])->save();

        return true;
    }

    public function isAwaiting(int $conversationId): bool
    {
        return ChatbotSession::query()
            ->where('conversation_id', $conversationId)
            ->where('status', ChatbotSessionStatus::Awaiting)
            ->exists();
    }

    /**
     * Queues the menu of a conversation whose session awaits (once: its
     * reference is unique per conversation and cycle). Returns false when
     * no menu was sent: no session awaiting, a person already took it, or
     * the menu is no longer available (then the ordinary routing takes over
     * and the caller sends the usual welcome).
     */
    public function sendMenu(Conversation $conversation, ?int $triggerEventId = null): bool
    {
        return DB::transaction(function () use ($conversation, $triggerEventId): bool {
            $conversation = $this->lifecycle->lock($conversation);
            $session = $this->awaitingSession($conversation);

            if ($session === null) {
                return false;
            }

            if (! $this->stillInQueue($conversation)) {
                $this->close($session, ChatbotSessionStatus::Cancelled);

                return false;
            }

            $available = $this->menus->availableFor($conversation->channelConnection()->firstOrFail());

            if ($available === null) {
                $this->fallBack($conversation, $session, 'menu_unavailable');

                return false;
            }

            $plan = new AutomaticMessagePlan(AutomaticMessageKind::ChatbotMenu, $this->menus->text($available['menu'], $available['options']), 'chatbot');

            $this->queueMessage->queueLocked(
                $conversation,
                $plan,
                QueueAutomaticMessage::reference($conversation->id, AutomaticMessageKind::ChatbotMenu, $conversation->reopen_count),
                ['trigger_event_id' => $triggerEventId, 'chatbot_menu_id' => $available['menu']->id],
            );

            return true;
        });
    }

    /**
     * The menu will not be sent (the conversation was processed too late):
     * the ordinary routing takes over at once.
     */
    public function abandon(Conversation $conversation, string $reason): void
    {
        DB::transaction(function () use ($conversation, $reason): void {
            $conversation = $this->lifecycle->lock($conversation);
            $session = $this->awaitingSession($conversation);

            if ($session !== null) {
                $this->fallBack($conversation, $session, $reason);
            }
        });
    }

    /**
     * A customer message added to a conversation, inside the inbound
     * transaction that holds its row locked. Returns true when the message
     * was the answer to the menu (chosen, retried or fallen back), so
     * nothing else (keyword replies) answers it.
     */
    public function answer(Conversation $conversation, Message $message): bool
    {
        $session = $this->awaitingSession($conversation);

        if ($session === null) {
            return false;
        }

        // A person took over before the customer chose: the menu is over.
        if (! $this->stillInQueue($conversation)) {
            $this->close($session, ChatbotSessionStatus::Cancelled);

            return false;
        }

        $available = $this->menus->availableFor($conversation->channelConnection()->firstOrFail());

        if ($available === null) {
            $this->fallBack($conversation, $session, 'menu_unavailable');

            return false;
        }

        $option = $this->menus->choose($available['options'], (string) $message->body);

        if ($option !== null) {
            $this->route($conversation, $session, $option, $message);

            return true;
        }

        $session->attempts++;

        if ($session->attempts > $available['menu']->max_attempts) {
            $this->fallBack($conversation, $session, 'no_valid_option');

            return true;
        }

        $session->save();
        $this->retry($conversation, $session, $available['menu'], $available['options'], $message);

        return true;
    }

    /**
     * The chosen option: the conversation moves to its department (queue
     * clock kept when it was already ready, so choosing never costs the
     * customer their place), the event is recorded, the reply is queued and
     * the department may distribute it.
     */
    private function route(Conversation $conversation, ChatbotSession $session, ChatbotMenuOption $option, Message $message): void
    {
        $fromId = $conversation->department_id;
        $destinationId = $option->department_id;
        $destination = null;

        // Lock order step 2: both departments in id order, the destination
        // exclusively (its queue may be distributed right after).
        foreach (collect([$fromId, $destinationId])->filter()->unique()->sort() as $departmentId) {
            $locked = $this->lifecycle->lockDepartment($departmentId, exclusive: $departmentId === $destinationId);
            $destination = $departmentId === $destinationId ? $locked : $destination;
        }

        // Deactivated while the customer was choosing.
        if ($destination === null || ! $destination->is_active) {
            $this->fallBack($conversation, $session, 'department_unavailable');

            return;
        }

        if ($destination->id !== $fromId) {
            $born = $this->queueRules->born($destination->id);
            $ready = $born['readiness'] === ConversationReadiness::Ready;

            $conversation->forceFill([
                'department_id' => $destination->id,
                'readiness' => $born['readiness'],
                'queued_at' => $ready ? ($conversation->queued_at ?? now()) : null,
                'version' => $conversation->version + 1,
            ])->save();
        }

        $this->lifecycle->record($conversation, ConversationEventType::ChatbotRouted, null, [
            'from_department_id' => $fromId,
            'to_department_id' => $destination->id,
            'metadata' => [
                'outcome' => 'chosen',
                'option_id' => $option->id,
                'option' => $option->displayLabel(),
                'message_id' => $message->id,
            ],
        ]);

        $session->forceFill([
            'status' => ChatbotSessionStatus::Routed,
            'chatbot_menu_option_id' => $option->id,
            'department_id' => $destination->id,
            'resolved_at' => now(),
        ])->save();

        $reply = $this->replyText($option, $destination);

        if ($reply !== null) {
            $this->queueMessage->queueLocked(
                $conversation,
                new AutomaticMessagePlan(AutomaticMessageKind::ChatbotReply, $reply, 'chatbot'),
                QueueAutomaticMessage::reference($conversation->id, AutomaticMessageKind::ChatbotReply, $conversation->reopen_count),
                ['chatbot_option_id' => $option->id, 'trigger_message_id' => $message->id],
            );
        }

        $this->assignAutomatically->handle($conversation);
    }

    /**
     * No option chosen (or none available): the conversation stays in the
     * department the ordinary routing chose, which may now distribute and
     * announce it (the event says why).
     */
    private function fallBack(Conversation $conversation, ChatbotSession $session, string $reason): void
    {
        // Exclusive, like a new conversation of an automatic department: the
        // event references it and the distribution below may lock it again.
        $this->lifecycle->lockDepartment($conversation->department_id, exclusive: true);

        $this->lifecycle->record($conversation, ConversationEventType::ChatbotRouted, null, [
            'from_department_id' => $conversation->department_id,
            'to_department_id' => $conversation->department_id,
            'metadata' => ['outcome' => 'fallback', 'reason' => $reason],
        ]);

        $this->close($session, ChatbotSessionStatus::Fallback);

        Log::info('Chatbot menu fell back to the ordinary routing.', [
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'reason' => $reason,
            'attempts' => $session->attempts,
        ]);

        $this->assignAutomatically->handle($conversation);
    }

    /**
     * @param  Collection<int, ChatbotMenuOption>  $options
     */
    private function retry(Conversation $conversation, ChatbotSession $session, ChatbotMenu $menu, Collection $options, Message $message): void
    {
        $this->queueMessage->queueLocked(
            $conversation,
            new AutomaticMessagePlan(AutomaticMessageKind::ChatbotRetry, $this->menus->retryText($menu, $options), 'chatbot'),
            QueueAutomaticMessage::chatbotReference($conversation->id, AutomaticMessageKind::ChatbotRetry, "{$conversation->reopen_count}:{$session->attempts}"),
            ['attempt' => $session->attempts, 'trigger_message_id' => $message->id],
        );
    }

    /**
     * The option's own reply; else the department's greeting: its welcome
     * within its hours, its away message outside them. Null when none.
     */
    private function replyText(ChatbotMenuOption $option, Department $department): ?string
    {
        $candidates = [
            $option->reply_message,
            $this->hours->isOpenAt($department, now()) ? $department->welcome_message : $department->away_message,
        ];

        foreach ($candidates as $text) {
            if (trim((string) $text) !== '') {
                return trim((string) $text);
            }
        }

        return null;
    }

    private function awaitingSession(Conversation $conversation): ?ChatbotSession
    {
        return ChatbotSession::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', ChatbotSessionStatus::Awaiting)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Still waiting in a queue for the customer's choice: nobody accepted,
     * assigned, transferred to a person or closed it.
     */
    private function stillInQueue(Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Pending && $conversation->assigned_user_id === null;
    }

    private function close(ChatbotSession $session, ChatbotSessionStatus $status): void
    {
        $session->forceFill(['status' => $status, 'resolved_at' => now()])->save();
    }
}
