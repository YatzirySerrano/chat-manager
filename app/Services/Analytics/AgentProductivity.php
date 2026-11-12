<?php

namespace App\Services\Analytics;

use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Models\User;
use App\Support\Features;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Productivity per collaborator, from the per-person rows of each
 * conversation (conversation_agent_metrics): what each person really did,
 * not what the last responsible agent inherited.
 *
 * Cohort: conversations RECEIVED in the period (like every other figure).
 * Per person, over that cohort:
 * - assigned: conversations the person was responsible for at some point;
 * - attended: conversations the person took part in with human attention
 *   (accepted it, received it by a manual assignment or a transfer, or
 *   replied). A conversation attended by two people counts for both, so
 *   the sum over people may exceed the number of conversations;
 * - closes: closings made while the person was responsible (each real
 *   closing counts once, also after a reopening);
 * - replies: human replies accepted by the provider (messages, not
 *   conversations — always labelled apart);
 * - first responses: conversations whose first human response the person
 *   wrote, and its average delay;
 * - handling time: average of the responsibility time over the
 *   conversations whose intervals already finished (a running interval is
 *   never averaged).
 * Live, whatever the period: conversations assigned now and not closed,
 * and cycles waiting now past their deadline.
 * Service level: cycles of the period that belong to the person (see
 * ServiceLevelAnalytics).
 *
 * The per-person rows are derived data built by `analytics:rebuild` and, from
 * then on, by every recomputation. Conversations whose metrics row predates
 * the release that introduced them have none until that command runs once
 * over the whole history: `complete` is false while a conversation of the
 * period that somebody was assigned to or answered still lacks them, so the
 * screens and exports say that the figures are partial instead of showing
 * zeros as if nobody had worked.
 *
 * Reach: a global role sees everyone; a departments role sees the people
 * who worked on conversations of its departments, plus itself; a personal
 * role sees only its own row. The agent filter keeps that person's row.
 *
 * @phpstan-type AgentRow array{id: int|null, name: string, assigned: int, attended: int, closes: int, closed_conversations: int, replies: int, first_responses: int, first_response_seconds: int|null, handling_seconds: int|null, handling_samples: int, transfers_in: int, transfers_out: int, pending_now: int, overdue_now: int, sla_met: int, sla_breached: int, sla_late_answers: int, sla_samples: int, sla_rate: float|null}
 * @phpstan-type SourcedAgentRow array{id: int|null, name: string, assigned: int, attended: int, closes: int, closed_conversations: int, replies: int, first_responses: int, first_response_seconds: int|null, handling_seconds: int|null, handling_samples: int, transfers_in: int, transfers_out: int, pending_now: int, overdue_now: int, sla_met: int, sla_breached: int, sla_late_answers: int, sla_samples: int, sla_rate: float|null, source_id: int}
 * @phpstan-type Evolution array{granularity: 'day'|'week'|'month', buckets: list<array{key: string, label: string}>, agents: list<array{id: int|null, name: string, attended: list<int>, closes: list<int>}>}
 * @phpstan-type Workload array{rows: list<array{id: int|null, name: string, open: int, pending: int, overdue: int}>, unassigned: int, unassigned_overdue: int}
 * @phpstan-type Productivity array{rows: list<AgentRow>, conversations: int, complete: bool, evolution: Evolution, workload: Workload}
 */
class AgentProductivity
{
    /**
     * People whose evolution is charted at once.
     */
    public const EVOLUTION_AGENTS = 6;

    /**
     * What the screens and exports say while `complete` is false.
     */
    public const INCOMPLETE_NOTE = 'Productividad sin calcular para parte del periodo: hay solicitudes que aún no tienen el detalle por persona porque falta ejecutar la reconstrucción de analítica (php artisan analytics:rebuild) tras la actualización. Las cifras por persona son parciales y un cero puede significar «sin datos».';

    public function __construct(
        private AnalyticsDimensions $dimensions,
        private ServiceLevelAnalytics $serviceLevels,
        private ProtectedAccounts $protectedAccounts,
    ) {}

