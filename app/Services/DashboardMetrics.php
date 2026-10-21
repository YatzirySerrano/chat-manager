<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the dashboard overview from real data only.
 *
 * A metric is `null` when the user may not see it, or when its source does not exist yet (for example, while no
 * WhatsApp number is connected, or while agent presence is not implemented).
 * The interface must render an empty state for `null` values instead of zeros.
 *
 * @phpstan-type Overview array{
 *     scope: 'all'|'departments'|'own',
 *     messaging_connected: bool,
 *     conversations: array{active: int|null, pending: int|null, closed_today: int|null},
 *     average_first_response_seconds: int|null,
 *     agents: array{registered: int|null, available: int|null},
 *     departments: array{active: int, total: int}|null,
 * }
 */
class DashboardMetrics
{
    public function __construct(private PlatformSettings $settings) {}

    /**
     * Every figure is limited to the user's scope: conversations to those the
     * user can see, team figures to the user's departments.
     *
     * @return Overview
     *
     * @throws AuthorizationException without dashboard.view.
     */
    public function overviewFor(User $user): array
    {
        if (! $user->holdsPermission(Permission::ViewDashboard)) {
            throw new AuthorizationException('The dashboard requires the dashboard.view permission.');
        }

        $messagingConnected = Connection::query()
            ->active()
            ->get()
            ->contains(fn (Connection $connection): bool => $connection->configurationHealth()->hasConfirmedTraffic());
        $canSeeTeam = $user->holdsPermission(Permission::ViewDepartments);
        $canSeeConversations = $messagingConnected && $user->holdsPermission(Permission::ViewConversations);

        return [
            'scope' => $this->scopeFor($user),
            'messaging_connected' => $messagingConnected,
            'conversations' => $canSeeConversations
                ? $this->conversationCounts($user)
                : ['active' => null, 'pending' => null, 'closed_today' => null],
            'average_first_response_seconds' => $canSeeConversations ? $this->averageFirstResponseSeconds($user) : null,
            'agents' => [
                'registered' => $canSeeTeam ? $this->registeredAgents($user) : null,
                'available' => null,
            ],
            'departments' => $canSeeTeam ? $this->departmentCounts($user) : null,
        ];
    }

    /**
     * @return 'all'|'departments'|'own'
     */
    private function scopeFor(User $user): string
    {
        return match ($user->dataScope()) {
            DataScope::Global => 'all',
            DataScope::Departments => 'departments',
            default => 'own',
        };
    }

    /**
     * @return array{active: int, pending: int, closed_today: int}
     */
    private function conversationCounts(User $user): array
    {
        $startOfToday = now((string) $this->settings->get('general.timezone'))->startOfDay()->utc();

        /** @var object{active: int|string|null, pending: int|string|null, closed_today: int|string|null} $counts */
        $counts = Conversation::query()
            ->visibleTo($user)
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
     * Average time between a conversation being created and the first agent
     * reply, over the last 30 days. Null when there is no answered conversation.
     */
    private function averageFirstResponseSeconds(User $user): ?int
    {
        $average = Conversation::query()
            ->visibleTo($user)
            ->whereNotNull('first_response_at')
            ->where('created_at', '>=', now()->subDays(30))
            ->toBase()
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, first_response_at)) AS average_seconds')
            ->value('average_seconds');

        return $average === null ? null : (int) round((float) $average);
    }

    /**
     * Active users whose role lets them attend conversations, within the viewer's scope.
     */
    private function registeredAgents(User $user): int
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
            ->count();
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
