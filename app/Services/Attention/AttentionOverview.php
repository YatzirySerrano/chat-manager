<?php

namespace App\Services\Attention;

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Models\AttentionAlert;
use App\Models\AttentionAlertRecipient;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\PendingAttention;
use App\Support\Personalization\ViewerAlias;
use App\Support\ProtectedAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What «Seguimiento de atención» shows, always within the viewer's reach:
 * the agents they supervise (AttentionAudience) with what each one attends,
 * the customers waiting for an answer and for how long, the queue without
 * agent and the latest alerts.
 *
 * States are told apart as PendingAttention defines them: «Esperando
 * respuesta del agente» (the customer wrote last), «Esperando al cliente»
 * (the team answered last) and «En cola» (no agent yet). An open
 * conversation is never counted as unattended by itself.
 *
 * Nothing here claims who is connected: the last activity comes from the
 * sessions table (only with the database session driver; null otherwise)
 * and the page adds «En línea» only from the real-time presence channels
 * it already joins.
 *
 * Every figure comes from a few aggregate queries (one per block), never
 * one per agent or conversation.
 */
class AttentionOverview
{
    /** Waiting conversations listed at most (oldest first). */
    public const WAITING_LIMIT = 100;

    /** Latest alerts listed in the history. */
    public const ALERTS_LIMIT = 30;

    public function __construct(
        private AttentionAudience $audience,
        private PendingAttention $pending,
        private ProtectedAccounts $protectedAccounts,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function agents(User $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        /** @var Collection<int, User> $agents */
        $agents = $this->audience->agents($viewer)
            // Roles and direct permissions eager loaded: whether the control
            // applies to each agent is then decided without more queries.
            ->with(['departments:id,name', 'roles', 'permissions'])
            ->orderBy('users.name')
            ->get();

        if ($agents->isEmpty()) {
            return [];
        }

        $ids = array_values(array_map('intval', $agents->modelKeys()));
        $threshold = $this->pending->threshold($now);
        $awaiting = 'conversations.last_inbound_at IS NOT NULL AND (conversations.last_outbound_at IS NULL OR conversations.last_inbound_at > conversations.last_outbound_at)';
        $clock = PendingAttention::CLOCK_SQL;

        $loads = Conversation::query()
            ->visibleTo($viewer)
            ->live()
            ->notHistoryOnly()
            ->whereIn('conversations.assigned_user_id', $ids)
            ->whereIn('conversations.status', ConversationStatus::attending())
            ->toBase()
            ->groupBy('conversations.assigned_user_id')
            ->select('conversations.assigned_user_id')
            ->selectRaw('COUNT(*) as in_attention')
            ->selectRaw("SUM(CASE WHEN {$awaiting} THEN 1 ELSE 0 END) as awaiting")
            ->selectRaw("SUM(CASE WHEN {$awaiting} AND {$clock} <= ? THEN 1 ELSE 0 END) as overdue", [$threshold])
            ->selectRaw("MIN(CASE WHEN {$awaiting} THEN conversations.last_inbound_at END) as oldest_waiting")
            ->get()
            ->keyBy('assigned_user_id');

        $activity = $this->lastActivity($ids);

        // The block counts every conversation of the agent, not only those in
        // the viewer's reach (a yes/no that reveals no conversation).
        $blockedIds = $this->pending->isEnabled()
            ? array_flip(array_map('intval', $this->pending->overdue(Conversation::query())
                ->whereIn('conversations.assigned_user_id', $ids)
                ->distinct()
                ->pluck('conversations.assigned_user_id')
                ->all()))
            : [];

        return array_values($agents->map(function (User $agent) use ($loads, $activity, $blockedIds): array {
            $load = $loads->get($agent->id);
            $inAttention = (int) ($load->in_attention ?? 0);
            $awaiting = (int) ($load->awaiting ?? 0);
            $oldest = is_string($load->oldest_waiting ?? null) ? CarbonImmutable::parse($load->oldest_waiting) : null;

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'role' => $agent->role()?->label,
                'departments' => array_values($agent->departments->map(fn ($department): array => ['id' => $department->id, 'name' => $department->name])->all()),
                'in_attention' => $inAttention,
                'awaiting' => $awaiting,
                'waiting_customer' => max(0, $inAttention - $awaiting),
                'overdue' => (int) ($load->overdue ?? 0),
                'oldest_waiting_since' => $oldest?->toIso8601String(),
                'blocked' => isset($blockedIds[$agent->id]) && $this->pending->appliesTo($agent),
                'last_activity_at' => $activity[$agent->id] ?? null,
                'last_login_at' => $agent->last_login_at?->toIso8601String(),
            ];
        })->all());
    }

    /**
     * Customers waiting for their agent's answer, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function waiting(User $viewer, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        /** @var Collection<int, Conversation> $rows */
        $rows = $this->pending->awaiting(Conversation::query()->visibleTo($viewer))
            ->whereIn('conversations.assigned_user_id', $this->audience->agents($viewer)->select('users.id'))
            ->with(['contact:id,name,profile_name,phone_e164', 'assignee:id,name', 'department:id,name,color,is_active'])
            ->orderBy('conversations.last_inbound_at')
            ->orderBy('conversations.id')
            ->limit(self::WAITING_LIMIT)
            ->get(['conversations.id', 'conversations.contact_id', 'conversations.assigned_user_id', 'conversations.department_id', 'conversations.status', 'conversations.last_inbound_at', 'conversations.last_outbound_at', 'conversations.assigned_at']);

        ViewerAlias::load($rows, $viewer);

