<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use App\Support\ProtectedAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the dashboard overview from real data only.
 *
 * A metric is `null` when the user may not see it, or when its source does
 * not exist yet (while no channel, WhatsApp or web chat, has exchanged real
 * traffic). The interface must render an empty state for `null` values
 * instead of zeros.
 *
 * Two reaches on purpose, labelled apart in the interface:
 * - live figures (in attention, pending, finished today) count the
 *   conversations the user can see in the inbox (Conversation::visibleTo);
 * - period figures (received, first human response, trend and every chart)
 *   come from the per-conversation metrics within the user's analytics
 *   reach (App\Services\Analytics\AnalyticsScope).
 * The overview and the charts are cached for a few seconds per reach and
 * filters, each on its own key (the charts are a separate deferred prop);
 * the activity buckets both need are cached once, on a key they share.
 *
 * @phpstan-type Overview array{
 *     scope: 'all'|'departments'|'own',
 *     messaging_connected: bool,
 *     conversations_visible: bool,
 *     conversations: array{active: int|null, pending: int|null, closed_today: int|null},
 *     average_first_response_seconds: int|null,
 *     previous_first_response_seconds: int|null,
 *     first_response_samples: int,
 *     received: array{value: int|null, previous: int|null},
 *     trend: list<array{label: string, received: int}>,
 *     trend_granularity: 'hour'|'day'|'month',
 *     agents: array{active: int|null},
 *     departments: array{active: int, total: int}|null,
 * }
 *
 * @phpstan-import-type Activity from OperationalAnalytics
 * @phpstan-import-type ChannelRow from OperationalAnalytics
 * @phpstan-import-type BreakdownRow from OperationalAnalytics
 *
 * @phpstan-type AgentBar array{key: string, id: int|null, name: string, received: int, attended: int, first_response_seconds: int|null, first_response_samples: int}
 * @phpstan-type DepartmentBar array{key: string, id: int|null, name: string, received: int, resolved: int, first_response_seconds: int|null, first_response_samples: int}
 * @phpstan-type Charts array{
 *     activity: Activity|null,
 *     channels: list<ChannelRow>|null,
 *     statuses: list<array{status: string, label: string, count: int}>|null,
 *     agents: list<AgentBar>|null,
 *     departments: list<DepartmentBar>|null,
 * }
 */
class DashboardMetrics
{
    /**
     * Seconds the overview is cached for the same reach and filters.
     */
    public const TTL = 30;

    /**
     * Agents or departments charted by name; the rest are summed as "Otros".
     */
    public const CHART_TOP = 8;

    public function __construct(
        private PlatformSettings $settings,
        private OperationalAnalytics $analytics,
        private AnalyticsScope $scope,
        private AnalyticsCache $cache,
    ) {}

    /**
     * Filters of the dashboard from (validated) query input.
     *
     * @param  array<string, mixed>  $input
     */
    public function filters(User $user, array $input = []): AnalyticsFilters
    {
        return AnalyticsFilters::from($input, $user, $this->scope, (string) $this->settings->get('general.timezone'));
    }

    /**
     * Every figure is limited to the user's scope: conversations to those the
     * user can see, team figures to the user's departments. "Finished today"
     * counts from the start of the day of $asOf (now by default; an export
     * passes the moment it was requested, like its own tables).
     *
     * @return Overview
     *
     * @throws AuthorizationException without dashboard.view.
     */
    public function overviewFor(User $user, ?AnalyticsFilters $filters = null, ?CarbonImmutable $asOf = null): array
    {
        if (! $user->holdsPermission(Permission::ViewDashboard)) {
            throw new AuthorizationException('No tienes permiso para ver el panel principal.');
        }

        $filters ??= $this->filters($user);
        $startOfToday = ($asOf ?? CarbonImmutable::now())->setTimezone((string) $this->settings->get('general.timezone'))->startOfDay()->utc();

        return $this->cache->remember(
            "dashboard:{$this->scope->fingerprint($user)}:{$filters->cacheKey()}:{$startOfToday->getTimestamp()}",
            self::TTL,
            fn (): array => $this->compute($user, $filters, $startOfToday),
        );
    }

    /**
     * Data of the dashboard charts within the user's analytics reach and the
     * same filters as the overview. A chart is null when the user may not
     * see it (or no channel has traffic yet): the interface does not draw
     * it. An empty list or buckets of 0 mean "no data in the period".
     *
     * - agents: only for a non personal reach with reports.view or
     *   conversations.supervise;
     * - departments: only with departments.view and a global reach or a
     *   departments reach over more than one department.
     *
     * @return Charts
     *
     * @throws AuthorizationException without dashboard.view.
     */
    public function chartsFor(User $user, ?AnalyticsFilters $filters = null): array
    {
        if (! $user->holdsPermission(Permission::ViewDashboard)) {
            throw new AuthorizationException('No tienes permiso para ver el panel principal.');
        }

        $filters ??= $this->filters($user);

        return $this->cache->remember(
            "dashboard:{$this->scope->fingerprint($user)}:{$filters->cacheKey()}:charts",
            self::TTL,
            fn (): array => $this->computeCharts($user, $filters),
        );
    }

