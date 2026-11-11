<?php

/*
| Data of the indicators scenario (tests/e2e/reportes-indicadores.mjs), on
| top of tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php:
| the superadministrator, E2E Ana and E2E Beto in E2E Ventas).
|
|   php tests/e2e/reportes-indicadores-seed.php setup
|       Six requests of today, produced with the application's own actions
|       (simulated webhook, categorize, accept, reply, close), so every
|       figure comes from real history:
|
|         customer            category     agent   what happened
|         Cliente Reporte 1   Garantías    Ana     answered in time, closed
|         Cliente Reporte 2   Garantías    Ana     answered after the deadline
|         Cliente Reporte 3   Facturación  Beto    answered in time, closed
|         Cliente Reporte 4   Facturación  Beto    accepted, still waiting
|         Cliente Reporte 5   (none)       —       pending in the queue
|         Cliente Reporte 6   Garantías    —       pending in the queue
|
|       The objective is a first response in 10 minutes of calendar time.
|       SIMULACIÓN of elapsed time for request 2: its response cycle is
|       moved back past its deadline and `sla:evaluate` records the breach
|       before Ana answers. Metrics are rebuilt at the end.
|       -> {"conversations", "categories": {...}, "ana", "beto"}
|   php tests/e2e/reportes-indicadores-seed.php figures <email> [json filters]
|       -> what the server computes for that person and filters
|          (OperationalAnalytics::compute(), not the cached report):
|          {"received", "attended", "resolved", "productivity": [names],
|           "sla": {...}, "trend_points"}
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to Meta (run it with
| WHATSAPP_TRANSPORT=simulated).
*/

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Conversations\QueueOutboundMessage;
use App\Actions\Conversations\SetConversationCategory;
use App\Enums\ColorSwatch;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\Analytics\OperationalAnalytics;
use App\Services\DashboardMetrics;
use App\Services\Meta\SimulatedWebhook;
use App\Services\PlatformSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestDatabase;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    TestDatabase::guard(DB::connection()->getDatabaseName());
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

const REPORTS_PHONE_NUMBER_ID = '880000000000002';

$command = $argv[1] ?? 'setup';

if ($command === 'figures') {
    $viewer = User::query()->where('email', $argv[2] ?? '')->firstOrFail();
    $input = json_decode($argv[3] ?? '{}', true) ?: [];
    $filters = app(DashboardMetrics::class)->filters($viewer, ['period' => '7d', ...$input]);
    $analytics = app(OperationalAnalytics::class)->compute($viewer, $filters);

    echo json_encode([
        'received' => $analytics['summary']['received']['value'],
        'attended' => $analytics['summary']['attended']['value'],
        'resolved' => $analytics['summary']['resolved']['value'],
        'productivity' => array_column($analytics['productivity']['rows'], 'name'),
        'sla' => $analytics['sla'],
        'trend_points' => count($analytics['trend']['points'] ?? $analytics['trend']),
    ]), PHP_EOL;
    exit(0);
}

if ($command !== 'setup') {
    fwrite(STDERR, 'Unknown command.'.PHP_EOL);
    exit(1);
}

app(PlatformSettings::class)->update([
    'operations' => ['pending_block_enabled' => false],
    'business_hours' => ['enabled' => false],
    'sla' => ['first_response_minutes' => 10, 'business_hours_only' => false],
]);

$admin = User::query()->where('email', 'e2e-admin@example.test')->firstOrFail();
$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
$beto = User::query()->where('email', 'e2e-beto@example.test')->firstOrFail();
$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$whatsapp = Connection::query()->where('phone_number_id', REPORTS_PHONE_NUMBER_ID)->firstOrFail();
$whatsapp->forceFill([
    'is_active' => true,
    // Throwaway values: without them the connection cannot send (simulated transport).
    'business_account_id' => $whatsapp->business_account_id ?? '990000000000002',
    'access_token' => $whatsapp->access_token ?? 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $whatsapp->app_secret ?? Str::random(40),
    'welcome_enabled' => false,
    'default_department_id' => $sales->id,
])->save();

$warranties = ConversationCategory::factory()->create(['name' => 'E2E Garantías', 'description' => null, 'color' => ColorSwatch::Blue]);
$billing = ConversationCategory::factory()->create(['name' => 'E2E Facturación', 'description' => null, 'color' => ColorSwatch::Emerald]);

$inbound = function (int $number) use ($whatsapp): Conversation {
    $receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
        REPORTS_PHONE_NUMBER_ID,
        (string) $whatsapp->business_account_id,
        '52155112000'.sprintf('%02d', $number),
        "Cliente Reporte {$number}",
        'wamid.e2e.'.Str::random(16),
        'text',
        "Hola, necesito ayuda con mi solicitud {$number}",
        now()->getTimestamp(),
    ));

    return $receipts[0]->conversation->refresh();
};

$reply = fn (Conversation $conversation, User $agent): mixed => app(QueueOutboundMessage::class)
    ->handle($conversation->refresh(), $agent, 'Con gusto te ayudo.', (string) Str::uuid());

/* [category|null, agent|null, reply?, late?, close?] */
$plan = [
    1 => [$warranties, $ana, true, false, true],
    2 => [$warranties, $ana, true, true, false],
    3 => [$billing, $beto, true, false, true],
    4 => [$billing, $beto, false, false, false],
    5 => [null, null, false, false, false],
    6 => [$warranties, null, false, false, false],
];

$created = [];

foreach ($plan as $number => [$category, $agent, $replies, $late, $closes]) {
    $conversation = $inbound($number);

    if ($category !== null) {
        app(SetConversationCategory::class)->handle($conversation, $category->id, $admin);
    }

    if ($agent !== null) {
        app(AcceptConversation::class)->handle($conversation->refresh(), $agent);
    }

    if ($late) {
        // SIMULACIÓN of elapsed time: the customer has been waiting 12 minutes.
        $cycle = ResponseCycle::query()->where('conversation_id', $conversation->id)->where('active', 1)->firstOrFail();
        $cycle->forceFill([
            'started_at' => $cycle->started_at->subMinutes(12),
            'warn_at' => $cycle->warn_at?->subMinutes(12),
            'due_at' => $cycle->due_at?->subMinutes(12),
        ])->saveQuietly();
        Artisan::call('sla:evaluate');
    }

    if ($replies && $agent !== null) {
        $reply($conversation, $agent);
    }

    if ($closes && $agent !== null) {
        app(CloseConversation::class)->handle($conversation->refresh(), $agent);
    }

    $created[$number] = $conversation->id;
}

app(RebuildConversationMetrics::class)->handle();
Cache::flush();

echo json_encode([
    'conversations' => $created,
    'categories' => ['garantias' => $warranties->id, 'facturacion' => $billing->id],
    'ana' => $ana->id,
    'beto' => $beto->id,
]), PHP_EOL;