    /**
     * Everything the productivity view shows.
     *
     * @return Productivity
     */
    public function report(User $viewer, AnalyticsFilters $filters): array
    {
        $rows = $this->rows($viewer, $filters, withSourceIds: true);
        $top = array_slice(array_column($rows, 'source_id'), 0, self::EVOLUTION_AGENTS);

        return [
            'rows' => array_map(function (array $row): array {
                unset($row['source_id']);

                return $row;
            }, $rows),
            // Distinct conversations behind the rows: the honest total next to the per-person sums.
            'conversations' => (int) $this->base($viewer, $filters)->whereNotNull('attended_at')->distinct()->count('conversation_id'),
            'complete' => $this->complete($filters),
            'evolution' => $this->evolution($viewer, $filters, $top, array_column($rows, 'name', 'source_id'), array_column($rows, 'id', 'source_id')),
            'workload' => $this->workload($viewer, $filters),
        ];
    }

    /**
     * Whether every conversation received in the period that needs
     * per-person rows has them. A conversation with a responsible agent or
     * a human response always gets at least one row when it is recomputed,
     * so one without any was last computed before those rows existed. It
     * describes the state of the derived data, not what the viewer may see:
     * neither the reach nor the filters apply, and only a yes or no leaves.
     * The anti-join stops at the first conversation found.
     */
    public function complete(AnalyticsFilters $filters): bool
    {
        return ! DB::table('conversation_metrics')
            ->where('received_at', '>=', $filters->period->fromUtc())
            ->where('received_at', '<', $filters->period->toUtc())
            ->where(fn (Builder $worked) => $worked->whereNotNull('agent_id')->orWhereNotNull('first_human_response_at'))
            ->whereNotExists(fn (Builder $rows) => $rows
                ->selectRaw('1')
                ->from('conversation_agent_metrics')
                ->whereColumn('conversation_agent_metrics.conversation_id', 'conversation_metrics.conversation_id'))
            ->exists();
    }

    /**
     * One row per person, most attended first (ties by id). A protected
     * (root) account is listed as ProtectedAccounts::SYSTEM_NAME with a
     * null id for viewers without root authority; exports that must tell
     * the rows apart ask for `source_id`.
     *
     * @return ($withSourceIds is true ? list<SourcedAgentRow> : list<AgentRow>)
     */
    public function rows(User $viewer, AnalyticsFilters $filters, bool $withSourceIds = false): array
    {
        $finished = 'intervals_count > 0 AND open_since IS NULL';
        $figures = $this->base($viewer, $filters)
            ->selectRaw('user_id')
            ->selectRaw('SUM(was_responsible) AS assigned')
            ->selectRaw('SUM(CASE WHEN attended_at IS NOT NULL THEN 1 ELSE 0 END) AS attended')
            ->selectRaw('SUM(closes_count) AS closes, SUM(CASE WHEN closes_count > 0 THEN 1 ELSE 0 END) AS closed_conversations')
            ->selectRaw('SUM(replies_count) AS replies')
            ->selectRaw('SUM(authored_first_response) AS first_responses, AVG(first_response_seconds) AS first_response_avg')
            ->selectRaw("AVG(CASE WHEN {$finished} THEN handling_seconds END) AS handling_avg, SUM(CASE WHEN {$finished} THEN 1 ELSE 0 END) AS handling_samples")
            ->selectRaw('SUM(transfers_in) AS transfers_in, SUM(transfers_out) AS transfers_out')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $sla = $this->serviceLevels->byOwner($viewer, $filters);
        $load = $this->assignedNow($viewer, $filters);
        $overdue = $this->serviceLevels->overdueNow($viewer, $filters, $filters->period->asOf);

        $ids = array_values(array_unique(array_filter([
            ...$figures->keys()->map(fn (mixed $id): int => (int) $id)->all(),
            ...array_keys($sla),
            ...array_keys($load),
            ...array_keys($overdue),
        ])));
        $names = User::query()->whereKey($ids)->pluck('name', 'id');
        $protected = $viewer->hasRootAuthority() ? [] : array_flip(array_intersect($ids, $this->protectedAccounts->ids()));
        $rows = [];

        foreach ($ids as $id) {
            if (! isset($names[$id])) {
                continue;
            }

            $row = $figures->get($id);
            $met = $sla[$id]['met'] ?? 0;
            $breached = $sla[$id]['breached'] ?? 0;
            $hidden = isset($protected[$id]);
            $rows[] = [
                'id' => $hidden ? null : $id,
                'name' => $hidden ? ProtectedAccounts::SYSTEM_NAME : (string) $names[$id],
                'assigned' => (int) ($row->assigned ?? 0),
                'attended' => (int) ($row->attended ?? 0),
                'closes' => (int) ($row->closes ?? 0),
                'closed_conversations' => (int) ($row->closed_conversations ?? 0),
                'replies' => (int) ($row->replies ?? 0),
                'first_responses' => (int) ($row->first_responses ?? 0),
                'first_response_seconds' => ($row->first_response_avg ?? null) === null ? null : (int) round((float) $row->first_response_avg),
                'handling_seconds' => ($row->handling_avg ?? null) === null ? null : (int) round((float) $row->handling_avg),
                'handling_samples' => (int) ($row->handling_samples ?? 0),
                'transfers_in' => (int) ($row->transfers_in ?? 0),
                'transfers_out' => (int) ($row->transfers_out ?? 0),
                'pending_now' => $load[$id] ?? 0,
                'overdue_now' => $overdue[$id] ?? 0,
                'sla_met' => $met,
                'sla_breached' => $breached,
                'sla_late_answers' => $sla[$id]['late_answers'] ?? 0,
                'sla_samples' => $met + $breached,
                'sla_rate' => $met + $breached > 0 ? round($met / ($met + $breached) * 100, 1) : null,
                'source_id' => $id,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['attended'], $b['assigned'], $a['source_id']] <=> [$a['attended'], $a['assigned'], $b['source_id']]);

        if (! $withSourceIds) {
            $rows = array_map(function (array $row): array {
                unset($row['source_id']);

                return $row;
            }, $rows);
        }

        return $rows;
    }