    /**
     * @return Charts
     */
    private function computeCharts(User $user, AnalyticsFilters $filters): array
    {
        if (! $this->messagingConnected() || ! $user->holdsPermission(Permission::ViewConversations)) {
            return ['activity' => null, 'channels' => null, 'statuses' => null, 'agents' => null, 'departments' => null];
        }

        $personal = $this->scope->isPersonal($user);
        $showsAgents = ! $personal && ($user->holdsPermission(Permission::ViewReports) || $user->holdsPermission(Permission::SuperviseConversations));
        $showsDepartments = ! $personal
            && $user->holdsPermission(Permission::ViewDepartments)
            && ($user->dataScope() === DataScope::Global || count($user->departmentIds()) > 1);

        return [
            'activity' => $this->activity($user, $filters),
            'channels' => $this->analytics->byChannel($user, $filters),
            'statuses' => $this->analytics->statuses($user, $filters),
            'agents' => $showsAgents
                ? array_map(fn (array $bar): array => [
                    'key' => $bar['key'],
                    'id' => $bar['id'],
                    'name' => $bar['name'],
                    'received' => $bar['received'],
                    'attended' => $bar['attended'],
                    'first_response_seconds' => $bar['first_response_seconds'],
                    'first_response_samples' => $bar['first_response_samples'],
                ], $this->top($this->analytics->breakdown($user, $filters, 'agent_id')))
                : null,
            'departments' => $showsDepartments
                ? array_map(fn (array $bar): array => [
                    'key' => $bar['key'],
                    'id' => $bar['id'],
                    'name' => $bar['name'],
                    'received' => $bar['received'],
                    'resolved' => $bar['resolved'],
                    'first_response_seconds' => $bar['first_response_seconds'],
                    'first_response_samples' => $bar['first_response_samples'],
                ], $this->top($this->analytics->breakdown($user, $filters, 'department_id')))
                : null,
        ];
    }

    /**
     * The CHART_TOP largest rows (already sorted, most received first) and
     * one "Otros" row summing the rest, so no volume is lost. The average
     * of "Otros" is weighted by the measured conversations of each row.
     *
     * @param  list<BreakdownRow>  $rows
     * @return list<array{key: string, id: int|null, name: string, received: int, attended: int, resolved: int, first_response_seconds: int|null, first_response_samples: int}>
     */
    private function top(array $rows): array
    {
        $result = [];

        foreach (array_slice($rows, 0, self::CHART_TOP) as $row) {
            $result[] = [
                'key' => match (true) {
                    $row['id'] !== null => (string) $row['id'],
                    $row['name'] === ProtectedAccounts::SYSTEM_NAME => 'system',
                    default => 'none',
                },
                'id' => $row['id'],
                'name' => $row['name'],
                'received' => $row['received'],
                'attended' => $row['attended'],
                'resolved' => $row['resolved'],
                'first_response_seconds' => $row['first_response_seconds'],
                'first_response_samples' => $row['first_response_samples'],
            ];
        }

        $rest = array_slice($rows, self::CHART_TOP);

        if ($rest === []) {
            return $result;
        }

        $weighted = 0;
        $samples = (int) array_sum(array_column($rest, 'first_response_samples'));

        foreach ($rest as $row) {
            $weighted += ($row['first_response_seconds'] ?? 0) * $row['first_response_samples'];
        }

        $result[] = [
            'key' => 'others',
            'id' => null,
            'name' => 'Otros',
            'received' => (int) array_sum(array_column($rest, 'received')),
            'attended' => (int) array_sum(array_column($rest, 'attended')),
            'resolved' => (int) array_sum(array_column($rest, 'resolved')),
            'first_response_seconds' => $samples > 0 ? (int) round($weighted / $samples) : null,
            'first_response_samples' => $samples,
        ];

        return $result;
    }

    /**
     * Activity buckets of the period, shared by the overview (trend) and the
     * charts: one GROUP BY per reach and filters within the TTL.
     *
     * @return Activity
     */
    private function activity(User $user, AnalyticsFilters $filters): array
    {
        return $this->cache->remember(
            "dashboard:{$this->scope->fingerprint($user)}:{$filters->cacheKey()}:activity",
            self::TTL,
            fn (): array => $this->analytics->activity($user, $filters),
        );
    }

