<?php

namespace App\Services\Analytics;

use App\Enums\DataScope;
use App\Enums\ResponseCycleKind;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service level (SLA) compliance from the response cycles: one row per
 * period in which a customer waited for a human answer, with the objective
 * that applied frozen in the row. Nothing here reads the current settings,
 * so changing an objective never rewrites past compliance.
 *
 * Cohort: cycles STARTED in the period. Each cycle falls in exactly one
 * group:
 * - unmeasured: no objective applied (`target_seconds` null) — outside
 *   compliance;
 * - met: answered on or before its deadline;
 * - breached: the deadline passed (`breached_at`), whether it was answered
 *   late, closed late or is still waiting. A cycle still open whose deadline
 *   already passed counts as breached even before the evaluator stamps it;
 * - in progress: still open inside its deadline — not judged yet;
 * - closed unanswered: the conversation was closed before the deadline
 *   without an answer — neither met nor breached.
 * Compliance = met / (met + breached); its base (n) is always reported.
 * Without a judged cycle there is no percentage (null), never 100 %.
 *
 * Who a cycle belongs to (agent filter, personal reach, per-agent figures):
 * the person responsible when it was breached (`breach_user_id`, nobody
 * when it was waiting in a queue); otherwise who answered it; otherwise who
 * it is assigned to.
 *
 * Dimensions (department, category, connection) are the ones frozen in the
 * cycle when it started. The conversation status filter does not apply.
 *
 * Average overrun of a group: `exceeded_seconds` is stored in the unit of the
 * objective of each cycle (business seconds when it counts only attention
 * hours, calendar seconds otherwise), so the two are averaged apart
 * (`exceeded_business_seconds`, `exceeded_calendar_seconds`) and never
 * together. A cycle whose overrun is unknown (null) is in neither.
 *
 * Cycles only exist since the release that introduced them: history is not
 * reconstructed, so `available_since` tells from when the figures are real.
 *
 * @phpstan-type Totals array{cycles: int, met: int, breached: int, in_progress: int, closed_unanswered: int, unmeasured: int, samples: int, rate: float|null}
 * @phpstan-type GroupRow array{id: int|null, name: string, met: int, breached: int, in_progress: int, samples: int, rate: float|null, exceeded_business_seconds: int|null, exceeded_calendar_seconds: int|null}
 * @phpstan-type Compliance array{available_since: string|null, covers_period: bool, totals: Totals, previous: array{rate: float|null, samples: int}, kinds: list<array{kind: string, label: string, cycles: int, met: int, breached: int, in_progress: int, closed_unanswered: int, unmeasured: int, samples: int, rate: float|null}>, response_time: array{avg: int|null, min: int|null, max: int|null, samples: int}, trend: array{granularity: 'day'|'week'|'month', points: list<array{key: string, label: string, met: int, breached: int, rate: float|null}>}, by_department: list<GroupRow>, by_category: list<GroupRow>}
 */
class ServiceLevelAnalytics
{
    public function __construct(
        private AnalyticsDimensions $dimensions,
    ) {}

    /**
     * SQL of the person a cycle belongs to (see the class).
     */
    public const OWNER = "CASE WHEN breached_at IS NOT NULL THEN breach_user_id WHEN outcome = 'answered' THEN responder_user_id ELSE assigned_user_id END";

    /**
     * Local date (Y-m-d) of the first cycle ever recorded; null when the
     * tracking has not produced any row yet.
     */
    public function availableSince(string $timezone): ?string
    {
        $first = DB::table('response_cycles')->min('started_at');

        return $first === null ? null : CarbonImmutable::parse((string) $first, 'UTC')->setTimezone($timezone)->toDateString();
    }

