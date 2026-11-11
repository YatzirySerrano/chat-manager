<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use App\Services\Analytics\ServiceLevelAnalytics;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/*
| The reports must keep reading the derived tables through their indexes as
| history grows: a fixed number of queries whatever the volume, never the
| messages nor the event log, and every range query with a usable index
| (plans on 300 000 rows are recorded in docs/reportes-y-exportaciones.md).
*/

/**
 * Conversations of several agents, departments and categories with their
 * derived rows and one response cycle each.
 */
function analyticsVolume(Connection $connection, int $groups): void
{
    for ($group = 0; $group < $groups; $group++) {
        $department = Department::factory()->create();
        $agent = User::factory()->agent()->inDepartments($department)->create();
        $category = ConversationCategory::factory()->create();

        Conversation::factory()->count(2)->for($connection, 'channelConnection')->inDepartment($department)->assignedTo($agent)
            ->create(['category_id' => $category->id, 'created_at' => now()->subDay()])
            ->each(fn (Conversation $conversation) => ResponseCycle::factory()->create(['conversation_id' => $conversation->id, 'started_at' => now()->subDay(), 'target_seconds' => 300, 'due_at' => now()->subDay()->addSeconds(300)]));
    }

    app(RebuildConversationMetrics::class)->handle();
}

/**
 * SQL of every query run while computing the whole report.
 *
 * @return list<string>
 */
function analyticsReportQueries(User $viewer): array
{
    $queries = [];
    $recording = true;
    DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
        if ($recording) {
            $queries[] = $query->sql;
        }
    });

    app(OperationalAnalytics::class)->compute($viewer, AnalyticsFilters::from(['period' => '7d'], $viewer, app(AnalyticsScope::class), 'America/Mexico_City'));
    $recording = false;

    return $queries;
}

test('the whole report costs the same queries with 2 or 12 agents, departments and categories', function () {
    $connection = Connection::factory()->operational()->create();
    $admin = User::factory()->administrator()->create();

    analyticsVolume($connection, 2);
    analyticsReportQueries($admin); // warms per-process caches (permissions, settings)
    $few = analyticsReportQueries($admin);

    analyticsVolume($connection, 10);
    $many = analyticsReportQueries($admin);

    expect(count($many))->toBe(count($few))
        // Aggregates come from the derived tables: the messages and the event log are never read per request.
        ->and(collect($many)->filter(fn (string $sql): bool => preg_match('/\b(from|join)\s+`(messages|conversation_events)`/i', $sql) === 1)->all())->toBe([]);
});

test('every range query of the reports has an index it can use', function () {
    $admin = User::factory()->administrator()->create();
    $lead = User::factory()->supervisor()->inDepartments(Department::factory()->create())->create();
    $analytics = app(OperationalAnalytics::class);
    $serviceLevels = app(ServiceLevelAnalytics::class);
    $keys = fn (Builder $query): array => collect(DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()))
        ->flatMap(fn (object $row): array => explode(',', (string) ($row->possible_keys ?? '')))
        ->filter()
        ->values()
        ->all();
    $filters = fn (User $viewer, array $input = []): AnalyticsFilters => AnalyticsFilters::from(['period' => '30d', ...$input], $viewer, app(AnalyticsScope::class), 'America/Mexico_City');
    $period = $filters($admin)->period;

    expect($keys($analytics->cohort($admin, $filters($admin))))->toContain('conversation_metrics_received_at_index')
        ->and($keys($analytics->cohort($admin, $filters($admin, ['status' => 'open']))))->toContain('conversation_metrics_status_received_at_index')
        ->and($keys($analytics->cohort($admin, $filters($admin, ['category' => ConversationCategory::factory()->create()->id]))))->toContain('conversation_metrics_category_id_received_at_index')
        ->and($keys($analytics->cohort($lead, $filters($lead))))->toContain('conversation_metrics_department_id_received_at_index', 'conversation_metrics_agent_id_received_at_index')
        ->and($keys(DB::table('conversation_metrics')->whereNull('left_backlog_at')->where('received_at', '<', $period->fromUtc())))->toContain('conversation_metrics_left_backlog_at_index')
        ->and($keys(DB::table('conversation_agent_metrics')->where('user_id', $lead->id)->where('received_at', '>=', $period->fromUtc())))->toContain('conversation_agent_metrics_user_id_received_at_index')
        ->and($keys(DB::table('conversation_agent_metrics')->where('received_at', '>=', $period->fromUtc())->where('received_at', '<', $period->toUtc())))->toContain('conversation_agent_metrics_received_at_index')
        ->and($keys($serviceLevels->cycles($admin, $filters($admin))))->toContain('response_cycles_started_at_index');
});
