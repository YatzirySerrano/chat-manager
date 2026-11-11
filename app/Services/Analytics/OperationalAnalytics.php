<?php

namespace App\Services\Analytics;

use App\Enums\ConnectionChannel;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Support\ProtectedAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
 * (pending now, load now, active agents now) ignore the period.
 * A figure without enough data is null, never zero.
 *
 * Reach: every figure here follows the ANALYTICS reach (AnalyticsScope, by
 * the data scope of the role over the metrics' agent and department), not
 * the inbox visibility (Conversation::visibleTo) the dashboard uses for its
 * live counts. Both are kept on purpose and labelled apart in the interface.
 *
 * Definitions shared by the dashboard, the reports and the exports (each one
 * is computed here once):
 * - received: conversations created in the period;
 * - attended / responded / resolved ("finalizadas de las recibidas"): of the
 *   received ones, those with human attention / a human reply / at least
 *   one closing;
 * - closed in the period ("finalizadas en el periodo"): conversations whose
 *   FIRST closing happened in the period, whenever they arrived. It is the
 *   only figure bucketed by closing date (the trend line and the backlog);
 * - backlog ("acumuladas sin atender"): conversations received and not yet
 *   attended nor closed at a given moment (`left_backlog_at`);
 * - comparisons use AnalyticsFilters::comparison(): the previous period,
 *   cut to the elapsed portion while the current one is in progress.
 *
 * @phpstan-type Comparable array{value: int|float|null, previous: int|float|null}
 * @phpstan-type Duration array{value: int|null, previous: int|null, samples: int}
 * @phpstan-type BreakdownRow array{id: int|null, name: string, received: int, attended: int, responded: int, resolved: int, first_attention_seconds: int|null, first_response_seconds: int|null, first_response_samples: int, resolution_seconds: int|null, transfers: int, reopened: int, messages_in: int, messages_out: int, failed_out: int, out_of_hours: int, current_load: int|null}
 * @phpstan-type SourcedBreakdownRow array{id: int|null, name: string, received: int, attended: int, responded: int, resolved: int, first_attention_seconds: int|null, first_response_seconds: int|null, first_response_samples: int, resolution_seconds: int|null, transfers: int, reopened: int, messages_in: int, messages_out: int, failed_out: int, out_of_hours: int, current_load: int|null, source_id: int|null}
 * @phpstan-type Sla array{target_seconds: int, met: int, samples: int, rate: float|null, previous_rate: float|null}
 * @phpstan-type Range array{min: int|null, max: int|null}
 * @phpstan-type Backlog array{now: int, start: int, end: int|null, granularity: 'day'|'week'|'month', points: list<array{key: string, label: string, received: int, left: int, backlog: int|null}>}
 * @phpstan-type Summary array{received: Comparable, closed_in_period: Comparable, first_response_range: Range, resolution_range: Range, attended: Comparable, responded: Comparable, resolved: Comparable, reopened: Comparable, transfers: Comparable, out_of_hours: Comparable, out_of_hours_measurable: int, first_attention_seconds: Duration, first_response_seconds: Duration, resolution_seconds: Duration, first_response_business_seconds: Duration, resolution_business_seconds: Duration, first_response_sla: Sla|null, resolution_sla: Sla|null, messages_in: Comparable, messages_out: Comparable, failed_out: Comparable, failure_rate: Comparable}
 * @phpstan-type Snapshot array{pending: int, open: int, agents: int|null}
 * @phpstan-type TrendPoint array{key: string, label: string, received: int, resolved: int, previous_received: int}
 * @phpstan-type ActivityPoint array{key: string, label: string, received: int, attended: int, first_response_seconds: int|null, first_response_samples: int}
 * @phpstan-type Activity array{granularity: 'hour'|'day'|'week'|'month', points: list<ActivityPoint>}
 * @phpstan-type ChannelRow array{channel: string|null, label: string, received: int, attended: int}
 * @phpstan-type Analytics array{summary: Summary, current: Snapshot, statuses: list<array{status: string, label: string, count: int}>, trend: array{granularity: 'day'|'week'|'month', points: list<TrendPoint>}, activity: Activity, heatmap: array{max: int, cells: list<list<int>>}, backlog: Backlog, by_agent: list<BreakdownRow>, by_department: list<BreakdownRow>, by_connection: list<BreakdownRow>, by_category: list<BreakdownRow>, by_channel: list<ChannelRow>, response_trend: array<string, mixed>, sla: array<string, mixed>, productivity: array<string, mixed>}
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
        private ProtectedAccounts $protectedAccounts,
        private ServiceLevelAnalytics $serviceLevels,
        private AgentProductivity $productivity,
        private AnalyticsDimensions $dimensionFilters,
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
        $byConnection = $this->breakdown($viewer, $filters, 'connection_id');

        return [
            'summary' => $this->summary($viewer, $filters),
            'current' => $this->snapshot($viewer, $filters),
            'statuses' => $this->statuses($viewer, $filters),
            'trend' => $this->trend($viewer, $filters),
            'activity' => $this->activity($viewer, $filters),
            'heatmap' => $this->heatmap($viewer, $filters),
            'backlog' => $this->backlog($viewer, $filters),
            'by_agent' => $this->breakdown($viewer, $filters, 'agent_id'),
            'by_department' => $this->scope->isPersonal($viewer) ? [] : $this->breakdown($viewer, $filters, 'department_id'),
            'by_connection' => $byConnection,
            'by_category' => $this->breakdown($viewer, $filters, 'category_id'),
            'by_channel' => $this->channelsOf($byConnection),
            'response_trend' => $this->responseTrend($viewer, $filters),
            'sla' => $this->serviceLevels->compliance($viewer, $filters),
            'productivity' => $this->productivity->report($viewer, $filters),
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
        // Like with like: an unfinished period is compared with the same elapsed portion of the previous one.
        $comparison = $filters->comparison();
        $previous = $this->totals($this->cohort($viewer, $comparison), $responseTarget, $resolutionTarget);

        $comparable = fn (string $key): array => ['value' => $current[$key], 'previous' => $previous[$key]];
        $duration = fn (string $key): array => [
            'value' => $current["{$key}_avg"],
            'previous' => $previous["{$key}_avg"],
            'samples' => $current["{$key}_samples"],
        ];

        return [
            'received' => $comparable('received'),
            'closed_in_period' => [
                'value' => $this->closedBetween($viewer, $filters),
                'previous' => $this->closedBetween($viewer, $comparison),
            ],
            'first_response_range' => ['min' => $current['first_response_min'], 'max' => $current['first_response_max']],
            'resolution_range' => ['min' => $current['resolution_min'], 'max' => $current['resolution_max']],
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
        // Live figures: imported history is not current activity, and the status filter cannot apply to "now".
        $query = DB::table('conversations')
            ->where('origin', ConversationOrigin::Live->value)
            ->where('status', '!=', ConversationStatus::Closed->value);
        $this->scope->apply($query, $viewer, 'assigned_user_id', 'department_id');
        $this->dimensions($query, $filters->withoutStatus(), 'assigned_user_id');

        $counts = $query->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return [
            'pending' => (int) ($counts[ConversationStatus::Pending->value] ?? 0),
            // Legacy on_hold rows (not migrated yet) are still in attention.
            'open' => (int) ($counts[ConversationStatus::Open->value] ?? 0) + (int) ($counts[ConversationStatus::OnHold->value] ?? 0),
            'agents' => $this->activeAgents($viewer, $filters),
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
            'count' => (int) ($counts[$status->value] ?? 0)
                + ($status === ConversationStatus::Open ? (int) ($counts[ConversationStatus::OnHold->value] ?? 0) : 0),
        ], ConversationStatus::operational());
    }

    /**
     * Conversations received and resolved per local day (or month), with
     * the received ones of the aligned bucket of the previous period.
     *
     * @return array{granularity: 'day'|'week'|'month', points: list<TrendPoint>}
     */
    public function trend(User $viewer, AnalyticsFilters $filters): array
    {
        $period = $filters->period;
        $granularity = $period->granularity();
        $buckets = $period->buckets($granularity);
        $previousBuckets = $filters->previous()->period->buckets($granularity);

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
     * Conversations received per local hour (periods of one day), day or
     * month, with how many of them were attended and the average first human
     * response of the ones answered. Same cohort and definitions as the
     * summary, so the buckets add up to its received and attended figures.
     * Every bucket is listed (counts of 0 included); the average is null in
     * a bucket without measured responses, never a fake 0.
     *
     * A local hour repeated when daylight saving ends forms one bucket.
     *
     * @return Activity
     */
    public function activity(User $viewer, AnalyticsFilters $filters): array
    {
        $period = $filters->period;
        $granularity = $this->activityGranularity($period);
        [$local, $bindings] = $period->localTimeSql('received_at');
        $bucket = AnalyticsPeriod::bucketSql($local, $granularity);

        $rows = $this->cohort($viewer, $filters)
            ->selectRaw("{$bucket} AS bucket", $bindings)
            ->selectRaw('COUNT(*) AS received')
            ->selectRaw('SUM(CASE WHEN first_assigned_at IS NOT NULL THEN 1 ELSE 0 END) AS attended')
            ->selectRaw('AVG(seconds_to_first_human_response) AS first_response_avg, COUNT(seconds_to_first_human_response) AS first_response_samples')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = [];

        foreach ($this->buckets($period, $granularity) as [$key, $label]) {
            $row = $rows->get($key);
            $samples = (int) ($row->first_response_samples ?? 0);
            $points[] = [
                'key' => $key,
                'label' => $label,
                'received' => (int) ($row->received ?? 0),
                'attended' => (int) ($row->attended ?? 0),
                'first_response_seconds' => $samples > 0 && $row->first_response_avg !== null ? (int) round((float) $row->first_response_avg) : null,
                'first_response_samples' => $samples,
            ];
        }

        return ['granularity' => $granularity, 'points' => $points];
    }

    /**
     * Average first human response per bucket of the period next to the
     * aligned bucket of the whole previous period ("are we answering
     * faster?"). A bucket without measured responses is null on its side.
     *
     * @param  Activity|null  $current  Activity of the period when the caller already has it.
     * @return array{granularity: 'hour'|'day'|'week'|'month', points: list<array{key: string, label: string, seconds: int|null, samples: int, previous_seconds: int|null, previous_samples: int}>}
     */
    public function responseTrend(User $viewer, AnalyticsFilters $filters, ?array $current = null): array
    {
        $current ??= $this->activity($viewer, $filters);
        $previous = $this->activity($viewer, $filters->previous())['points'];
        $points = [];

        foreach ($current['points'] as $index => $point) {
            $points[] = [
                'key' => $point['key'],
                'label' => $point['label'],
                'seconds' => $point['first_response_seconds'],
                'samples' => $point['first_response_samples'],
                'previous_seconds' => $previous[$index]['first_response_seconds'] ?? null,
                'previous_samples' => $previous[$index]['first_response_samples'] ?? 0,
            ];
        }

        return ['granularity' => $current['granularity'], 'points' => $points];
    }

    /**
     * Charted per hour when the period lasts one day or less.
     *
     * @return 'hour'|'day'|'week'|'month'
     */
    public function activityGranularity(AnalyticsPeriod $period): string
    {
        return $period->days() <= 1 ? 'hour' : $period->granularity();
    }

    /**
     * Figures of the period per channel, derived from the per connection
     * breakdown (so both always agree). Channels without conversations are
     * left out; deleted connections keep counting in their channel.
     *
     * @return list<ChannelRow>
     */
    public function byChannel(User $viewer, AnalyticsFilters $filters): array
    {
        return $this->channelsOf($this->breakdown($viewer, $filters, 'connection_id'));
    }

    /**
     * @param  list<BreakdownRow>  $connections
     * @return list<ChannelRow>
     */
    private function channelsOf(array $connections): array
    {
        $ids = array_values(array_filter(array_column($connections, 'id'), fn (?int $id): bool => $id !== null));
        // Logical deletion is not a global scope: deleted connections resolve too.
        $channels = $ids === [] ? collect() : Connection::query()->whereKey($ids)->toBase()->pluck('channel', 'id');
        $totals = [];

        foreach ($connections as $row) {
            $channel = $row['id'] === null ? null : ConnectionChannel::tryFrom((string) ($channels[$row['id']] ?? ''));
            $key = $channel->value ?? '';
            $totals[$key] ??= [
                'channel' => $channel?->value,
                'label' => $channel?->label() ?? 'Canal no disponible',
                'received' => 0,
                'attended' => 0,
            ];
            $totals[$key]['received'] += $row['received'];
            $totals[$key]['attended'] += $row['attended'];
        }

        $order = array_flip(array_map(fn (ConnectionChannel $channel): string => $channel->value, ConnectionChannel::cases()));
        $rows = array_values(array_filter($totals, fn (array $row): bool => $row['received'] > 0));
        usort($rows, fn (array $a, array $b): int => [$b['received'], $order[$a['channel'] ?? ''] ?? PHP_INT_MAX] <=> [$a['received'], $order[$b['channel'] ?? ''] ?? PHP_INT_MAX]);

        return $rows;
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
     * own row. For a viewer without root authority, a protected (root)
     * agent keeps its figures under ProtectedAccounts::SYSTEM_NAME with a
     * null id. Exports that must tell the rows apart ask for `source_id`
     * (the real id), which never reaches the screen.
     *
     * @param  'agent_id'|'department_id'|'connection_id'|'category_id'  $column
     * @return ($withSourceIds is true ? list<SourcedBreakdownRow> : list<BreakdownRow>)
     */
    public function breakdown(User $viewer, AnalyticsFilters $filters, string $column, bool $withSourceIds = false): array
    {
        $rows = $this->aggregate($this->cohort($viewer, $filters))
            ->addSelect(DB::raw("{$column} AS dimension"))
            ->groupBy($column)
            ->orderByDesc('received')
            ->orderBy($column)
            ->get();

        $ids = $rows->pluck('dimension')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $protected = [];

        if ($column === 'agent_id' && $ids !== [] && ! $viewer->hasRootAuthority()) {
            $protected = array_flip(array_values(array_intersect($ids, $this->protectedAccounts->ids())));
        }

        $names = match ($column) {
            'agent_id' => User::query()->whereKey($ids)->pluck('name', 'id'),
            'department_id' => Department::query()->whereKey($ids)->pluck('name', 'id'),
            'connection_id' => Connection::query()->whereKey($ids)->pluck('name', 'id'),
            'category_id' => ConversationCategory::query()->whereKey($ids)->pluck('name', 'id'),
        };
        $loads = in_array($column, ['connection_id', 'category_id'], true) ? null : $this->currentLoads($viewer, $filters, $column === 'agent_id' ? 'assigned_user_id' : 'department_id');
        $empty = match ($column) {
            'agent_id' => 'Sin agente',
            'department_id' => 'Sin departamento',
            'connection_id' => 'Conexión no disponible',
            'category_id' => 'Sin categoría',
        };

        $result = [];

        foreach ($rows as $row) {
            $id = $row->dimension === null ? null : (int) $row->dimension;
            $totals = $this->normalize($row);
            $hidden = $id !== null && isset($protected[$id]);
            $result[] = [
                'id' => $hidden ? null : $id,
                'name' => match (true) {
                    $hidden => ProtectedAccounts::SYSTEM_NAME,
                    $id === null => $empty,
                    default => (string) ($names[$id] ?? $empty),
                },
                'received' => $totals['received'],
                'attended' => $totals['attended'],
                'responded' => $totals['responded'],
                'resolved' => $totals['resolved'],
                'first_attention_seconds' => $totals['first_attention_avg'],
                'first_response_seconds' => $totals['first_response_avg'],
                'first_response_samples' => $totals['first_response_samples'],
                'resolution_seconds' => $totals['resolution_avg'],
                'transfers' => $totals['transfers'],
                'reopened' => $totals['reopened'],
                'messages_in' => $totals['messages_in'],
                'messages_out' => $totals['messages_out'],
                'failed_out' => $totals['failed_out'],
                'out_of_hours' => $totals['out_of_hours'],
                'current_load' => $loads === null ? null : (int) ($loads[$id ?? 0] ?? 0),
                ...($withSourceIds ? ['source_id' => $id] : []),
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
        $this->dimensionFilters->apply($query, $filters, $agentColumn);
    }

    /**
     * Conversations whose first closing happened in the filters' period
     * (whenever they arrived), within the reach and the filters.
     */
    public function closedBetween(User $viewer, AnalyticsFilters $filters): int
    {
        return $this->scoped($viewer, $filters)
            ->where('resolved_at', '>=', $filters->period->fromUtc())
            ->where('resolved_at', '<', $filters->period->toUtc())
            ->count();
    }

    /**
     * Unattended conversations accumulated at the end of each bucket of the
     * period: received before that moment and, by then, neither attended by
     * a person nor closed. It starts from the backlog at the beginning of
     * the period and adds what arrived and subtracts what left in each
     * bucket, so it only reads indexed ranges (never the whole history).
     * Buckets that have not finished yet have no value (null).
     *
     * The status filter is not applied: the backlog is about the past
     * state of each conversation, not its status today.
     *
     * @return Backlog
     */
    public function backlog(User $viewer, AnalyticsFilters $filters): array
    {
        $filters = $filters->withoutStatus();
        $period = $filters->period;
        $granularity = $period->granularity();
        $from = $period->fromUtc();

        $start = $this->scoped($viewer, $filters)->whereNull('left_backlog_at')->where('received_at', '<', $from)->count()
            + $this->scoped($viewer, $filters)->where('left_backlog_at', '>=', $from)->where('received_at', '<', $from)->count();

        $received = $this->bucketCounts($this->cohort($viewer, $filters), 'received_at', $period, $granularity);
        $left = $this->bucketCounts(
            $this->scoped($viewer, $filters)->where('left_backlog_at', '>=', $from)->where('left_backlog_at', '<', $period->toUtc()),
            'left_backlog_at',
            $period,
            $granularity,
        );

        $buckets = $period->buckets($granularity);
        $ends = array_slice(array_column($buckets, 0), 1);
        $now = $period->asOf ?? CarbonImmutable::now();
        $running = $start;
        $points = [];
        $end = null;

        foreach ($buckets as $index => [$key, $label]) {
            $running += ($received[$key] ?? 0) - ($left[$key] ?? 0);
            // A bucket is finished when the next one already started (the last one, when the period ended).
            $finished = isset($ends[$index])
                ? $this->bucketStart($period, $granularity, $ends[$index])->lessThanOrEqualTo($now)
                : $period->to->lessThanOrEqualTo($now);
            $points[] = [
                'key' => $key,
                'label' => $label,
                'received' => (int) ($received[$key] ?? 0),
                'left' => (int) ($left[$key] ?? 0),
                'backlog' => $finished ? max(0, $running) : null,
            ];

            if ($finished) {
                $end = max(0, $running);
            }
        }

        return [
            'now' => $this->scoped($viewer, $filters)->whereNull('left_backlog_at')->count(),
            'start' => $start,
            'end' => $end,
            'granularity' => $granularity,
            'points' => $points,
        ];
    }

    /**
     * Local start of a bucket from its key.
     *
     * @param  'day'|'week'|'month'  $granularity
     */
    private function bucketStart(AnalyticsPeriod $period, string $granularity, string $key): CarbonImmutable
    {
        return match ($granularity) {
            'day' => CarbonImmutable::createFromFormat('!Y-m-d', $key, $period->timezone),
            'week' => CarbonImmutable::now($period->timezone)->setISODate((int) substr($key, 0, 4), (int) substr($key, 6))->startOfDay(),
            'month' => CarbonImmutable::createFromFormat('!Y-m', $key, $period->timezone),
        } ?: $period->to;
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
            ->selectRaw('MIN(seconds_to_first_human_response) AS first_response_min, MAX(seconds_to_first_human_response) AS first_response_max')
            ->selectRaw('AVG(seconds_to_resolution) AS resolution_avg, COUNT(seconds_to_resolution) AS resolution_samples')
            ->selectRaw('MIN(seconds_to_resolution) AS resolution_min, MAX(seconds_to_resolution) AS resolution_max')
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

        foreach (['first_attention_avg', 'first_response_avg', 'resolution_avg', 'first_response_business_avg', 'resolution_business_avg', 'first_response_min', 'first_response_max', 'resolution_min', 'resolution_max'] as $key) {
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
     * Conversations in attention now (including legacy on_hold rows), per agent or department.
     *
     * @return array<int, int> Id (0 for none) => count.
     */
    private function currentLoads(User $viewer, AnalyticsFilters $filters, string $column): array
    {
        $query = DB::table('conversations')
            ->where('origin', ConversationOrigin::Live->value)
            ->whereIn('status', array_map(fn (ConversationStatus $status): string => $status->value, ConversationStatus::attending()));
        $this->scope->apply($query, $viewer, 'assigned_user_id', 'department_id');
        $this->dimensions($query, $filters->withoutStatus(), 'assigned_user_id');

        $loads = [];

        $select = $column === 'assigned_user_id' ? 'assigned_user_id AS dimension, COUNT(*) AS total' : 'department_id AS dimension, COUNT(*) AS total';

        foreach ($query->selectRaw($select)->groupBy($column)->get() as $row) {
            $loads[(int) ($row->dimension ?? 0)] = (int) $row->total;
        }

        return $loads;
    }

    /**
     * Active agents (active account and role able to reply) within the
     * reach; a personal reach does not see colleagues (null). There is no
     * manual availability any more. The single definition of "agentes
     * activos": the dashboard reads it from here too.
     */
    public function activeAgents(User $viewer, AnalyticsFilters $filters): ?int
    {
        if ($this->scope->isPersonal($viewer)) {
            return null;
        }

        $agents = User::query()
            ->visibleTo($viewer)
            ->withoutProtectedFor($viewer)
            ->active()
            ->withPermission(Permission::ReplyConversations)
            ->when($filters->departmentId !== null, fn (EloquentBuilder $query) => $query->whereHas('departments', fn (EloquentBuilder $departments) => $departments->whereKey($filters->departmentId)))
            ->when($filters->agentId !== null, fn (EloquentBuilder $query) => $query->whereKey($filters->agentId))
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->first();

        return (int) ($agents->total ?? 0);
    }

    /**
     * Keys and labels of the buckets of a period, in order.
     *
     * @param  'hour'|'day'|'week'|'month'  $granularity
     * @return list<array{0: string, 1: string}>
     */
    private function buckets(AnalyticsPeriod $period, string $granularity): array
    {
        return $granularity === 'hour' ? $this->hourBuckets($period) : $period->buckets($granularity);
    }

    /**
     * Local hours of a period ("2026-10-21T09:00", "09:00"), walked in UTC
     * so daylight saving changes neither skip nor duplicate a bucket.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function hourBuckets(AnalyticsPeriod $period): array
    {
        $buckets = [];
        $cursor = $period->fromUtc();
        $end = $period->toUtc();

        while ($cursor < $end) {
            $local = $cursor->setTimezone($period->timezone);
            $buckets[$local->format('Y-m-d\TH:00')] ??= $local->format('H:00');
            $cursor = $cursor->addHour();
        }

        return array_map(fn (string $key, string $label): array => [$key, $label], array_keys($buckets), array_values($buckets));
    }

    /**
     * @param  'received_at'|'resolved_at'|'left_backlog_at'  $column
     * @param  'day'|'week'|'month'  $granularity
     * @return array<string, int>
     */
    private function bucketCounts(Builder $query, string $column, AnalyticsPeriod $period, string $granularity): array
    {
        [$local, $bindings] = $period->localTimeSql($column);
        $bucket = AnalyticsPeriod::bucketSql($local, $granularity);

        return $query
            ->selectRaw("{$bucket} AS bucket, COUNT(*) AS total", $bindings)
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