    /**
     * Compliance of the period within the viewer's reach and the filters.
     *
     * @return Compliance
     */
    public function compliance(User $viewer, AnalyticsFilters $filters): array
    {
        $period = $filters->period;
        $now = $period->asOf ?? CarbonImmutable::now();
        $available = $this->availableSince($period->timezone);
        // One grouped query for both kinds; the totals are their sum.
        $perKind = $this->totalsPerKind($this->cycles($viewer, $filters), $now);
        $previous = $this->sum($this->totalsPerKind($this->cycles($viewer, $filters->comparison()), $now));
        $totals = $this->sum($perKind);
        $kinds = [];

        foreach (ResponseCycleKind::cases() as $kind) {
            $kinds[] = [
                'kind' => $kind->value,
                'label' => $kind->label(),
                ...($perKind[$kind->value] ?? $this->sum([])),
            ];
        }

        /** @var object{average: string|float|null, shortest: int|string|null, longest: int|string|null, samples: int|string|null} $times */
        $times = $this->cycles($viewer, $filters)
            ->where('outcome', ResponseCycle::OUTCOME_ANSWERED)
            ->selectRaw('AVG(response_seconds) AS average, MIN(response_seconds) AS shortest, MAX(response_seconds) AS longest, COUNT(response_seconds) AS samples')
            ->first();

        return [
            'available_since' => $available,
            // False when the period starts before the first recorded cycle: the figures are partial.
            'covers_period' => $available !== null && $available <= $period->from->toDateString(),
            'totals' => $totals,
            'previous' => ['rate' => $previous['rate'], 'samples' => $previous['samples']],
            'kinds' => $kinds,
            'response_time' => [
                'avg' => $times->average === null ? null : (int) round((float) $times->average),
                'min' => $times->shortest === null ? null : (int) $times->shortest,
                'max' => $times->longest === null ? null : (int) $times->longest,
                'samples' => (int) $times->samples,
            ],
            'trend' => $this->trend($viewer, $filters, $now),
            'by_department' => $this->grouped($viewer, $filters, 'department_id', $now),
            'by_category' => $this->grouped($viewer, $filters, 'category_id', $now),
        ];
    }

