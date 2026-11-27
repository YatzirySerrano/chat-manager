<?php

namespace App\Services\Analytics;

use App\Enums\ConversationOrigin;
use App\Enums\ConversationOutcome;
use App\Enums\ConversationStatus;
use App\Models\Department;
use App\Models\User;
use App\Support\ProtectedAccounts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «Resultados de solicitudes»: what became of the requests completed in the
 * period. «Solicitud completada» means the request was attended; its credit
 * result says whether it was approved (the only result that commissions,
 * with its authorized amount) or rejected, or nobody recorded one yet.
 *
 * Cohort: live conversations (never imported history) still completed
 * (`completed_at`; a reopened one is no longer completed) whose result date
 * falls in the period: when the result was recorded (`outcome_at`), or when
 * it was completed for the ones without a result.
 *
 * Attribution: who completed the request (`closed_by_user_id`), the person
 * the commission belongs to; the agent filter and the personal reach apply
 * to that person. Department, connection, category and channel are the
 * current ones of the conversation (AnalyticsDimensions); the status filter
 * does not apply (they are all closed).
 *
 * Reach (AnalyticsScope): a global role sees everyone; a departments role
 * the requests of its departments plus the ones it completed; a personal
 * role only the ones it completed. Protected (root) accounts are named
 * ProtectedAccounts::SYSTEM_NAME, without id, for viewers without root
 * authority.
 *
 * Amounts are pesos with two decimals; the average authorized amount only
 * counts approvals that have an amount.
 *
 * @phpstan-type Figures array{completed: int, approved: int, rejected: int, without: int, approval_rate: float|null, amount: float, with_amount: int, average_amount: float|null}
 * @phpstan-type PersonRow array{id: int|null, name: string, completed: int, approved: int, rejected: int, without: int, approval_rate: float|null, amount: float, with_amount: int, average_amount: float|null}
 * @phpstan-type SourcedPersonRow array{id: int|null, name: string, completed: int, approved: int, rejected: int, without: int, approval_rate: float|null, amount: float, with_amount: int, average_amount: float|null, source_id: int|null}
 * @phpstan-type DepartmentRow array{id: int|null, name: string, completed: int, approved: int, rejected: int, without: int, approval_rate: float|null, amount: float, with_amount: int, average_amount: float|null}
 * @phpstan-type TrendPoint array{key: string, label: string, completed: int, approved: int, rejected: int, without: int, approval_rate: float|null, amount: float, with_amount: int, average_amount: float|null}
 * @phpstan-type Outcomes array{totals: Figures, by_agent: list<PersonRow>, by_department: list<DepartmentRow>, trend: array{granularity: 'day'|'week'|'month', points: list<TrendPoint>}}
 */
class RequestOutcomes
{
    /**
     * Date of the result: when it was recorded, or the completion without one.
     */
    public const DATE = 'COALESCE(conversations.outcome_at, conversations.completed_at)';

    public const WITHOUT_PERSON = 'Sin registro';

    public const WITHOUT_DEPARTMENT = 'Sin departamento';

    public function __construct(
        private AnalyticsScope $scope,
        private AnalyticsDimensions $dimensions,
        private ProtectedAccounts $protectedAccounts,
    ) {}

    /**
     * Everything the «Resultados» view shows.
     *
     * @return Outcomes
     */
    public function report(User $viewer, AnalyticsFilters $filters): array
    {
        return [
            'totals' => $this->totals($viewer, $filters),
            'by_agent' => array_map(function (array $row): array {
                unset($row['source_id']);

                return $row;
            }, $this->byAgent($viewer, $filters)),
            'by_department' => $this->byDepartment($viewer, $filters),
            'trend' => $this->trend($viewer, $filters),
        ];
    }

    /**
     * @return Figures
     */
    public function totals(User $viewer, AnalyticsFilters $filters): array
    {
        return self::figures($this->aggregate($this->base($viewer, $filters))->first());
    }

    /**
     * One row per person who completed requests: most authorized amount
     * first, then most approvals and most completions (ties by name).
     * `source_id` is the real id, for exports that must tell rows apart.
     *
     * @return list<SourcedPersonRow>
     */
    public function byAgent(User $viewer, AnalyticsFilters $filters): array
    {
        $rows = $this->aggregate($this->base($viewer, $filters))
            ->addSelect('conversations.closed_by_user_id AS person_id')
            ->groupBy('conversations.closed_by_user_id')
            ->get();
        $ids = $rows->pluck('person_id')->filter()->map(fn (mixed $id): int => (int) $id)->values()->all();
        $names = User::query()->whereKey($ids)->pluck('name', 'id');
        $protected = $viewer->hasRootAuthority() ? [] : array_flip(array_intersect($ids, $this->protectedAccounts->ids()));
        $result = [];

        foreach ($rows as $row) {
            $id = $row->person_id === null ? null : (int) $row->person_id;
            $hidden = $id !== null && isset($protected[$id]);
            $result[] = [
                'id' => $hidden ? null : $id,
                'name' => match (true) {
                    $hidden => ProtectedAccounts::SYSTEM_NAME,
                    $id !== null && isset($names[$id]) => (string) $names[$id],
                    default => self::WITHOUT_PERSON,
                },
                ...self::figures($row),
                'source_id' => $id,
            ];
        }

        usort($result, fn (array $a, array $b): int => [$b['amount'], $b['approved'], $b['completed'], $a['name']] <=> [$a['amount'], $a['approved'], $a['completed'], $b['name']]);

        return $result;
    }

