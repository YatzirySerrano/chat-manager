<?php

/*
| Data for the charts browser scenario (tests/e2e/graficas.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php:
| superadministrator, Ana and Beto in E2E Ventas, Carla in E2E Soporte).
|
|   php tests/e2e/graficas-seed.php                     creates the data
|   php tests/e2e/graficas-seed.php charts <email> <period>
|                                                       prints DashboardMetrics::chartsFor()
|   php tests/e2e/graficas-seed.php analytics <email> <period>
|                                                       prints OperationalAnalytics::compute()
|
| Conversations over the last six local days (none today), on a WhatsApp
| and a web chat connection, assigned to Ana, Beto, Carla or nobody, in both
| departments and in every status. Metrics are rebuilt and then fixed so the
| figures are deterministic: three days before today has conversations but
| no measured first response (a gap in the line, never 0 s).
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. Run with APP_ENV=testing.
*/

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\User;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use App\Services\DashboardMetrics;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabase;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();

try {
    TestDatabase::guard($database);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

$mode = $argv[1] ?? 'setup';

if ($mode === 'charts' || $mode === 'analytics') {
    $viewer = User::query()->where('email', $argv[2] ?? '')->firstOrFail();
    $input = ['period' => $argv[3] ?? '7d'];
    $metrics = app(DashboardMetrics::class);
    $filters = $metrics->filters($viewer, $input);

    echo json_encode(
        $mode === 'charts'
            ? $metrics->chartsFor($viewer, $filters)
            : app(OperationalAnalytics::class)->compute($viewer, $filters),
        JSON_PRETTY_PRINT,
    ), PHP_EOL;

    exit(0);
}

$timezone = (string) app(PlatformSettings::class)->get('general.timezone', 'America/Mexico_City');
$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$support = Department::query()->where('slug', 'e2e-soporte')->firstOrFail();
$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
$beto = User::query()->where('email', 'e2e-beto@example.test')->firstOrFail();
$carla = User::query()->where('email', 'e2e-carla@example.test')->firstOrFail();

$whatsapp = Connection::factory()->operational()->create(['name' => 'E2E WhatsApp Gráficas', 'default_department_id' => $sales->id]);
$webChat = Connection::factory()->webChat()->create(['name' => 'E2E Chat web Gráficas', 'default_department_id' => $support->id]);

/*
| [days ago, connection, department, agent|null, status, first response seconds|null]
| Day 3 has conversations but no measured response (gap).
*/
$plan = [
    [6, $whatsapp, $sales, $ana, 'closed', 120],
    [6, $whatsapp, $sales, $beto, 'closed', 240],
    [5, $whatsapp, $sales, $ana, 'open', 60],
    [5, $webChat, $support, $carla, 'closed', 600],
    [5, $webChat, $support, null, 'pending', null],
    [4, $whatsapp, $sales, $beto, 'open', 90],
    [3, $whatsapp, $sales, null, 'pending', null],
    [3, $webChat, $support, $carla, 'open', null],
    [2, $whatsapp, $sales, $ana, 'closed', 300],
    [2, $whatsapp, $sales, $ana, 'open', 180],
    [2, $webChat, $support, $carla, 'closed', 45],
    [1, $whatsapp, $sales, $beto, 'closed', 30],
    [1, $webChat, $support, null, 'pending', null],
];

$created = [];

foreach ($plan as [$daysAgo, $connection, $department, $agent, $status, $seconds]) {
    $at = CarbonImmutable::now($timezone)->subDays($daysAgo)->setTime(10, 15)->utc();
    $factory = Conversation::factory()->for($connection, 'channelConnection')->inDepartment($department);
    $factory = $agent === null ? $factory : $factory->assignedTo($agent);
    $factory = $status === 'closed' ? $factory->closed() : $factory;
    $conversation = $factory->create([
        'created_at' => $at,
        'updated_at' => $at,
        'last_message_at' => $at,
        'last_inbound_at' => $at,
        ...($status === 'closed' ? ['closed_at' => $at->addHour()] : []),
        ...($agent !== null ? ['assigned_at' => $at->addMinutes(5)] : []),
    ]);
    $created[] = [$conversation->id, $agent !== null, $seconds, $at, $status === 'closed'];
}

app(RebuildConversationMetrics::class)->handle();

foreach ($created as [$id, $attended, $seconds, $at, $closed]) {
    ConversationMetric::query()->where('conversation_id', $id)->update([
        'received_at' => $at,
        'first_assigned_at' => $attended ? $at->addMinutes(5) : null,
        'seconds_to_first_human_response' => $seconds,
        'first_human_response_at' => $seconds === null ? null : $at->addSeconds($seconds),
        'resolved_at' => $closed ? $at->addHour() : null,
    ]);
}

Cache::flush();

echo json_encode([
    'timezone' => $timezone,
    'conversations' => count($created),
    'connections' => ['whatsapp' => $whatsapp->id, 'web_chat' => $webChat->id],
    'scope_check' => app(AnalyticsScope::class)->label(User::query()->where('email', 'e2e-admin@example.test')->firstOrFail()),
], JSON_PRETTY_PRINT), PHP_EOL;
