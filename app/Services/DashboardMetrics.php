<?php

namespace App\Services;

use App\Enums\AgentAvailability;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the dashboard overview from real data only.
 *
 * A metric is `null` when the user may not see it, or when its source does
 * not exist yet (for example, while no WhatsApp number is connected).
 * The interface must render an empty state for `null` values instead of zeros.
 *
 * Live figures (in attention, pending, closed today) come from the
 * conversations the user can see; period figures (received, first human
 * response, trend) come from the per-conversation metrics within the
 * user's analytics reach (App\Services\Analytics\AnalyticsScope). The whole
 * overview is cached for a few seconds per reach and filters.
 *
 * @phpstan-type Overview array{
 *     scope: 'all'|'departments'|'own',
 *     messaging_connected: bool,
 *     conversations: array{active: int|null, pending: int|null, closed_today: int|null},
 *     average_first_response_seconds: int|null,
 *     previous_first_response_seconds: int|null,
 *     first_response_samples: int,
 *     received: array{value: int|null, previous: int|null},
 *     trend: list<array{label: string, received: int}>,
 *     agents: array{registered: int|null, available: int|null},
 *     departments: array{active: int, total: int}|null,
 * }
 */
class DashboardMetrics
{
    /**
     * Seconds the overview is cached for the same reach and filters.
     */
    public const TTL = 30;

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
     * user can see, team figures to the user's departments.
     *
     * @return Overview
     *
     * @throws AuthorizationException without dashboard.view.
     */
    public function overviewFor(User $user, ?AnalyticsFilters $filters = null): array
    {
        if (! $user->holdsPermission(Permission::ViewDashboard)) {
            throw new AuthorizationException('The dashboard requires the dashboard.view permission.');
        }

        $filters ??= $this->filters($user);

        return $this->cache->remember(
            "dashboard:{$this->scope->fingerprint($user)}:{$filters->cacheKey()}",
            self::TTL,
            fn (): array => $this->compute($user, $filters),
        );
    }

    /**
     * @return Overview
     */
    private function compute(User $user, AnalyticsFilters $filters): array
    {
        $messagingConnected = $this->messagingConnected();
        $canSeeTeam = $user->holdsPermission(Permission::ViewDepartments);
        $canSeeConversations = $messagingConnected && $user->holdsPermission(Permission::ViewConversations);
        $summary = $canSeeConversations ? $this->analytics->summary($user, $filters) : null;

        return [
            'scope' => $this->scope->label($user),
            'messaging_connected' => $messagingConnected,
            'conversations' => $canSeeConversations
                ? $this->conversationCounts($user, $filters)
                : ['active' => null, 'pending' => null, 'closed_today' => null],
            'average_first_response_seconds' => $summary['first_response_seconds']['value'] ?? null,
            'previous_first_response_seconds' => $summary['first_response_seconds']['previous'] ?? null,
            'first_response_samples' => $summary['first_response_seconds']['samples'] ?? 0,
            'received' => [
                'value' => $summary === null ? null : (int) $summary['received']['value'],
                'previous' => $summary === null ? null : (int) $summary['received']['previous'],
            ],
            'trend' => $canSeeConversations
                ? array_map(
                    fn (array $point): array => ['label' => $point['label'], 'received' => $point['received']],
                    $this->analytics->trend($user, $filters)['points'],
                )
                : [],
            'agents' => [
                'registered' => $canSeeTeam ? $this->agents($user, $filters)->count() : null,
                'available' => $canSeeTeam ? $this->agents($user, $filters)->where('availability', AgentAvailability::Available)->count() : null,
            ],
            'departments' => $canSeeTeam ? $this->departmentCounts($user) : null,
        ];
    }

    /**
     * Whether a WhatsApp number already exchanged real traffic (cached briefly:
     * it requires inspecting every active connection).
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
    private function conversationCounts(User $user, AnalyticsFilters $filters): array
    {
        $startOfToday = now((string) $this->settings->get('general.timezone'))->startOfDay()->utc();

        /** @var object{active: int|string|null, pending: int|string|null, closed_today: int|string|null} $counts */
        $counts = Conversation::query()
            ->visibleTo($user)
            ->when($filters->departmentId !== null, fn (Builder $query) => $query->where('conversations.department_id', $filters->departmentId))
            ->when($filters->agentId !== null, fn (Builder $query) => $query->where('conversations.assigned_user_id', $filters->agentId))
            ->when($filters->connectionId !== null, fn (Builder $query) => $query->where('conversations.connection_id', $filters->connectionId))
            ->toBase()
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS active', [ConversationStatus::Open->value, ConversationStatus::OnHold->value])
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