        return array_values($rows->map(fn (Conversation $conversation): array => [
            'id' => $conversation->id,
            'contact' => ViewerAlias::displayName($conversation->contact, ViewerAlias::of($conversation->contact, $viewer)),
            'agent' => $conversation->assignee === null ? null : [
                'id' => $conversation->assignee->id,
                'name' => $this->protectedAccounts->nameFor($conversation->assignee, $viewer),
            ],
            'department' => $conversation->department === null ? null : [
                'id' => $conversation->department->id,
                'name' => $conversation->department->name,
                'color' => $conversation->department->color->value,
            ],
            'waiting_since' => $conversation->last_inbound_at?->toIso8601String(),
            'overdue' => $this->pending->isOverdue($conversation, $now),
            'url' => route('conversations.show', $conversation),
        ])->all());
    }

    /**
     * Pending conversations without agent (queues) within the viewer's reach.
     *
     * @return array{count: int, oldest_since: string|null}
     */
    public function queue(User $viewer): array
    {
        $row = Conversation::query()
            ->visibleTo($viewer)
            ->live()
            ->notHistoryOnly()
            ->where('conversations.status', ConversationStatus::Pending)
            ->whereNull('conversations.assigned_user_id')
            ->toBase()
            ->selectRaw('COUNT(*) as total, MIN(COALESCE(conversations.last_inbound_at, conversations.created_at)) as oldest')
            ->first();

        return [
            'count' => (int) ($row->total ?? 0),
            'oldest_since' => is_string($row->oldest ?? null) ? CarbonImmutable::parse($row->oldest)->toIso8601String() : null,
        ];
    }

    /**
     * The latest alerts the viewer may consult: everyone's with global
     * scope, their own otherwise. Recipients are counted per state in the
     * same query.
     *
     * @return list<array<string, mixed>>
     */
    public function alerts(User $viewer): array
    {
        /** @var Collection<int, AttentionAlert> $alerts */
        $alerts = AttentionAlert::query()
            ->when($viewer->dataScope() !== DataScope::Global, fn (Builder $query) => $query->where('sender_id', $viewer->id))
            ->with('sender:id,name')
            ->withCount($this->recipientCounts($viewer))
            ->latest('id')
            ->limit(self::ALERTS_LIMIT)
            ->get();

        return array_values($alerts->map(fn (AttentionAlert $alert): array => $this->presentAlert($alert, $viewer))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAlert(AttentionAlert $alert, User $viewer): array
    {
        return [
            'id' => $alert->id,
            'title' => $alert->title,
            'body' => $alert->body,
            'priority' => $alert->priority->value,
            'priority_label' => $alert->priority->label(),
            'audience' => $alert->audience->value,
            'audience_label' => $alert->audience->label(),
            'color' => $alert->color->value,
            'sound' => $alert->sound->value,
            'link_pending' => $alert->action === AttentionAlert::ACTION_PENDING_CONVERSATIONS,
            'sender' => $this->protectedAccounts->nameFor($alert->sender, $viewer) ?? 'Usuario eliminado',
            'sent_at' => $alert->created_at?->toIso8601String(),
            // Counted like recipients(): accounts the viewer must not see are left out.
            'recipients_count' => (int) ($alert->getAttribute('visible_recipients_count') ?? $alert->recipients_count),
            'delivered_count' => (int) $alert->getAttribute('delivered_count'),
            'acknowledged_count' => (int) $alert->getAttribute('acknowledged_count'),
        ];
    }

    /**
     * Recipient counts of an alert as the viewer may know them (protected
     * accounts never counted for people who must not see them).
     *
     * @return array<string, \Closure(Builder<AttentionAlertRecipient>): mixed>
     */
    public function recipientCounts(User $viewer): array
    {
        $visible = fn (Builder $query) => $query->whereIn('attention_alert_recipients.user_id', User::query()->withoutProtectedFor($viewer)->select('users.id'));

        return [
            'recipients as visible_recipients_count' => $visible,
            'recipients as delivered_count' => fn (Builder $query) => $visible($query)->whereNotNull('delivered_at'),
            'recipients as acknowledged_count' => fn (Builder $query) => $visible($query)->whereNotNull('acknowledged_at'),
        ];
    }

    /**
     * Whether the viewer may consult this alert's detail (same rule as alerts()).
     */
    public function canSeeAlert(User $viewer, AttentionAlert $alert): bool
    {
        return $viewer->dataScope() === DataScope::Global || $alert->sender_id === $viewer->id;
    }

    /**
     * Each recipient of an alert with its delivery and acknowledgement,
     * leaving out accounts the viewer must not see.
     *
     * @return list<array{id: int, name: string|null, delivered_at: string|null, acknowledged_at: string|null}>
     */
    public function recipients(AttentionAlert $alert, User $viewer): array
    {
        $rows = $alert->recipients()
            ->with('user:id,name')
            ->whereHas('user', fn (Builder $users) => $users->withoutProtectedFor($viewer))
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn ($row): array => [
            'id' => $row->user_id,
            'name' => $row->user?->name,
            'delivered_at' => $row->delivered_at?->toIso8601String(),
            'acknowledged_at' => $row->acknowledged_at?->toIso8601String(),
        ])->all());
    }

    /**
     * Latest request of each user's open sessions (database session driver
     * only), as ISO-8601 dates keyed by user id.
     *
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function lastActivity(array $userIds): array
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable((string) config('session.table', 'sessions'))) {
            return [];
        }

        return DB::table((string) config('session.table', 'sessions'))
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(last_activity) as last_activity')
            ->pluck('last_activity', 'user_id')
            ->mapWithKeys(fn (mixed $timestamp, mixed $userId): array => [(int) $userId => CarbonImmutable::createFromTimestamp((int) $timestamp)->toIso8601String()])
            ->all();
    }
}
