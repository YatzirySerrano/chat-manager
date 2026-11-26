<?php

namespace App\Services\Conversations;

use App\Enums\MessageDirection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Who is working right now, for the automatic distribution and the
 * department page: an agent is "en sesión" while one of their open sessions
 * made a request in the last presence.online_minutes (database session
 * driver; a logout deletes the session). The automatic distribution only
 * hands requests to agents in session (presence.auto_assign_only_online),
 * so an account that never logs in, or someone who left, receives nothing
 * without being taken out of the department.
 */
class AgentPresence
{
    public function onlineMinutes(): int
    {
        return max(1, (int) config('presence.online_minutes', 15));
    }

    /**
     * Whether presence can be told (database sessions); when it cannot,
     * nobody is filtered out.
     */
    public function available(): bool
    {
        try {
            return config('session.driver') === 'database' && Schema::hasTable($this->table());
        } catch (Throwable) {
            return false;
        }
    }

    public function filtersDistribution(): bool
    {
        return (bool) config('presence.auto_assign_only_online', true) && $this->available();
    }

    /**
     * Latest request of each user's open sessions, keyed by user id.
     *
     * @param  list<int>  $userIds
     * @return array<int, CarbonImmutable>
     */
    public function lastActivity(array $userIds): array
    {
        if ($userIds === [] || ! $this->available()) {
            return [];
        }

        return DB::table($this->table())
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(last_activity) as last_activity')
            ->pluck('last_activity', 'user_id')
            ->mapWithKeys(fn (mixed $timestamp, mixed $userId): array => [(int) $userId => CarbonImmutable::createFromTimestamp((int) $timestamp)])
            ->all();
    }

    /**
     * The given users that are in session right now.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function online(array $userIds): array
    {
        $since = CarbonImmutable::now()->subMinutes($this->onlineMinutes());

        return array_map('intval', array_keys(array_filter(
            $this->lastActivity($userIds),
            fn (CarbonImmutable $at): bool => $at->greaterThanOrEqualTo($since),
        )));
    }

    /**
     * Last message each agent sent to a customer (last 30 days), keyed by
     * user id.
     *
     * @param  list<int>  $userIds
     * @return array<int, CarbonImmutable>
     */
    public function lastReplies(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('messages')
            ->whereIn('sender_user_id', $userIds)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(30))
            ->groupBy('sender_user_id')
            ->selectRaw('sender_user_id, MAX(created_at) as last_reply')
            ->pluck('last_reply', 'sender_user_id')
            ->mapWithKeys(fn (mixed $at, mixed $userId): array => [(int) $userId => CarbonImmutable::parse((string) $at)])
            ->all();
    }

    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
