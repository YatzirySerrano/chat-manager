<?php

namespace App\Services\Analytics;

use App\Enums\AgentAvailability;
use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational figures of the reports module, computed from the per
 * conversation metrics table (never from the messages table) plus a few
 * live counts from the conversations table (indexed by status).
 *
 * Period figures follow the cohort of conversations RECEIVED in the period:
 * "of the conversations received between these dates, how many were
 * attended, answered, resolved…, and how long it took". Snapshot figures
 * (pending now, load now, available agents now) ignore the period.
 * A figure without enough data is null, never zero.
 *
 * @phpstan-type Comparable array{value: int|float|null, previous: int|float|null}
 * @phpstan-type Duration array{value: int|null, previous: int|null, samples: int}
 * @phpstan-type BreakdownRow array{id: int|null, name: string, received: int, attended: int, responded: int, resolved: int, first_attention_seconds: int|null, first_response_seconds: int|null, resolution_seconds: int|null, transfers: int, reopened: int, messages_in: int, messages_out: int, failed_out: int, out_of_hours: int, current_load: int|null}
 * @phpstan-type Sla array{target_seconds: int, met: int, samples: int, rate: float|null, previous_rate: float|null}
 * @phpstan-type Summary array{received: Comparable, attended: Comparable, responded: Comparable, resolved: Comparable, reopened: Comparable, transfers: Comparable, out_of_hours: Comparable, out_of_hours_measurable: int, first_attention_seconds: Duration, first_response_seconds: Duration, resolution_seconds: Duration, first_response_business_seconds: Duration, resolution_business_seconds: Duration, first_response_sla: Sla|null, resolution_sla: Sla|null, messages_in: Comparable, messages_out: Comparable, failed_out: Comparable, failure_rate: Comparable}
 * @phpstan-type Snapshot array{pending: int, open: int, on_hold: int, available_agents: int|null, agents: int|null}
 * @phpstan-type TrendPoint array{key: string, label: string, received: int, resolved: int, previous_received: int}
 * @phpstan-type Analytics array{summary: Summary, current: Snapshot, statuses: list<array{status: string, label: string, count: int}>, trend: array{granularity: 'day'|'month', points: list<TrendPoint>}, heatmap: array{max: int, cells: list<list<int>>}, by_agent: list<BreakdownRow>, by_department: list<BreakdownRow>, by_connection: list<BreakdownRow>}
 */
class OperationalAnalytics
{
    /**
     * Seconds a report is cached for the same reach and filters.
     */
    public const REPORT_TTL = 120;

    public function __construct(
        private AnalyticsScope $scope,
        private AnalyticsCache $cache,
        private PlatformSettings $settings,
    ) {}

    /**
     * Everything the reports page shows, cached for REPORT_TTL seconds.
     *
     * @return Analytics
     */
    public function report(User $viewer, AnalyticsFilters $filters): array
    {
        return $this->cache->remember(
            "report:{$this->scope->fingerprint($viewer)}:{$filters->cacheKey()}:sla".implode('-', array_map('intval', $this->slaTargets())),
            self::REPORT_TTL,
            fn (): array => $this->compute($viewer, $filters),
        );
    }

    /**
     * Everything the reports page shows, computed now (no cache): exports
     * read it inside their own consistent snapshot, so their rows, totals
     * and figures always come from the same data.
     *
     * @return Analytics
     */
    public function compute(User $viewer, AnalyticsFilters $filters): array
    {
        return [
            'summary' => $this->summary($viewer, $filters),
            'current' => $this->snapshot($viewer, $filters),
            'statuses' => $this->statuses($viewer, $filters),
            'trend' => $this->trend($viewer, $filters),
            'heatmap' => $this->heatmap($viewer, $filters),
            'by_agent' => $this->breakdown($viewer, $filters, 'agent_id'),
            'by_department' => $this->scope->isPersonal($viewer) ? [] : $this->breakdown($viewer, $filters, 'department_id'),
            'by_connection' => $this->breakdown($viewer, $filters, 'connection_id'),
        ];
    }

