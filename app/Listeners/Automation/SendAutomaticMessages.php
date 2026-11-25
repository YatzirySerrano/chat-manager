<?php

namespace App\Listeners\Automation;

use App\Actions\Automation\QueueAutomaticMessage;
use App\Enums\ConversationEventType;
use App\Events\Conversations\ConversationChanged;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\Automation\AutomaticMessagePlan;
use App\Services\Automation\AutomaticMessages;
use App\Services\BusinessHours;
use App\Services\Chatbot\ChatbotConversations;
use App\Services\Chatbot\ChatbotMenus;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Sends the automatic messages of a conversation cycle:
 *
 * - greeting (welcome or away) when the customer starts a cycle: a new
 *   conversation created by an inbound message, or one reopened by an
 *   inbound message. Conversations created or reopened by people (manual
 *   reopen, imports) get nothing;
 * - farewell when the conversation closes (by an agent, «Solicitud
 *   completada» included, or by inactivity). On WhatsApp Web (QR), which
 *   has no 24 hour window to bound it, a close by inactivity only says
 *   goodbye when the customer wrote in the last 24 hours (never days later
 *   through a personal phone);
 * - the chatbot's welcome menu (ChatbotConversations) when the new
 *   conversation got one: it takes the place of the welcome within business
 *   hours; outside them the away message still goes first, then the menu.
 *   When the menu can no longer be sent, the usual welcome is sent instead.
 *
 * A greeting is skipped when the customer's last message is older than
 * MAX_GREETING_DELAY_MINUTES (a backlog processed late must not greet
 * someone hours later). See QueueAutomaticMessage for the other guarantees.
 */
class SendAutomaticMessages implements ShouldQueue
{
    public const MAX_GREETING_DELAY_MINUTES = 60;

    /** On WhatsApp Web, a close by inactivity says goodbye only within this many hours of the customer's last message. */
    public const QR_INACTIVITY_FAREWELL_HOURS = 24;

