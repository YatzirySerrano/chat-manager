<?php

namespace App\Broadcasting;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Events\Broadcasts\RealtimeSignal;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
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
 *
 * It is a container singleton so a long-lived queue worker reuses, for a
 * short while (SUPERVISED_DEPARTMENTS_TTL), the departments that have a
 * departments-scope supervisor (supervisedDepartmentIds()).
 */
#[Singleton]
class RealtimeSignals
{
    /**
     * Seconds the departments with a departments-scope supervisor are reused
     * before being read again. Bounded so a supervisor appointed meanwhile
     * hears the passages of their departments within a minute (their own
     * departments' inbox channels never depend on it).
     */
    private const SUPERVISED_DEPARTMENTS_TTL = 60;

    /** @var list<int>|null */
    private ?array $supervisedDepartments = null;

    private float $supervisedDepartmentsUntil = 0.0;

    public function __construct(private RealtimeAudience $audience) {}

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
     * Lightweight delivery update of a conversation's latest message for the
     * inbox lists: the same audience as inboxChanged() (inbox channels and
     * the responsible agent) in one publication. Lists patch the row's
     * status icon with it and never reload; the payload carries ids and
     * the state only, never content.
     *
     * @param  array{conversation_id: int, message_id: int, status: string, error_code: string|null, updated_at: string|null}  $payload
     */
    public function inboxMessageStatus(Conversation $conversation, array $payload): bool
    {
        $channels = $this->inboxChannels($conversation, 'message_status', null);

        if ($conversation->assigned_user_id !== null && $conversation->assigned_user_id > 0) {
            $channels[] = RealtimeChannels::user($conversation->assigned_user_id);
        }

        return $this->send($channels, 'inbox.message_status', $payload);
    }

    /**
     * Channels of every inbox that lists the conversation (global, its
     * departments, while queued its department queue and the supervision
     * channel of every other department it passed through, whose
     * departments-scope supervisors keep reading it; departments without
     * such a supervisor get none, since nobody may join it).
     *
     * @return list<string>
     */
    public function inboxChannels(Conversation $conversation, string $reason, ?ConversationEvent $event): array
    {
        $channels = [RealtimeChannels::globalInbox()];
        $departments = [];

        foreach ([$conversation->department_id, $event?->from_department_id, $event?->to_department_id] as $departmentId) {
            if ($departmentId !== null) {
                $departments[$departmentId] = true;
                $channels[] = RealtimeChannels::departmentInbox($departmentId);
            }
        }

        foreach ($this->passedDepartmentIds($conversation, array_keys($departments)) as $departmentId) {
            $channels[] = RealtimeChannels::departmentSupervision($departmentId);
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

    /**
     * Other departments the conversation moved from or to (its events) whose
     * supervision channel somebody may join: only departments with a
     * departments-scope supervisor (supervisedDepartmentIds()) and not
     * already reached through their department inbox ($excluded). When no
     * such department remains, nothing is read; otherwise one lookup on the
     * conversation_events(conversation_id, ...) index.
     *
     * @param  list<int>  $excluded
     * @return list<int>
     */
    private function passedDepartmentIds(Conversation $conversation, array $excluded): array
    {
        $candidates = array_values(array_diff($this->supervisedDepartmentIds(), $excluded));

        if ($candidates === []) {
            return [];
        }

        $ids = [];

        $passages = DB::table('conversation_events')
            ->where('conversation_id', $conversation->id)
            ->where(fn (QueryBuilder $passage) => $passage->whereIn('from_department_id', $candidates)->orWhereIn('to_department_id', $candidates))
            ->distinct()
            ->get(['from_department_id', 'to_department_id']);

        foreach ($passages as $passage) {
            foreach ([$passage->from_department_id, $passage->to_department_id] as $departmentId) {
                if (is_numeric($departmentId) && in_array((int) $departmentId, $candidates, true)) {
                    $ids[(int) $departmentId] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Departments with at least one member who may join their supervision
     * channel (RealtimeAudience::canJoinDepartmentSupervision()): active
     * members whose role has the departments scope and who supervise it.
     * Read once per SUPERVISED_DEPARTMENTS_TTL seconds per process.
     *
     * @return list<int>
     */
    private function supervisedDepartmentIds(): array
    {
        if ($this->supervisedDepartments !== null && microtime(true) < $this->supervisedDepartmentsUntil) {
            return $this->supervisedDepartments;
        }

        $ids = [];

        User::query()
            ->where('is_active', true)
            ->whereHas('departments')
            ->whereHas('roles', fn (Builder $role) => $role->where('scope', DataScope::Departments->value))
            ->with(['roles', 'permissions', 'departments:id'])
            ->get()
            ->each(function (User $user) use (&$ids): void {
                if ($user->isDeleted()) {
                    return;
                }

                foreach ($user->departmentIds() as $departmentId) {
                    if (! isset($ids[$departmentId]) && $this->audience->canJoinDepartmentSupervision($user, $departmentId)) {
                        $ids[$departmentId] = true;
                    }
                }
            });

        $this->supervisedDepartmentsUntil = microtime(true) + self::SUPERVISED_DEPARTMENTS_TTL;

        return $this->supervisedDepartments = array_keys($ids);
    }
}
