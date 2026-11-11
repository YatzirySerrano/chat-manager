<?php

/*
| Data of the queue rules scenario (tests/e2e/reglas-cola.mjs) and of the
| SLA scenario (tests/e2e/sla.mjs), on top of tests/e2e/increment-b-seed.php
| (already run by tests/e2e/run.php: the superadministrator, E2E Ana and E2E
| Beto, agents of E2E Ventas, and the "E2E WhatsApp Ventas" connection).
|
| The rules themselves are switched on by the scenarios in the interface
| (Configuración); customers write through the simulated webhook of
| increment-b-seed.php. This script only prepares what has no screen in the
| scenario and moves the clocks:
|
|   php tests/e2e/reglas-cola-seed.php prepare
|       -> {"sofia", "ana", "sales", "categories": {"garantias", "facturacion"}}
|       E2E Sofía (Supervisor of E2E Ventas, created with the role's default
|       permissions), two request categories created with the application's
|       own action, the control of pending conversations off and continuous
|       attention (no business hours), the connection active without
|       automatic messages.
|   php tests/e2e/reglas-cola-seed.php burst <wa_id> <name> <count>
|       -> {"conversation", "messages"}: <count> customer messages in one
|       go (the preview shows the initial message apart after 20).
|   php tests/e2e/reglas-cola-seed.php queue-age <conversation> <seconds>
|       SIMULACIÓN of elapsed time: the request entered its queue <seconds>
|       ago (conversations.queued_at), so a minimum wait of one minute can
|       be watched reaching zero in a few seconds.
|   php tests/e2e/reglas-cola-seed.php sla-age <conversation> warn|due <seconds>
|       SIMULACIÓN of elapsed time: the open response cycle is moved back as
|       a whole (started_at, warn_at and due_at together, as if the customer
|       had written earlier) so that its warning instant (warn) or its
|       deadline (due) is <seconds> from now; a negative value leaves it
|       that many seconds in the past. The objective itself never changes.
|   php tests/e2e/reglas-cola-seed.php sla-evaluate
|       runs `php artisan sla:evaluate` (the scheduler does it every minute)
|   php tests/e2e/reglas-cola-seed.php state <conversation>
|       -> what is stored about the conversation, its open cycle, its last
|       events and the audit entries about it
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta.
*/

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\ColorSwatch;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\Meta\SimulatedWebhook;
use App\Services\PlatformSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
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

const QUEUE_PHONE_NUMBER_ID = '880000000000002';

$command = $argv[1] ?? 'prepare';
$iso = fn (mixed $moment): ?string => $moment?->toIso8601String();

if ($command === 'prepare') {
    app(PlatformSettings::class)->update([
        'operations' => ['pending_block_enabled' => false],
        'business_hours' => ['enabled' => false],
    ]);

    $sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
    $ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
    $sofia = User::query()->where('email', 'e2e-sofia@example.test')->first()
        ?? User::factory()->supervisor()->inDepartments([$sales])->create([
            'name' => 'E2E Sofía',
            'email' => 'e2e-sofia@example.test',
            'password' => 'e2e-password',
        ]);

    $whatsapp = Connection::query()->where('phone_number_id', QUEUE_PHONE_NUMBER_ID)->firstOrFail();
    $whatsapp->forceFill([
        'is_active' => true,
        // Throwaway values: without them the connection cannot send (simulated transport).
        'business_account_id' => $whatsapp->business_account_id ?? '990000000000002',
        'access_token' => $whatsapp->access_token ?? 'E2E-SIMULATED-'.Str::random(32),
        'app_secret' => $whatsapp->app_secret ?? Str::random(40),
        'welcome_enabled' => false,
        'default_department_id' => $sales->id,
    ])->save();

    $category = fn (string $name, ColorSwatch $color): ConversationCategory => ConversationCategory::query()->where('name', $name)->first()
        ?? ConversationCategory::factory()->create(['name' => $name, 'description' => null, 'color' => $color]);

    echo json_encode([
        'sofia' => $sofia->id,
        'ana' => $ana->id,
        'sales' => $sales->id,
        'categories' => [
            'garantias' => $category('E2E Garantías', ColorSwatch::Blue)->id,
            'facturacion' => $category('E2E Facturación', ColorSwatch::Emerald)->id,
        ],
    ]), PHP_EOL;
    exit(0);
}