    /**
     * Events that start (greeting) or end (farewell) a conversation cycle.
     *
     * @var list<ConversationEventType>
     */
    private const CYCLE_EVENTS = [
        ConversationEventType::Created,
        ConversationEventType::Reopened,
        ConversationEventType::Closed,
    ];

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(
        private AutomaticMessages $messages,
        private QueueAutomaticMessage $queueMessage,
        private BusinessHours $hours,
        private ChatbotConversations $chatbot,
        private ChatbotMenus $menus,
    ) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::MESSAGES);
    }

    /**
     * Only the events that start or end a cycle queue a job on the critical
     * "messages" queue; every other change (messages, tags, reads...) does not.
     */
    public function shouldQueue(ConversationChanged $event): bool
    {
        return $event->eventId !== null
            && in_array(ConversationEventType::tryFrom($event->reason), self::CYCLE_EVENTS, true);
    }

    public function handle(ConversationChanged $event): void
    {
        $type = ConversationEventType::tryFrom($event->reason);

        if ($type === null || ! $this->shouldQueue($event)) {
            return;
        }

        $record = ConversationEvent::query()->find($event->eventId);
        $conversation = Conversation::query()->with(['channelConnection', 'department'])->find($event->conversationId);
        $connection = $conversation?->channelConnection;

        if ($record === null || $conversation === null || $connection === null) {
            return;
        }

        if ($type === ConversationEventType::Closed) {
            $this->farewell($conversation, $connection, $record);

            return;
        }

        // A cycle started by a person (manual reopen, import) gets no greeting by design.
        if (! $this->startedByCustomer($type, $record)) {
            return;
        }

        $menuOffered = $type === ConversationEventType::Created && $this->chatbot->isAwaiting($conversation->id);

        if (! $this->isRecent($conversation)) {
            $this->skipped($conversation, 'greeting', 'processed_late');

            // Too late for a menu as well: the ordinary routing takes over.
            if ($menuOffered) {
                $this->chatbot->abandon($conversation, 'processed_late');
            }

            return;
        }

        $department = $this->operatingDepartment($conversation->department);

        if ($menuOffered) {
            $open = $this->hours->isOpenAt($department, now());

            // Outside hours the away message goes first; within hours the
            // menu is the welcome (and the usual welcome only replaces it
            // when it could not be sent).
            if (! $open) {
                $this->greet($conversation, $connection, $department, $record);
            }

            if ($this->chatbot->sendMenu($conversation, $record->id) || ! $open) {
                return;
            }
        }

        // A chatbot without options only greets, at the start of every cycle
        // the customer starts: a new conversation or one their message
        // reopened (closing each request is the usual flow, so most cycles
        // are reopenings). Its welcome takes the place of the usual one;
        // outside hours the away message, when there is one, goes first, as
        // with the menu. Nothing waits for an answer.
        if (($greeting = $this->menus->greetingOnly($connection)) !== null) {
            if (! $this->hours->isOpenAt($department, now())) {
                $this->greet($conversation, $connection, $department, $record);
            }

            $this->queueMessage->handle($conversation, new AutomaticMessagePlan(AutomaticMessageKind::ChatbotMenu, $greeting, 'chatbot'), $conversation->reopen_count, $record->id);

            return;
        }

        $this->greet($conversation, $connection, $department, $record);
    }

    private function greet(Conversation $conversation, Connection $connection, ?Department $department, ConversationEvent $record): void
    {
        $plan = $this->messages->greeting($connection, $department, now());

        if ($plan === null) {
            $attention = $this->hours->describe($department);

            // Outside hours without an away message, or within hours without a welcome.
            $this->skipped($conversation, 'greeting', $attention['is_open'] ? 'welcome_not_configured' : 'away_not_configured', [
                'schedule' => $attention['mode'],
                'is_open' => $attention['is_open'],
            ]);

            return;
        }

        $this->queueMessage->handle($conversation, $plan, $conversation->reopen_count, $record->id);
    }

    private function farewell(Conversation $conversation, Connection $connection, ConversationEvent $record): void
    {
        // Closed with «sin despedida».
        if ((($record->metadata ?? [])['farewell'] ?? null) === false) {
            $this->skipped($conversation, 'farewell', 'closed_without_farewell');

            return;
        }

        $plan = $this->messages->farewell($connection);

        if ($plan === null) {
            $this->skipped($conversation, 'farewell', 'not_configured');

            return;
        }

        // WhatsApp Web has no window: a conversation closed by inactivity
        // days after the customer's last message is not told goodbye.
        if ($connection->isWhatsAppWeb()
            && (($record->metadata ?? [])['trigger'] ?? null) === 'inactivity'
            && ($conversation->last_inbound_at === null || $conversation->last_inbound_at->lessThan(now()->subHours(self::QR_INACTIVITY_FAREWELL_HOURS)))) {
            $this->skipped($conversation, 'farewell', 'customer_inactive');

            return;
        }

        $this->queueMessage->handle($conversation, $plan, $conversation->reopen_count, $record->id);
    }

    /**
     * Why a cycle got no automatic message, for administrators diagnosing
     * one that "did not arrive" (no customer data, no message text).
     *
     * @param  array<string, scalar|null>  $context
     */
    private function skipped(Conversation $conversation, string $slot, string $reason, array $context = []): void
    {
        Log::info('Automatic message not sent.', [
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'slot' => $slot,
            'reason' => $reason,
            ...$context,
        ]);
    }

    private function startedByCustomer(ConversationEventType $type, ConversationEvent $record): bool
    {
        $metadata = $record->metadata ?? [];

        return $type === ConversationEventType::Created
            ? ($metadata['source'] ?? null) === 'inbound'
            : ($metadata['trigger'] ?? null) === 'inbound';
    }

    private function isRecent(Conversation $conversation): bool
    {
        return $conversation->last_inbound_at !== null
            && $conversation->last_inbound_at->greaterThanOrEqualTo(now()->subMinutes(self::MAX_GREETING_DELAY_MINUTES));
    }

    /**
     * Messages of a deleted or inactive department no longer apply.
     */
    private function operatingDepartment(?Department $department): ?Department
    {
        return $department !== null && $department->is_active && ! $department->isDeleted() ? $department : null;
    }
}
