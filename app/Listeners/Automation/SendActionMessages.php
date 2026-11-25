<?php

namespace App\Listeners\Automation;

use App\Actions\Automation\QueueAutomaticMessage;
use App\Enums\ConversationEventType;
use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\User;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\Automation\AutomaticMessagePlan;
use App\Services\Automation\AutomaticMessages;
use App\Services\Chatbot\ChatbotMenus;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Sends the connection's automatic messages for the team's actions on a
 * conversation, once per conversation event:
 *
 * - the chatbot's welcome (a chatbot without options), before anything
 *   else, when the first person takes it and this cycle has not had it;
 * - «Agente asignado» when a person takes it with nobody attending it
 *   before: accepted, assigned by hand, assigned automatically, or
 *   transferred straight to an agent from a queue. Not when the agent who
 *   attended it earlier in this cycle takes it again (released and
 *   accepted back, for instance);
 * - «Transferido a otro agente» when it goes from one agent to another
 *   (assigned or transferred);
 * - «Transferido a departamento» when it is transferred to another
 *   department's queue, with nobody attending it yet (the chatbot's own
 *   routing is not a transfer: its option has a reply of its own).
 *
 * Never for imported or history conversations, nor before the customer
 * ever wrote (a conversation the team started), nor for an event processed
 * more than SendAutomaticMessages::MAX_GREETING_DELAY_MINUTES late. The
 * customer service window, the connection state and idempotency are
 * QueueAutomaticMessage's (handleAction()).
 */
class SendActionMessages implements ShouldQueue
{
    /**
     * Events that may change who attends the conversation, or where.
     *
     * @var list<ConversationEventType>
     */
    private const ACTION_EVENTS = [
        ConversationEventType::Accepted,
        ConversationEventType::Assigned,
        ConversationEventType::AutoAssigned,
        ConversationEventType::Transferred,
    ];

    /**
     * Events after which an agent takes the conversation (to_user_id).
     *
     * @var list<ConversationEventType>
     */
    private const ATTENTION_EVENTS = self::ACTION_EVENTS;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(
        private AutomaticMessages $messages,
        private QueueAutomaticMessage $queueMessage,
        private ChatbotMenus $menus,
    ) {}

    public function viaQueue(): string
    {
        return Queues::name(Queues::MESSAGES);
    }

    /**
     * Only the team's actions queue a job; every other change does not.
     */
    public function shouldQueue(ConversationChanged $event): bool
    {
        return $event->eventId !== null
            && in_array(ConversationEventType::tryFrom($event->reason), self::ACTION_EVENTS, true);
    }

    public function handle(ConversationChanged $event): void
    {
        if (! $this->shouldQueue($event)) {
            return;
        }

        $record = ConversationEvent::query()->find($event->eventId);
        $conversation = Conversation::query()->with('channelConnection')->find($event->conversationId);
        $connection = $conversation?->channelConnection;

        if ($record === null || $conversation === null || $connection === null || $record->conversation_id !== $conversation->id) {
            return;
        }

        $kind = $this->kindFor($record);

        if ($kind === null) {
            return;
        }

        // The first person to take it (accepting, with an exception or not,
        // or assigning it): the chatbot's welcome goes first when this cycle
        // has not had it yet, before anybody writes.
        if ($kind === AutomaticMessageKind::AgentAssigned) {
            $this->welcomeFirst($conversation, $record);
        }

        $agent = $record->to_user_id === null ? null : User::query()->find($record->to_user_id, ['id', 'name']);
        $plan = $this->messages->action($connection, $kind, $agent);

        if ($plan === null) {
            return;
        }

        $reason = match (true) {
            $conversation->isImported() || $conversation->isHistoryOnly() => 'imported_conversation',
            // The team started it: there is nobody waiting to be greeted.
            $conversation->last_inbound_at === null => 'customer_never_wrote',
            $record->created_at->lessThan(now()->subMinutes(SendAutomaticMessages::MAX_GREETING_DELAY_MINUTES)) => 'processed_late',
            $kind === AutomaticMessageKind::AgentAssigned && $this->attendedBefore($record) => 'same_agent',
            $kind !== AutomaticMessageKind::DepartmentTransfer && $agent === null => 'agent_not_found',
            default => null,
        };

        if ($reason !== null) {
            Log::info('Automatic message not sent.', [
                'conversation_id' => $conversation->id,
                'connection_id' => $conversation->connection_id,
                'kind' => $kind->value,
                'reason' => $reason,
            ]);

            return;
        }

        $this->queueMessage->handleAction($conversation, $plan, $record);
    }

    /**
     * The welcome of a chatbot without options, once per cycle: its
     * reference is the one the cycle's start uses (SendAutomaticMessages),
     * so a welcome already sent is never repeated. Not for imported
     * conversations, a conversation the team started, nor an event
     * processed too late.
     */
    private function welcomeFirst(Conversation $conversation, ConversationEvent $record): void
    {
        $greeting = $this->menus->greetingOnly($conversation->channelConnection);

        if ($greeting === null
            || $conversation->isImported()
            || $conversation->isHistoryOnly()
            || $conversation->last_inbound_at === null
            || $record->created_at->lessThan(now()->subMinutes(SendAutomaticMessages::MAX_GREETING_DELAY_MINUTES))) {
            return;
        }

        $this->queueMessage->handle($conversation, new AutomaticMessagePlan(AutomaticMessageKind::ChatbotMenu, $greeting, 'chatbot'), $conversation->reopen_count, $record->id);
    }

    /**
     * Which message the event calls for, if any.
     */
    private function kindFor(ConversationEvent $record): ?AutomaticMessageKind
    {
        if ($record->to_user_id === null) {
            return $record->type === ConversationEventType::Transferred
                && $record->to_department_id !== null
                && $record->to_department_id !== $record->from_department_id
                ? AutomaticMessageKind::DepartmentTransfer
                : null;
        }

        if ($record->from_user_id === $record->to_user_id) {
            return null;
        }

        return $record->from_user_id === null ? AutomaticMessageKind::AgentAssigned : AutomaticMessageKind::AgentTransfer;
    }

    /**
     * Whether the agent the event hands it to was the last one who attended
     * the conversation earlier in this cycle (since it was created or last
     * reopened): taking it back is not a new agent to introduce.
     */
    private function attendedBefore(ConversationEvent $record): bool
    {
        $cycleStart = ConversationEvent::query()
            ->where('conversation_id', $record->conversation_id)
            ->where('id', '<', $record->id)
            ->whereIn('type', [ConversationEventType::Created, ConversationEventType::Reopened])
            ->max('id');

        $previous = ConversationEvent::query()
            ->where('conversation_id', $record->conversation_id)
            ->where('id', '<', $record->id)
            ->when($cycleStart !== null, fn ($query) => $query->where('id', '>', $cycleStart))
            ->whereIn('type', self::ATTENTION_EVENTS)
            ->whereNotNull('to_user_id')
            ->latest('id')
            ->value('to_user_id');

        return $previous !== null && (int) $previous === $record->to_user_id;
    }
}