    /**
     * Conversations attended and closings per bucket of the period for the
     * given people (by the date each conversation was received).
     *
     * @param  list<int>  $userIds
     * @param  array<int, string>  $names  Display name per real id.
     * @param  array<int, int|null>  $visibleIds  Id as the viewer may see it per real id.
     * @return Evolution
     */
    public function evolution(User $viewer, AnalyticsFilters $filters, array $userIds, array $names, array $visibleIds): array
    {
        $period = $filters->period;
        $granularity = $period->granularity();
        $buckets = $period->buckets($granularity);
        $agents = [];

        if ($userIds !== []) {
            [$local, $bindings] = $period->localTimeSql('received_at');
            $bucket = AnalyticsPeriod::bucketSql($local, $granularity);
            $rows = $this->base($viewer, $filters)
                ->whereIn('user_id', $userIds)
                ->selectRaw("user_id, {$bucket} AS bucket", $bindings)
                ->selectRaw('SUM(CASE WHEN attended_at IS NOT NULL THEN 1 ELSE 0 END) AS attended, SUM(closes_count) AS closes')
                ->groupBy('user_id', 'bucket')
                ->get()
                ->groupBy('user_id');

            foreach ($userIds as $id) {
                $byBucket = ($rows->get($id) ?? collect())->keyBy('bucket');
                $agents[] = [
                    'id' => $visibleIds[$id] ?? null,
                    'name' => $names[$id] ?? '',
                    'attended' => array_map(fn (array $item): int => (int) ($byBucket->get($item[0])->attended ?? 0), $buckets),
                    'closes' => array_map(fn (array $item): int => (int) ($byBucket->get($item[0])->closes ?? 0), $buckets),
                ];
            }
        }

        return [
            'granularity' => $granularity,
            'buckets' => array_map(fn (array $item): array => ['key' => $item[0], 'label' => $item[1]], $buckets),
            'agents' => $agents,
        ];
    }