    /**
     * Headline figures of the period compared with the previous period.
     *
     * @return Summary
     */
    public function summary(User $viewer, AnalyticsFilters $filters): array
    {
        [$responseTarget, $resolutionTarget] = $this->slaTargets();
        $current = $this->totals($this->cohort($viewer, $filters), $responseTarget, $resolutionTarget);
        $previous = $this->totals($this->cohort($viewer, $filters->previous()), $responseTarget, $resolutionTarget);

        $comparable = fn (string $key): array => ['value' => $current[$key], 'previous' => $previous[$key]];
        $duration = fn (string $key): array => [
            'value' => $current["{$key}_avg"],
            'previous' => $previous["{$key}_avg"],
            'samples' => $current["{$key}_samples"],
        ];

        return [
            'received' => $comparable('received'),
            'attended' => $comparable('attended'),
            'responded' => $comparable('responded'),
            'resolved' => $comparable('resolved'),
            'reopened' => $comparable('reopened'),
            'transfers' => $comparable('transfers'),
            'out_of_hours' => [
                'value' => $current['out_of_hours_measurable'] > 0 ? $current['out_of_hours'] : null,
                'previous' => $previous['out_of_hours_measurable'] > 0 ? $previous['out_of_hours'] : null,
            ],
            'out_of_hours_measurable' => $current['out_of_hours_measurable'],
            'first_attention_seconds' => $duration('first_attention'),
            'first_response_seconds' => $duration('first_response'),
            'resolution_seconds' => $duration('resolution'),
            'first_response_business_seconds' => $duration('first_response_business'),
            'resolution_business_seconds' => $duration('resolution_business'),
            'first_response_sla' => $this->sla($responseTarget, $current, $previous, 'first_response'),
            'resolution_sla' => $this->sla($resolutionTarget, $current, $previous, 'resolution'),
            'messages_in' => $comparable('messages_in'),
            'messages_out' => $comparable('messages_out'),
            'failed_out' => $comparable('failed_out'),
            'failure_rate' => [
                'value' => $this->rate($current['failed_out'], $current['messages_out']),
                'previous' => $this->rate($previous['failed_out'], $previous['messages_out']),
            ],
        ];
    }

    /**
     * Live state of the attention within the viewer's reach and filters.
     *
     * @return Snapshot
     */
    public function snapshot(User $viewer, AnalyticsFilters $filters): array
    {
        $query = DB::table('conversations')->where('status', '!=', ConversationStatus::Closed->value);
        $this->scope->apply($query, $viewer, 'assigned_user_id', 'department_id');
        $this->dimensions($query, $filters, 'assigned_user_id');

        $counts = $query->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        [$available, $agents] = $this->agentAvailability($viewer, $filters);

        return [
            'pending' => (int) ($counts[ConversationStatus::Pending->value] ?? 0),
            'open' => (int) ($counts[ConversationStatus::Open->value] ?? 0),
            'on_hold' => (int) ($counts[ConversationStatus::OnHold->value] ?? 0),
            'available_agents' => $available,
            'agents' => $agents,
        ];
    }

    /**
     * Current status of the conversations received in the period.
     *
     * @return list<array{status: string, label: string, count: int}>
     */
    public function statuses(User $viewer, AnalyticsFilters $filters): array
    {
        $counts = $this->cohort($viewer, $filters)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(fn (ConversationStatus $status): array => [
            'status' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($counts[$status->value] ?? 0),
        ], ConversationStatus::cases());
    }

    /**
     * Conversations received and resolved per local day (or month), with
     * the received ones of the aligned bucket of the previous period.
     *
     * @return array{granularity: 'day'|'month', points: list<TrendPoint>}
     */
    public function trend(User $viewer, AnalyticsFilters $filters): array
    {
        $period = $filters->period;
        $granularity = $period->granularity();
        $buckets = $this->buckets($period, $granularity);
        $previousBuckets = $this->buckets($filters->previous()->period, $granularity);

        $received = $this->bucketCounts($this->cohort($viewer, $filters), 'received_at', $period, $granularity);
        $resolved = $this->bucketCounts($this->scoped($viewer, $filters)->where('resolved_at', '>=', $period->fromUtc())->where('resolved_at', '<', $period->toUtc()), 'resolved_at', $period, $granularity);
        $previous = $this->bucketCounts($this->cohort($viewer, $filters->previous()), 'received_at', $filters->previous()->period, $granularity);

        $points = [];

        foreach ($buckets as $index => [$key, $label]) {
            $previousKey = $previousBuckets[$index][0] ?? null;
            $points[] = [
                'key' => $key,
                'label' => $label,
                'received' => (int) ($received[$key] ?? 0),
                'resolved' => (int) ($resolved[$key] ?? 0),
                'previous_received' => $previousKey === null ? 0 : (int) ($previous[$previousKey] ?? 0),
            ];
        }

        return ['granularity' => $granularity, 'points' => $points];
    }