if ($command === 'burst') {
    [, , $waId, $name, $count] = $argv + [null, null, '5215511180009', 'Cliente Ráfaga', '22'];
    $whatsapp = Connection::query()->where('phone_number_id', QUEUE_PHONE_NUMBER_ID)->firstOrFail();
    $conversation = null;

    for ($index = 1; $index <= (int) $count; $index++) {
        $receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
            QUEUE_PHONE_NUMBER_ID,
            (string) $whatsapp->business_account_id,
            (string) $waId,
            (string) $name,
            'wamid.e2e.'.Str::random(16),
            'text',
            $index === 1 ? 'Mensaje inicial: necesito ayuda con mi pedido 4521' : "Detalle {$index} del pedido",
            now()->subSeconds((int) $count - $index)->getTimestamp(),
        ));
        $conversation = $receipts[0]->conversation;
    }

    echo json_encode(['conversation' => $conversation?->id, 'messages' => (int) $count]), PHP_EOL;
    exit(0);
}

if ($command === 'queue-age') {
    $conversation = Conversation::query()->findOrFail((int) ($argv[2] ?? 0));
    $conversation->forceFill(['queued_at' => now()->subSeconds((int) ($argv[3] ?? 0))])->saveQuietly();

    echo json_encode(['conversation' => $conversation->id, 'queued_at' => $iso($conversation->queued_at)]), PHP_EOL;
    exit(0);
}

if ($command === 'sla-age') {
    $cycle = ResponseCycle::query()->where('conversation_id', (int) ($argv[2] ?? 0))->where('active', 1)->firstOrFail();
    $anchor = ($argv[3] ?? 'due') === 'warn' ? $cycle->warn_at : $cycle->due_at;

    if ($anchor === null) {
        fwrite(STDERR, 'The open cycle has no objective.'.PHP_EOL);
        exit(1);
    }

    // Seconds to move the whole cycle back so the anchor lands at now + <seconds>.
    $shift = (int) round(now()->diffInSeconds($anchor, false)) - (int) ($argv[4] ?? 0);
    $cycle->forceFill([
        'started_at' => $cycle->started_at->subSeconds($shift),
        'warn_at' => $cycle->warn_at?->subSeconds($shift),
        'due_at' => $cycle->due_at?->subSeconds($shift),
    ])->saveQuietly();

    echo json_encode([
        'cycle' => $cycle->id,
        'shifted_seconds' => $shift,
        'started_at' => $iso($cycle->started_at),
        'warn_at' => $iso($cycle->warn_at),
        'due_at' => $iso($cycle->due_at),
        'now' => now()->toIso8601String(),
    ]), PHP_EOL;
    exit(0);
}

if ($command === 'sla-evaluate') {
    $status = Artisan::call('sla:evaluate');

    echo json_encode(['status' => $status, 'output' => trim(Artisan::output())]), PHP_EOL;
    exit($status === 0 ? 0 : 1);
}

if ($command === 'state') {
    $conversation = Conversation::query()->findOrFail((int) ($argv[2] ?? 0));
    $cycle = ResponseCycle::query()->where('conversation_id', $conversation->id)->orderByDesc('id')->first();
    $value = fn (mixed $enum): mixed => $enum instanceof BackedEnum ? $enum->value : $enum;

    echo json_encode([
        'id' => $conversation->id,
        'status' => $value($conversation->status),
        'readiness' => $value($conversation->readiness),
        'assigned_user_id' => $conversation->assigned_user_id,
        'category_id' => $conversation->category_id,
        'queued_at' => $iso($conversation->queued_at),
        'cycle' => $cycle === null ? null : [
            'id' => $cycle->id,
            'active' => $cycle->active,
            'target_seconds' => $cycle->target_seconds,
            'warned_at' => $iso($cycle->warned_at),
            'breached_at' => $iso($cycle->breached_at),
            'outcome' => $cycle->outcome,
            'responded_at' => $iso($cycle->responded_at),
        ],
        'events' => ConversationEvent::query()->where('conversation_id', $conversation->id)->orderBy('id')->get()->map(fn (ConversationEvent $event): array => [
            'type' => $value($event->type),
            'metadata' => $event->metadata,
        ])->all(),
        'audit' => AuditLog::query()->where('auditable_type', $conversation->getMorphClass())->where('auditable_id', $conversation->id)->orderBy('id')->get()->map(fn (AuditLog $log): array => [
            'action' => $value($log->action),
            'properties' => $log->properties,
        ])->all(),
    ]), PHP_EOL;
    exit(0);
}

fwrite(STDERR, 'Unknown command.'.PHP_EOL);
exit(1);