    /**
     * One row per current department of the requests, ordered like the
     * people.
     *
     * @return list<DepartmentRow>
     */
    public function byDepartment(User $viewer, AnalyticsFilters $filters): array
    {
        $rows = $this->aggregate($this->base($viewer, $filters))
            ->addSelect('conversations.department_id AS group_id')
            ->groupBy('conversations.department_id')
            ->get();
        $names = Department::query()->whereKey($rows->pluck('group_id')->filter()->all())->pluck('name', 'id');
        $result = [];

        foreach ($rows as $row) {
            $id = $row->group_id === null ? null : (int) $row->group_id;
            $result[] = [
                'id' => $id,
                'name' => $id !== null && isset($names[$id]) ? (string) $names[$id] : self::WITHOUT_DEPARTMENT,
                ...self::figures($row),
            ];
        }

        usort($result, fn (array $a, array $b): int => [$b['amount'], $b['approved'], $b['completed'], $a['name']] <=> [$a['amount'], $a['approved'], $a['completed'], $b['name']]);

        return $result;
    }

    /**
     * Figures per bucket of the period (local date of the result), every
     * bucket present.
     *
     * @return array{granularity: 'day'|'week'|'month', points: list<TrendPoint>}
     */
    public function trend(User $viewer, AnalyticsFilters $filters): array
    {
        $period = $filters->period;
        $granularity = $period->granularity();
        [$local, $bindings] = $period->localTimeSql(self::DATE);
        $bucket = AnalyticsPeriod::bucketSql($local, $granularity);
        $rows = $this->aggregate($this->base($viewer, $filters))
            ->selectRaw("{$bucket} AS bucket", $bindings)
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');
        $points = [];

        foreach ($period->buckets($granularity) as [$key, $label]) {
            $points[] = ['key' => $key, 'label' => $label, ...self::figures($rows->get($key))];
        }

        return ['granularity' => $granularity, 'points' => $points];
    }

    /**
     * The completed requests of the period within the reach and the filters
     * (conversations table, one row per request).
     */
    public function base(User $viewer, AnalyticsFilters $filters): Builder
    {
        return $this->constrain(DB::table('conversations'), $viewer, $filters);
    }

    /**
     * Restrict a query on the conversations table to the cohort, the reach
     * and the filters (also used by the export on the Eloquent query).
     */
    public function constrain(Builder $query, User $viewer, AnalyticsFilters $filters): Builder
    {
        $query
            ->where('conversations.origin', ConversationOrigin::Live->value)
            ->whereNull('conversations.history_only_at')
            ->where('conversations.status', ConversationStatus::Closed->value)
            ->whereNotNull('conversations.completed_at')
            ->whereRaw(self::DATE.' >= ?', [$filters->period->fromUtc()->format('Y-m-d H:i:s')])
            ->whereRaw(self::DATE.' < ?', [$filters->period->toUtc()->format('Y-m-d H:i:s')]);
        $this->scope->apply($query, $viewer, 'conversations.closed_by_user_id', 'conversations.department_id');
        $this->dimensions->apply($query, $filters->withoutStatus(), 'conversations.closed_by_user_id', withStatus: false);

        return $query;
    }

    /**
     * The counts and amounts of a group of requests.
     */
    private function aggregate(Builder $query): Builder
    {
        $approved = ConversationOutcome::Approved->value;
        $rejected = ConversationOutcome::Rejected->value;

        return $query
            ->selectRaw('COUNT(*) AS completed')
            ->selectRaw('SUM(CASE WHEN conversations.outcome = ? THEN 1 ELSE 0 END) AS approved', [$approved])
            ->selectRaw('SUM(CASE WHEN conversations.outcome = ? THEN 1 ELSE 0 END) AS rejected', [$rejected])
            ->selectRaw('SUM(CASE WHEN conversations.outcome = ? THEN COALESCE(conversations.outcome_amount, 0) ELSE 0 END) AS amount', [$approved])
            ->selectRaw('SUM(CASE WHEN conversations.outcome = ? AND conversations.outcome_amount IS NOT NULL THEN 1 ELSE 0 END) AS with_amount', [$approved]);
    }

    /**
     * @return Figures
     */
    private static function figures(?object $row): array
    {
        $completed = (int) ($row->completed ?? 0);
        $approved = (int) ($row->approved ?? 0);
        $rejected = (int) ($row->rejected ?? 0);
        $amount = round((float) ($row->amount ?? 0), 2);
        $withAmount = (int) ($row->with_amount ?? 0);

        return [
            'completed' => $completed,
            'approved' => $approved,
            'rejected' => $rejected,
            'without' => max(0, $completed - $approved - $rejected),
            // Over the requests with a result: those without one are not counted against it.
            'approval_rate' => $approved + $rejected > 0 ? round($approved / ($approved + $rejected) * 100, 1) : null,
            'amount' => $amount,
            'with_amount' => $withAmount,
            'average_amount' => $withAmount > 0 ? round($amount / $withAmount, 2) : null,
        ];
    }
}