    /**
     * Conversations received per local weekday (Monday first) and hour.
     *
     * @return array{max: int, cells: list<list<int>>}
     */
    public function heatmap(User $viewer, AnalyticsFilters $filters): array
    {
        [$local, $bindings] = $filters->period->localTimeSql('received_at');
        $rows = $this->cohort($viewer, $filters)
            ->selectRaw("WEEKDAY({$local}) AS weekday, HOUR({$local}) AS hour, COUNT(*) AS total", [...$bindings, ...$bindings])
            ->groupBy('weekday', 'hour')
            ->get();

        $cells = array_fill(0, 7, array_fill(0, 24, 0));
        $max = 0;

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $cells[(int) $row->weekday][(int) $row->hour] = $total;
            $max = max($max, $total);
        }

        return ['max' => $max, 'cells' => array_values(array_map(array_values(...), $cells))];
    }

    /**
     * Figures of the period grouped by agent, department or connection,
     * largest first (ties by id, so the screen and the export list them in
     * the same order). Conversations without agent/department form their
     * own row.
     *
     * @param  'agent_id'|'department_id'|'connection_id'  $column
     * @return list<BreakdownRow>
     */
    public function breakdown(User $viewer, AnalyticsFilters $filters, string $column): array
    {
        $rows = $this->aggregate($this->cohort($viewer, $filters))
            ->addSelect(DB::raw("{$column} AS dimension"))
            ->groupBy($column)
            ->orderByDesc('received')
            ->orderBy($column)
            ->get();

        $ids = $rows->pluck('dimension')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $names = match ($column) {
            'agent_id' => User::query()->whereKey($ids)->pluck('name', 'id'),
            'department_id' => Department::query()->whereKey($ids)->pluck('name', 'id'),
            'connection_id' => Connection::query()->whereKey($ids)->pluck('name', 'id'),
        };
        $loads = $column === 'connection_id' ? null : $this->currentLoads($viewer, $filters, $column === 'agent_id' ? 'assigned_user_id' : 'department_id');
        $empty = match ($column) {
            'agent_id' => 'Sin agente',
            'department_id' => 'Sin departamento',
            'connection_id' => 'Conexión no disponible',
        };

        $result = [];

        foreach ($rows as $row) {
            $id = $row->dimension === null ? null : (int) $row->dimension;
            $totals = $this->normalize($row);
            $result[] = [
                'id' => $id,
                'name' => $id === null ? $empty : (string) ($names[$id] ?? $empty),
                'received' => $totals['received'],
                'attended' => $totals['attended'],
                'responded' => $totals['responded'],
                'resolved' => $totals['resolved'],
                'first_attention_seconds' => $totals['first_attention_avg'],
                'first_response_seconds' => $totals['first_response_avg'],
                'resolution_seconds' => $totals['resolution_avg'],
                'transfers' => $totals['transfers'],
                'reopened' => $totals['reopened'],
                'messages_in' => $totals['messages_in'],
                'messages_out' => $totals['messages_out'],
                'failed_out' => $totals['failed_out'],
                'out_of_hours' => $totals['out_of_hours'],
                'current_load' => $loads === null ? null : (int) ($loads[$id ?? 0] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Conversations received in the filters' period within the reach.
     */
    public function cohort(User $viewer, AnalyticsFilters $filters): Builder
    {
        return $this->scoped($viewer, $filters)
            ->where('received_at', '>=', $filters->period->fromUtc())
            ->where('received_at', '<', $filters->period->toUtc());
    }

    /**
     * Metrics rows within the viewer's reach and the dimension filters.
     */
    private function scoped(User $viewer, AnalyticsFilters $filters): Builder
    {
        $query = DB::table('conversation_metrics');
        $this->scope->apply($query, $viewer, 'agent_id', 'department_id');
        $this->dimensions($query, $filters, 'agent_id');

        return $query;
    }

    private function dimensions(Builder $query, AnalyticsFilters $filters, string $agentColumn): void
    {
        $query
            ->when($filters->departmentId !== null, fn (Builder $q) => $q->where('department_id', $filters->departmentId))
            ->when($filters->agentId !== null, fn (Builder $q) => $q->where($agentColumn, $filters->agentId))
            ->when($filters->connectionId !== null, fn (Builder $q) => $q->where('connection_id', $filters->connectionId));
    }

    private function aggregate(Builder $query, ?int $responseTarget = null, ?int $resolutionTarget = null): Builder
    {
        // Compliance needs an objective; without one it is not computed.
        if ($responseTarget !== null) {
            $query->selectRaw('SUM(CASE WHEN business_seconds_to_first_human_response <= ? THEN 1 ELSE 0 END) AS first_response_sla_met', [$responseTarget]);
        }

        if ($resolutionTarget !== null) {
            $query->selectRaw('SUM(CASE WHEN business_seconds_to_resolution <= ? THEN 1 ELSE 0 END) AS resolution_sla_met', [$resolutionTarget]);
        }

        return $query
            ->selectRaw('COUNT(*) AS received')
            ->selectRaw('SUM(CASE WHEN first_assigned_at IS NOT NULL THEN 1 ELSE 0 END) AS attended')
            ->selectRaw('SUM(CASE WHEN first_human_response_at IS NOT NULL THEN 1 ELSE 0 END) AS responded')
            ->selectRaw('SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved')
            ->selectRaw('SUM(CASE WHEN reopened_count > 0 THEN 1 ELSE 0 END) AS reopened')
            ->selectRaw('SUM(transfers_count) AS transfers')
            ->selectRaw('SUM(CASE WHEN out_of_hours = 1 THEN 1 ELSE 0 END) AS out_of_hours')
            ->selectRaw('SUM(CASE WHEN out_of_hours IS NOT NULL THEN 1 ELSE 0 END) AS out_of_hours_measurable')
            ->selectRaw('AVG(seconds_to_first_attention) AS first_attention_avg, COUNT(seconds_to_first_attention) AS first_attention_samples')
            ->selectRaw('AVG(seconds_to_first_human_response) AS first_response_avg, COUNT(seconds_to_first_human_response) AS first_response_samples')
            ->selectRaw('AVG(seconds_to_resolution) AS resolution_avg, COUNT(seconds_to_resolution) AS resolution_samples')
            ->selectRaw('AVG(business_seconds_to_first_human_response) AS first_response_business_avg, COUNT(business_seconds_to_first_human_response) AS first_response_business_samples')
            ->selectRaw('AVG(business_seconds_to_resolution) AS resolution_business_avg, COUNT(business_seconds_to_resolution) AS resolution_business_samples')
            ->selectRaw('SUM(messages_in) AS messages_in, SUM(messages_out) AS messages_out, SUM(failed_out) AS failed_out');
    }

    /**
     * @return array<string, int|null>
     */
    private function totals(Builder $query, ?int $responseTarget = null, ?int $resolutionTarget = null): array
    {
        return $this->normalize($this->aggregate($query, $responseTarget, $resolutionTarget)->first());
    }

    /**
     * Configured service level objectives in seconds of business time
     * (first response, resolution); null when not configured.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function slaTargets(): array
    {
        $seconds = function (string $key): ?int {
            $minutes = $this->settings->get("sla.{$key}");

            return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes * 60 : null;
        };

        return [$seconds('first_response_minutes'), $seconds('resolution_minutes')];
    }

    /**
     * Compliance against an objective: of the measured conversations, how
     * many took no longer than the target (business time).
     *
     * @param  array<string, int|null>  $current
     * @param  array<string, int|null>  $previous
     * @param  'first_response'|'resolution'  $key
     * @return Sla|null
     */
    private function sla(?int $target, array $current, array $previous, string $key): ?array
    {
        if ($target === null) {
            return null;
        }

        $samples = (int) $current["{$key}_business_samples"];
        $met = (int) $current["{$key}_sla_met"];

        return [
            'target_seconds' => $target,
            'met' => $met,
            'samples' => $samples,
            'rate' => $this->rate($met, $samples),
            'previous_rate' => $this->rate((int) $previous["{$key}_sla_met"], (int) $previous["{$key}_business_samples"]),
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function normalize(?object $row): array
    {
        $values = (array) $row;
        $result = [];

        foreach (['received', 'attended', 'responded', 'resolved', 'reopened', 'transfers', 'out_of_hours', 'out_of_hours_measurable', 'first_attention_samples', 'first_response_samples', 'resolution_samples', 'first_response_business_samples', 'resolution_business_samples', 'first_response_sla_met', 'resolution_sla_met', 'messages_in', 'messages_out', 'failed_out'] as $key) {
            $result[$key] = (int) ($values[$key] ?? 0);
        }

        foreach (['first_attention_avg', 'first_response_avg', 'resolution_avg', 'first_response_business_avg', 'resolution_business_avg'] as $key) {
            $value = $values[$key] ?? null;
            $result[$key] = $value === null ? null : (int) round((float) $value);
        }

        return $result;
    }

    private function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    /**
     * Conversations in attention (open or on hold) now, per agent or department.
     *
     * @return array<int, int> Id (0 for none) => count.
     */
    private function currentLoads(User $viewer, AnalyticsFilters $filters, string $column): array
    {
        $query = DB::table('conversations')->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::OnHold->value]);
        $this->scope->apply($query, $viewer, 'assigned_user_id', 'department_id');
        $this->dimensions($query, $filters, 'assigned_user_id');

        $loads = [];

        $select = $column === 'assigned_user_id' ? 'assigned_user_id AS dimension, COUNT(*) AS total' : 'department_id AS dimension, COUNT(*) AS total';

        foreach ($query->selectRaw($select)->groupBy($column)->get() as $row) {
            $loads[(int) ($row->dimension ?? 0)] = (int) $row->total;
        }

        return $loads;
    }

    /**
     * Agents declared available now and agents able to attend, within the
     * reach; a personal reach does not see colleagues (null).
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function agentAvailability(User $viewer, AnalyticsFilters $filters): array
    {
        if ($this->scope->isPersonal($viewer)) {
            return [null, null];
        }

        $agents = User::query()
            ->visibleTo($viewer)
            ->active()
            ->withPermission(Permission::ReplyConversations)
            ->when($filters->departmentId !== null, fn ($query) => $query->whereHas('departments', fn ($departments) => $departments->whereKey($filters->departmentId)))
            ->when($filters->agentId !== null, fn ($query) => $query->whereKey($filters->agentId))
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN availability = ? THEN 1 ELSE 0 END) AS available', [AgentAvailability::Available->value])
            ->first();

        return [(int) ($agents->available ?? 0), (int) ($agents->total ?? 0)];
    }

    /**
     * Keys and labels of the buckets of a period, in order.
     *
     * @param  'day'|'month'  $granularity
     * @return list<array{0: string, 1: string}>
     */
    private function buckets(AnalyticsPeriod $period, string $granularity): array
    {
        $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $buckets = [];
        $cursor = $granularity === 'day' ? $period->from : $period->from->startOfMonth();

        while ($cursor < $period->to) {
            $label = $granularity === 'day'
                ? $cursor->day.' '.$months[$cursor->month - 1]
                : $months[$cursor->month - 1].' '.$cursor->format('y');
            $buckets[] = [$granularity === 'day' ? $cursor->toDateString() : $cursor->format('Y-m'), $label];
            $cursor = $granularity === 'day' ? $cursor->addDay() : $cursor->addMonthNoOverflow();
        }

        return $buckets;
    }

    /**
     * @param  'received_at'|'resolved_at'  $column
     * @param  'day'|'month'  $granularity
     * @return array<string, int>
     */
    private function bucketCounts(Builder $query, string $column, AnalyticsPeriod $period, string $granularity): array
    {
        [$local, $bindings] = $period->localTimeSql($column);
        $bucket = $granularity === 'day' ? "DATE_FORMAT({$local}, '%Y-%m-%d')" : "DATE_FORMAT({$local}, '%Y-%m')";

        return $query
            ->selectRaw("{$bucket} AS bucket, COUNT(*) AS total", $bindings)
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