    /**
     * Live distribution of the work: conversations in attention and
     * pending per assigned person, their cycles past the deadline, and
     * what waits without anyone assigned. The period and the status filter
     * do not apply.
     *
     * @return Workload
     */
    public function workload(User $viewer, AnalyticsFilters $filters): array
    {
        $query = $this->liveConversations($viewer, $filters)
            ->selectRaw('assigned_user_id')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending', [ConversationStatus::Pending->value])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END) AS open', [ConversationStatus::Pending->value])
            ->groupBy('assigned_user_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) ($row->assigned_user_id ?? 0));

        // Cycles past their deadline are phase two (FEATURE_PHASE_TWO): none while it is off.
        $overdue = Features::phaseTwo() ? $this->serviceLevels->overdueNow($viewer, $filters, $filters->period->asOf) : [];
        $ids = array_values(array_filter(array_unique([...$query->keys()->all(), ...array_keys($overdue)])));
        $names = User::query()->whereKey($ids)->pluck('name', 'id');
        $protected = $viewer->hasRootAuthority() ? [] : array_flip(array_intersect($ids, $this->protectedAccounts->ids()));
        $rows = [];

        foreach ($ids as $id) {
            if (! isset($names[$id])) {
                continue;
            }

            $rows[] = [
                'id' => isset($protected[$id]) ? null : $id,
                'name' => isset($protected[$id]) ? ProtectedAccounts::SYSTEM_NAME : (string) $names[$id],
                'open' => (int) ($query->get($id)->open ?? 0),
                'pending' => (int) ($query->get($id)->pending ?? 0),
                'overdue' => $overdue[$id] ?? 0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['open'] + $b['pending'], $a['name']] <=> [$a['open'] + $a['pending'], $b['name']]);

        return [
            'rows' => $rows,
            // Waiting in a queue, with nobody assigned (a personal reach sees none).
            'unassigned' => (int) ($query->get(0)->pending ?? 0) + (int) ($query->get(0)->open ?? 0),
            'unassigned_overdue' => $overdue[0] ?? 0,
        ];
    }

    /**
     * Conversations assigned now and not closed, per person.
     *
     * @return array<int, int>
     */
    private function assignedNow(User $viewer, AnalyticsFilters $filters): array
    {
        $result = [];
        $rows = $this->liveConversations($viewer, $filters)
            ->whereNotNull('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) AS total')
            ->groupBy('assigned_user_id')
            ->get();

        foreach ($rows as $row) {
            $result[(int) $row->assigned_user_id] = (int) $row->total;
        }

        return $result;
    }

    /**
     * Live (not imported), not closed conversations within the reach and
     * the filters that can apply to "now" (the status one cannot).
     */
    private function liveConversations(User $viewer, AnalyticsFilters $filters): Builder
    {
        $query = DB::table('conversations')
            ->where('origin', ConversationOrigin::Live->value)
            ->where('status', '!=', ConversationStatus::Closed->value);
        $this->restrict($query, $viewer, 'assigned_user_id');
        $this->dimensions->apply($query, $filters->withoutStatus(), 'assigned_user_id');

        return $query;
    }

    /**
     * Per-person rows of the conversations received in the period, within
     * the reach and the filters.
     */
    private function base(User $viewer, AnalyticsFilters $filters): Builder
    {
        $query = DB::table('conversation_agent_metrics')
            ->where('received_at', '>=', $filters->period->fromUtc())
            ->where('received_at', '<', $filters->period->toUtc());
        $this->restrict($query, $viewer, 'user_id');
        $this->dimensions->apply($query, $filters, 'user_id');

        return $query;
    }

    private function restrict(Builder $query, User $viewer, string $personColumn): void
    {
        match ($viewer->dataScope()) {
            DataScope::Global => null,
            DataScope::Departments => $query->where(fn (Builder $visible) => $visible
                ->where($personColumn, $viewer->id)
                ->orWhereIn('department_id', $viewer->departmentIds())),
            DataScope::Assigned => $query->where($personColumn, $viewer->id),
            null => $query->whereRaw('1 = 0'),
        };
    }
}