    /**
     * @return Overview
     */
    private function compute(User $user, AnalyticsFilters $filters, CarbonImmutable $startOfToday): array
    {
        $messagingConnected = $this->messagingConnected();
        $canSeeTeam = $user->holdsPermission(Permission::ViewDepartments);
        $conversationsVisible = $user->holdsPermission(Permission::ViewConversations);
        $canSeeConversations = $messagingConnected && $conversationsVisible;
        $summary = $canSeeConversations ? $this->analytics->summary($user, $filters) : null;
        $activity = $canSeeConversations ? $this->activity($user, $filters) : null;

        return [
            'scope' => $this->scope->label($user),
            'messaging_connected' => $messagingConnected,
            // Without it, figures are null because of the role, not of the channels.
            'conversations_visible' => $conversationsVisible,
            'conversations' => $canSeeConversations
                ? $this->conversationCounts($user, $filters, $startOfToday)
                : ['active' => null, 'pending' => null, 'closed_today' => null],
            'average_first_response_seconds' => $summary['first_response_seconds']['value'] ?? null,
            'previous_first_response_seconds' => $summary['first_response_seconds']['previous'] ?? null,
            'first_response_samples' => $summary['first_response_seconds']['samples'] ?? 0,
            'received' => [
                'value' => $summary === null ? null : (int) $summary['received']['value'],
                'previous' => $summary === null ? null : (int) $summary['received']['previous'],
            ],
            // Per hour for a period of one day (a single daily point is no trend).
            'trend' => $activity === null ? [] : array_map(
                fn (array $point): array => ['label' => $point['label'], 'received' => $point['received']],
                $activity['points'],
            ),
            'trend_granularity' => $this->analytics->activityGranularity($filters->period),
            'agents' => [
                // Active accounts with an active role that can attend (no manual availability).
                'active' => $canSeeTeam ? $this->agents($user, $filters)->count() : null,
            ],
            'departments' => $canSeeTeam ? $this->departmentCounts($user) : null,
        ];
    }

    /**
     * Whether any active channel (a WhatsApp number or the web chat) already
     * exchanged real traffic (cached briefly: it requires inspecting every
     * active connection).
     */
    private function messagingConnected(): bool
    {
        return Cache::remember('dashboard:messaging-connected', 60, fn (): bool => Connection::query()
            ->active()
            ->with('webChatWidget')
            ->get()
            ->contains(fn (Connection $connection): bool => $connection->configurationHealth()->hasConfirmedTraffic()));
    }

    /**
     * @return array{active: int, pending: int, closed_today: int}
     */
    private function conversationCounts(User $user, AnalyticsFilters $filters, CarbonImmutable $startOfToday): array
    {
        /** @var object{active: int|string|null, pending: int|string|null, closed_today: int|string|null} $counts */
        $counts = Conversation::query()
            ->visibleTo($user)
            ->when($filters->departmentId !== null, fn (Builder $query) => $query->where('conversations.department_id', $filters->departmentId))
            ->when($filters->agentId !== null, fn (Builder $query) => $query->where('conversations.assigned_user_id', $filters->agentId))
            ->when($filters->connectionId !== null, fn (Builder $query) => $query->where('conversations.connection_id', $filters->connectionId))
            ->toBase()
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS active', array_map(fn (ConversationStatus $status): string => $status->value, ConversationStatus::attending()))
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending', [ConversationStatus::Pending->value])
            ->selectRaw('SUM(CASE WHEN status = ? AND closed_at >= ? THEN 1 ELSE 0 END) AS closed_today', [ConversationStatus::Closed->value, $startOfToday])
            ->first();

        return [
            'active' => (int) $counts->active,
            'pending' => (int) $counts->pending,
            'closed_today' => (int) $counts->closed_today,
        ];
    }

    /**
     * Active users whose role lets them attend conversations, within the
     * viewer's scope and the department/agent filters.
     *
     * @return Builder<User>
     */
    private function agents(User $user, AnalyticsFilters $filters): Builder
    {
        return User::query()
            ->withoutProtectedFor($user)
            ->active()
            ->withPermission(Permission::ReplyConversations)
            ->when(
                $user->dataScope() !== DataScope::Global,
                fn (Builder $query) => $query->whereHas(
                    'departments',
                    fn (Builder $departments) => $departments->whereKey($user->departmentIds()),
                ),
            )
            ->when($filters->departmentId !== null, fn (Builder $query) => $query->whereHas(
                'departments',
                fn (Builder $departments) => $departments->whereKey($filters->departmentId),
            ))
            ->when($filters->agentId !== null, fn (Builder $query) => $query->whereKey($filters->agentId));
    }

    /**
     * @return array{active: int, total: int}
     */
    private function departmentCounts(User $user): array
    {
        /** @var object{total: int|string|null, active: int|string|null} $counts */
        $counts = Department::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END) AS active', [true])
            ->first();

        return [
            'active' => (int) $counts->active,
            'total' => (int) $counts->total,
        ];
    }
}
