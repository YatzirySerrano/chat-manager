<?php

namespace App\Broadcasting;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Events\Broadcasts\RealtimeSignal;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes real-time invalidation signals and decides who receives each
 * one. It is the single place other modules use to reach a browser
 * (notifications, team chat...), so payload rules stay in one spot.
 *
 * Delivery is best effort by design: the database stays the source of
 * truth and clients recover on reconnection and through their fallback
 * polling, so a stopped or slow Reverb server is logged and never breaks
 * the operation (or the queued listener) that produced the signal.
 */
class RealtimeSignals
{
    /**
     * Lifecycle changes that can take a conversation out of a department
     * queue; queue channels learn about them only when the conversation was
     * actually queued (no previous responsible agent).
     *
     * @var list<string>
     */
    private const LEAVES_QUEUE = ['accepted', 'assigned', 'auto_assigned', 'transferred', 'closed'];

    /**
     * Changes that only matter inside the conversation (its timeline), not
     * to inbox lists or counters.
     *
     * @var list<string>
     */
    private const CONVERSATION_ONLY = ['note_added', 'note_edited'];

    /**
     * Send one signal to the given channels (duplicates are removed).
     * Returns false only when the publication failed (and was logged).
     *
     * @param  iterable<string>  $channels
     * @param  array<string, int|string|bool|null>  $payload
     */
    public function send(iterable $channels, string $event, array $payload): bool
    {
        $names = [];

        foreach ($channels as $channel) {
            $names[$channel] = true;
        }

        if ($names === []) {
            return true;
        }

        try {
            broadcast(new RealtimeSignal(array_keys($names), $event, $payload));

            return true;
        } catch (Throwable $exception) {
            Log::warning('No se pudo publicar una señal de tiempo real; los clientes se recuperarán al reconectar o con la actualización periódica.', [
                'event' => $event,
                'channels' => count($names),
                'error' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * Send a signal to the personal channel of each user. Returns false only
     * when the publication failed.
     *
     * @param  iterable<int|null>  $userIds
     * @param  array<string, int|string|bool|null>  $payload
     */
    public function toUsers(iterable $userIds, string $event, array $payload): bool
    {
        $channels = [];

        foreach ($userIds as $userId) {
            if ($userId !== null && $userId > 0) {
                $channels[] = RealtimeChannels::user($userId);
            }
        }

        return $this->send($channels, $event, $payload);
    }

    /**
     * Signals for a change of a conversation: its own channel (people
     * looking at it) and, unless the change only concerns its timeline, the
     * inboxes that list it, before and after a transfer or reassignment.
     */
    public function conversationChanged(Conversation $conversation, string $reason, ?ConversationEvent $event = null): void
    {
        $this->send([RealtimeChannels::conversation($conversation->id)], 'conversation.changed', [
            'reason' => $reason,
            'version' => $conversation->version,
        ]);

        if ($reason === ConversationEventType::OutboundBlocked->value) {
            $this->toUsers([$conversation->assigned_user_id], 'inbox.changed', [
                'conversation_id' => $conversation->id,
                'reason' => $reason,
            ]);

            return;
        }

        if (in_array($reason, self::CONVERSATION_ONLY, true)) {
            return;
        }

        $this->inboxChanged($conversation, $reason, $event);
    }

    /**
     * Signal to every inbox that lists the conversation (and to the
     * responsible agents involved) that it must be refreshed.
     */
    public function inboxChanged(Conversation $conversation, string $reason, ?ConversationEvent $event = null): void
    {
        $payload = ['conversation_id' => $conversation->id, 'reason' => $reason];

        $this->send($this->inboxChannels($conversation, $reason, $event), 'inbox.changed', $payload);
        $this->toUsers([$conversation->assigned_user_id, $event?->from_user_id, $event?->to_user_id], 'inbox.changed', $payload);
    }

    /**
     * @return list<string>
     */
    private function inboxChannels(Conversation $conversation, string $reason, ?ConversationEvent $event): array
    {
        $channels = [RealtimeChannels::globalInbox()];

        foreach ([$conversation->department_id, $event?->from_department_id, $event?->to_department_id] as $departmentId) {
            if ($departmentId !== null) {
                $channels[] = RealtimeChannels::departmentInbox($departmentId);
            }
        }

        if ($conversation->status === ConversationStatus::Pending && $conversation->assigned_user_id === null && $conversation->department_id !== null) {
            $channels[] = RealtimeChannels::departmentQueue($conversation->department_id);
        }

        if ($event !== null && $event->from_user_id === null && in_array($reason, self::LEAVES_QUEUE, true)) {
            $queuedDepartment = $event->from_department_id ?? $event->to_department_id ?? $conversation->department_id;

            if ($queuedDepartment !== null) {
                $channels[] = RealtimeChannels::departmentQueue($queuedDepartment);
            }
        }

        return array_values(array_unique($channels));
    }
}