    /**
     * Judged cycles per person (see "who a cycle belongs to"): met, breached
     * and answered late, keyed by user id (0 = nobody, waiting in a queue).
     *
     * @return array<int, array{met: int, breached: int, late_answers: int}>
     */
    public function byOwner(User $viewer, AnalyticsFilters $filters): array
    {
        $now = $filters->period->asOf ?? CarbonImmutable::now();
        $rows = $this->cycles($viewer, $filters)
            ->whereNotNull('target_seconds')
            ->selectRaw(self::OWNER.' AS owner_id')
            ->selectRaw("SUM({$this->metSql()}) AS met, SUM({$this->breachedSql()}) AS breached", [$this->moment($now)])
            ->groupBy('owner_id')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) ($row->owner_id ?? 0)] = ['met' => (int) $row->met, 'breached' => (int) $row->breached, 'late_answers' => 0];
        }

        // Informative: late answers by who finally wrote them (the breach itself belongs to who was responsible).
        $late = $this->cycles($viewer, $filters, ownerFilter: false)
            ->whereNotNull('target_seconds')
            ->where('outcome', ResponseCycle::OUTCOME_ANSWERED)
            ->whereColumn('responded_at', '>', 'due_at')
            ->whereNotNull('responder_user_id')
            ->when($filters->agentId !== null, fn (Builder $query) => $query->where('responder_user_id', $filters->agentId))
            ->selectRaw('responder_user_id, COUNT(*) AS total')
            ->groupBy('responder_user_id')
            ->get();

        foreach ($late as $row) {
            $id = (int) $row->responder_user_id;

            $result[$id] ??= ['met' => 0, 'breached' => 0, 'late_answers' => 0];
            $result[$id]['late_answers'] = (int) $row->total;
        }

        return $result;
    }

    /**
     * Cycles waiting NOW past their deadline, per assigned person (0 =
     * nobody, in a queue), whenever they started. Live figure: the period
     * and the status filter do not apply.
     *
     * @return array<int, int>
     */
    public function overdueNow(User $viewer, AnalyticsFilters $filters, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $query = DB::table('response_cycles')
            ->where('active', 1)
            ->whereNotNull('target_seconds')
            ->where(fn (Builder $overdue) => $overdue->whereNotNull('breached_at')->orWhere('due_at', '<', $now));
        $this->restrict($query, $viewer, 'assigned_user_id');
        $this->dimensions->apply($query, $filters, 'assigned_user_id', withStatus: false);

        $result = [];

        foreach ($query->selectRaw('assigned_user_id, COUNT(*) AS total')->groupBy('assigned_user_id')->get() as $row) {
            $result[(int) ($row->assigned_user_id ?? 0)] = (int) $row->total;
        }

        return $result;
    }

    /**
     * Breached cycles of the period, most recent first, for the detail of
     * an export.
     */
    public function breaches(User $viewer, AnalyticsFilters $filters): Builder
    {
        $now = $filters->period->asOf ?? CarbonImmutable::now();

        return $this->cycles($viewer, $filters)
            ->whereNotNull('target_seconds')
            ->whereRaw("{$this->breachedSql()} = 1", [$this->moment($now)])
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    /**
     * Cycles started in the filters' period within the reach and filters.
     *
     * @param  bool  $ownerFilter  Apply the agent filter and the personal reach to the owner of the cycle.
     */
    public function cycles(User $viewer, AnalyticsFilters $filters, bool $ownerFilter = true): Builder
    {
        $query = DB::table('response_cycles')
            ->where('started_at', '>=', $filters->period->fromUtc())
            ->where('started_at', '<', $filters->period->toUtc());
        $owner = DB::raw('('.self::OWNER.')');

        if ($ownerFilter) {
            $this->restrict($query, $viewer, $owner);
        } elseif ($viewer->dataScope() === DataScope::Departments) {
            $query->where(fn (Builder $visible) => $visible->where('responder_user_id', $viewer->id)->orWhereIn('department_id', $viewer->departmentIds()));
        } elseif ($viewer->dataScope() !== DataScope::Global) {
            $query->where('responder_user_id', $viewer->id);
        }

        $this->dimensions->apply($query, $filters, $ownerFilter ? $owner : null, withStatus: false);

        return $query;
    }

    /**
     * Reach over the cycles by the data scope of the role: everything, the
     * cycles of the viewer's departments plus their own, or only their own.
     */
    private function restrict(Builder $query, User $viewer, string|Expression $ownerColumn): void
    {
        match ($viewer->dataScope()) {
            DataScope::Global => null,
            DataScope::Departments => $query->where(fn (Builder $visible) => $visible
                ->where($ownerColumn, $viewer->id)
                ->orWhereIn('department_id', $viewer->departmentIds())),
            DataScope::Assigned => $query->where($ownerColumn, $viewer->id),
            null => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @return array<string, Totals> Totals keyed by cycle kind.
     */
    private function totalsPerKind(Builder $query, CarbonImmutable $now): array
    {
        $rows = $query
            ->selectRaw('kind, COUNT(*) AS cycles')
            ->selectRaw("SUM({$this->metSql()}) AS met")
            ->selectRaw("SUM({$this->breachedSql()}) AS breached", [$this->moment($now)])
            ->selectRaw("SUM(CASE WHEN target_seconds IS NOT NULL AND active = 1 AND {$this->breachedSql()} = 0 THEN 1 ELSE 0 END) AS in_progress", [$this->moment($now)])
            ->selectRaw("SUM(CASE WHEN target_seconds IS NOT NULL AND outcome = 'closed' AND {$this->breachedSql()} = 0 THEN 1 ELSE 0 END) AS closed_unanswered", [$this->moment($now)])
            ->selectRaw('SUM(CASE WHEN target_seconds IS NULL THEN 1 ELSE 0 END) AS unmeasured')
            ->groupBy('kind')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $met = (int) $row->met;
            $breached = (int) $row->breached;
            $result[(string) $row->kind] = [
                'cycles' => (int) $row->cycles,
                'met' => $met,
                'breached' => $breached,
                'in_progress' => (int) $row->in_progress,
                'closed_unanswered' => (int) $row->closed_unanswered,
                'unmeasured' => (int) $row->unmeasured,
                'samples' => $met + $breached,
                'rate' => $this->rate($met, $met + $breached),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, Totals>  $groups
     * @return Totals
     */
    private function sum(array $groups): array
    {
        $met = (int) array_sum(array_column($groups, 'met'));
        $breached = (int) array_sum(array_column($groups, 'breached'));

        return [
            'cycles' => (int) array_sum(array_column($groups, 'cycles')),
            'met' => $met,
            'breached' => $breached,
            'in_progress' => (int) array_sum(array_column($groups, 'in_progress')),
            'closed_unanswered' => (int) array_sum(array_column($groups, 'closed_unanswered')),
            'unmeasured' => (int) array_sum(array_column($groups, 'unmeasured')),
            'samples' => $met + $breached,
            'rate' => $this->rate($met, $met + $breached),
        ];
    }

    /**
     * @return array{granularity: 'day'|'week'|'month', points: list<array{key: string, label: string, met: int, breached: int, rate: float|null}>}
     */
    private function trend(User $viewer, AnalyticsFilters $filters, CarbonImmutable $now): array
    {
        $period = $filters->period;
        $granularity = $period->granularity();
        [$local, $bindings] = $period->localTimeSql('started_at');
        $bucket = AnalyticsPeriod::bucketSql($local, $granularity);

        $rows = $this->cycles($viewer, $filters)
            ->whereNotNull('target_seconds')
            ->selectRaw("{$bucket} AS bucket", $bindings)
            ->selectRaw("SUM({$this->metSql()}) AS met, SUM({$this->breachedSql()}) AS breached", [$this->moment($now)])
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = [];

        foreach ($period->buckets($granularity) as [$key, $label]) {
            $met = (int) ($rows->get($key)->met ?? 0);
            $breached = (int) ($rows->get($key)->breached ?? 0);
            $points[] = ['key' => $key, 'label' => $label, 'met' => $met, 'breached' => $breached, 'rate' => $this->rate($met, $met + $breached)];
        }

        return ['granularity' => $granularity, 'points' => $points];
    }

    /**
     * Judged cycles per department or category (the one frozen in the
     * cycle), most breaches first.
     *
     * @param  'department_id'|'category_id'  $column
     * @return list<GroupRow>
     */
    private function grouped(User $viewer, AnalyticsFilters $filters, string $column, CarbonImmutable $now): array
    {
        $rows = $this->cycles($viewer, $filters)
            ->whereNotNull('target_seconds')
            ->selectRaw("{$column} AS dimension")
            ->selectRaw("SUM({$this->metSql()}) AS met, SUM({$this->breachedSql()}) AS breached", [$this->moment($now)])
            ->selectRaw("SUM(CASE WHEN active = 1 AND {$this->breachedSql()} = 0 THEN 1 ELSE 0 END) AS in_progress", [$this->moment($now)])
            ->selectRaw('AVG(CASE WHEN business_hours_only = 1 THEN exceeded_seconds END) AS exceeded_business')
            ->selectRaw('AVG(CASE WHEN business_hours_only = 0 THEN exceeded_seconds END) AS exceeded_calendar')
            ->groupBy($column)
            ->orderByDesc('breached')
            ->orderBy($column)
            ->get();

        $ids = $rows->pluck('dimension')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $names = $column === 'department_id'
            ? Department::query()->whereKey($ids)->pluck('name', 'id')
            : ConversationCategory::query()->whereKey($ids)->pluck('name', 'id');
        $empty = $column === 'department_id' ? 'Sin departamento' : 'Sin categoría';
        $result = [];

        foreach ($rows as $row) {
            $id = $row->dimension === null ? null : (int) $row->dimension;
            $met = (int) $row->met;
            $breached = (int) $row->breached;
            $result[] = [
                'id' => $id,
                'name' => $id === null ? $empty : (string) ($names[$id] ?? $empty),
                'met' => $met,
                'breached' => $breached,
                'in_progress' => (int) $row->in_progress,
                'samples' => $met + $breached,
                'rate' => $this->rate($met, $met + $breached),
                'exceeded_business_seconds' => $row->exceeded_business === null ? null : (int) round((float) $row->exceeded_business),
                'exceeded_calendar_seconds' => $row->exceeded_calendar === null ? null : (int) round((float) $row->exceeded_calendar),
            ];
        }

        return $result;
    }

    /**
     * 1 when the cycle was answered on or before its deadline.
     *
     * @return literal-string
     */
    private function metSql(): string
    {
        return "(CASE WHEN target_seconds IS NOT NULL AND outcome = 'answered' AND responded_at <= due_at THEN 1 ELSE 0 END)";
    }

    /**
     * 1 when the deadline of the cycle passed (stamped, answered late, or
     * still open after it). It takes one binding: the current moment
     * (moment()), from the application clock.
     *
     * @return literal-string
     */
    private function breachedSql(): string
    {
        return "(CASE WHEN target_seconds IS NULL OR (outcome = 'answered' AND responded_at <= due_at) THEN 0"
            ." WHEN breached_at IS NOT NULL OR (outcome = 'answered' AND responded_at > due_at) OR (active = 1 AND due_at < ?) THEN 1 ELSE 0 END)";
    }

    private function moment(CarbonImmutable $now): string
    {
        return $now->utc()->format('Y-m-d H:i:s');
    }

    private function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }
}
