<?php

/*
| Conversation of the chat date separators scenario (tests/e2e/fechas-chat.mjs).
| It sits on top of tests/e2e/increment-b-seed.php (already run by
| tests/e2e/run.php): E2E Ana and the "E2E WhatsApp Ventas" connection.
|
| One conversation assigned to E2E Ana with messages on five calendar days of
| the platform time zone (6 and 4 days ago, the day before yesterday, yesterday
| and today), more than one page of history (ConversationTimeline::PAGE_SIZE),
| and the edge cases of a day change at local midnight:
|   - a customer message at 23:58 and another at 00:02,
|   - a reply stored at 23:57 whose sent_at is 00:01 of the next day (it is
|     ordered by when it was stored, so it belongs to the earlier day).
| Messages are written directly (no notifications, no jobs); "live" sends one
| more customer message through the real inbound pipeline.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta.
|
|   php tests/e2e/fechas-chat-seed.php prepare
|       -> {"conversation", "wa_id", "timezone", "days": {"YYYY-MM-DD": count}, "messages",
|           "edge": {"late", "early", "shifted"}}
|   php tests/e2e/fechas-chat-seed.php live <wa_id> <text>
|       -> {"conversation", "outcome"}
*/

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Meta\SimulatedWebhook;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
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

const FECHAS_PHONE_NUMBER_ID = '880000000000002';

$connection = Connection::query()->where('phone_number_id', FECHAS_PHONE_NUMBER_ID)->firstOrFail();
$command = $argv[1] ?? 'prepare';

if ($command === 'live') {
    [, , $waId, $text] = $argv + [null, null, '', 'Hola'];

    $receipts = app(ProcessInboundMessages::class)->handle(SimulatedWebhook::message(
        FECHAS_PHONE_NUMBER_ID,
        (string) ($connection->business_account_id ?? '990000000000002'),
        $waId,
        'Cliente Fechas E2E',
        'wamid.e2e.'.Str::random(16),
        'text',
        $text,
        now()->getTimestamp(),
    ));

    echo json_encode(['conversation' => $receipts[0]->conversation->id, 'outcome' => $receipts[0]->outcome->value]), PHP_EOL;

    exit(0);
}

if ($command !== 'prepare') {
    fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
    exit(1);
}

$connection->forceFill([
    'is_active' => true,
    'business_account_id' => $connection->business_account_id ?? '990000000000002',
    'access_token' => $connection->access_token ?? 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $connection->app_secret ?? Str::random(40),
])->save();

$timezone = (string) (app(PlatformSettings::class)->all()['general']['timezone'] ?? 'America/Mexico_City');
$now = CarbonImmutable::now($timezone);
$today = $now->startOfDay();

$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
$department = $ana->departments()->firstOrFail();
$waId = '5215511139950';
$contact = Contact::factory()->fromWhatsapp($waId)->create(['name' => 'Cliente Fechas Del Historial']);

$conversation = Conversation::factory()
    ->for($connection, 'channelConnection')
    ->for($contact)
    ->inDepartment($department)
    ->assignedTo($ana)
    ->create([
        'last_inbound_at' => now()->subMinute(),
        'last_message_at' => now()->subMinute(),
    ]);

$days = [];
$counter = 0;
$message = function (CarbonImmutable $at, string $direction, ?CarbonImmutable $sentAt = null) use ($conversation, $ana, &$days, &$counter): Message {
    $counter++;
    $inbound = $direction === 'in';
    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'whatsapp_message_id' => 'wamid.e2e.fechas.'.Str::random(16),
        'direction' => $inbound ? MessageDirection::Inbound : MessageDirection::Outbound,
        'type' => MessageType::Text,
        'status' => $inbound ? MessageStatus::Received : MessageStatus::Read,
        'sender_user_id' => $inbound ? null : $ana->id,
        'body' => ($inbound ? 'Cliente' : 'Respuesta').' '.$counter.' · '.$at->format('Y-m-d H:i'),
    ]);
    $utc = $at->utc();
    $message->forceFill([
        'created_at' => $utc,
        'updated_at' => $utc,
        'sent_at' => ($sentAt ?? $at)->utc(),
    ])->save();
    $day = $at->format('Y-m-d');
    $days[$day] = ($days[$day] ?? 0) + 1;

    return $message;
};

// Ordinary days: 6 and 4 days ago, 15 messages each, in the afternoon.
foreach ([6, 4] as $ago) {
    $base = $today->subDays($ago)->setTime(15, 0);

    for ($i = 0; $i < 15; $i++) {
        $message($base->addMinutes($i * 3), $i % 2 === 0 ? 'in' : 'out');
    }
}

// The day before yesterday, ending at local midnight.
$dayBefore = $today->subDays(2);

for ($i = 0; $i < 12; $i++) {
    $message($dayBefore->setTime(18, 0)->addMinutes($i * 5), $i % 2 === 0 ? 'in' : 'out');
}

$shifted = $message($dayBefore->setTime(23, 57), 'out', $dayBefore->addDay()->setTime(0, 1));
$late = $message($dayBefore->setTime(23, 58), 'in');

// Yesterday, starting right after midnight.
$yesterday = $today->subDay();
$early = $message($yesterday->setTime(0, 2), 'in');

for ($i = 0; $i < 12; $i++) {
    $message($yesterday->setTime(10, 0)->addMinutes($i * 4), $i % 2 === 0 ? 'out' : 'in');
}

// Today, spread between midnight and now (never in the future).
$elapsed = max(1, $now->diffInSeconds($today, true));

for ($i = 1; $i <= 10; $i++) {
    $message($today->addSeconds((int) floor($elapsed * $i / 12)), $i % 2 === 0 ? 'out' : 'in');
}

echo json_encode([
    'conversation' => $conversation->id,
    'wa_id' => $waId,
    'timezone' => $timezone,
    'days' => $days,
    'messages' => $counter,
    'edge' => ['shifted' => $shifted->body, 'late' => $late->body, 'early' => $early->body],
], JSON_UNESCAPED_UNICODE), PHP_EOL;
