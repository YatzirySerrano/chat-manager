<?php

/*
| Synthetic dataset of the load battery (tests/load/run.php).
|
|   APP_ENV=testing LOAD_PASSWORD=... php tests/load/seed.php <manifest.json>
|
| Runs against chatmanager_testing only (already rebuilt by run.php) and
| refuses any other database. Everything is synthetic: names, phones
| (+52 155 0... numbers) and texts are generated here, and the WhatsApp
| connections are local fixtures whose webhooks are signed by the load
| scripts with throwaway secrets (SIMULACIÓN, nothing reaches Meta).
|
| Users, departments and connections go through the application models
| (encryption, roles, generated webhook keys); the history (contacts,
| conversations, messages, events) is bulk inserted in chunks because
| creating tens of thousands of rows through the engine would take far too
| long. The metrics of the reports are computed afterwards by run.php with
| the real `analytics:rebuild` command.
|
| Options (JSON in LOAD_SEED_OPTIONS): conversations (10000), agents (100).
*/

use App\Enums\ColorSwatch;
use App\Enums\DefaultRole;
use App\Enums\DistributionMode;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
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

$manifestPath = $argv[1] ?? null;
$password = (string) getenv('LOAD_PASSWORD');

if ($manifestPath === null || $password === '') {
    fwrite(STDERR, 'Usage: LOAD_PASSWORD=... php tests/load/seed.php <manifest.json>'.PHP_EOL);
    exit(1);
}

/** @var array{conversations?: int, agents?: int} $options */
$options = json_decode((string) (getenv('LOAD_SEED_OPTIONS') ?: '{}'), true) ?: [];
$conversationTotal = max(100, (int) ($options['conversations'] ?? 10000));
$agentTotal = max(4, (int) ($options['agents'] ?? 100));

mt_srand(20261021);
$started = microtime(true);
$now = Carbon::now()->startOfSecond();

$makeDepartment = function (string $name, string $slug, ColorSwatch $color, DistributionMode $mode): Department {
    $department = Department::factory()->create(['name' => "Carga {$name}", 'slug' => "carga-{$slug}", 'color' => $color]);
    $department->forceFill(['distribution_mode' => $mode, 'is_active' => true])->save();

    return $department;
};

$departments = [
    'ventas' => $makeDepartment('Ventas', 'ventas', ColorSwatch::Blue, DistributionMode::Shared),
    'soporte' => $makeDepartment('Soporte', 'soporte', ColorSwatch::Emerald, DistributionMode::Automatic),
    'cobranza' => $makeDepartment('Cobranza', 'cobranza', ColorSwatch::Amber, DistributionMode::Shared),
    'postventa' => $makeDepartment('Postventa', 'postventa', ColorSwatch::Violet, DistributionMode::Shared),
];

$departmentIds = array_map(fn (Department $department): int => $department->id, $departments);

$makeUser = function (string $email, string $name, DefaultRole $role, array $inDepartments) use ($password): User {
    $user = User::factory()->withRole($role)->inDepartments($inDepartments)->create([
        'name' => $name,
        'email' => $email,
        'password' => $password,
    ]);
    $user->forceFill(['availability' => 'available', 'availability_changed_at' => now()])->save();

    return $user;
};

$superAdministrator = $makeUser('carga-superadmin@example.test', 'Carga Superadministración', DefaultRole::SuperAdministrator, []);
$administrator = $makeUser('carga-admin@example.test', 'Carga Administración', DefaultRole::Administrator, []);

$supervisors = [];

foreach ($departments as $slug => $department) {
    $supervisors[$slug] = $makeUser("carga-supervision-{$slug}@example.test", "Carga Supervisión {$department->name}", DefaultRole::Supervisor, [$department]);
}

/** @var array<string, list<int>> $agentsByDepartment */
$agentsByDepartment = array_fill_keys(array_keys($departments), []);
$agents = [];
$slugs = array_keys($departments);

for ($index = 1; $index <= $agentTotal; $index++) {
    $slug = $slugs[($index - 1) % count($slugs)];
    $member = [$departments[$slug]];

    // One agent in five also covers the next department.
    if ($index % 5 === 0) {
        $member[] = $departments[$slugs[$index % count($slugs)]];
    }

    $agent = $makeUser(sprintf('carga-agente-%03d@example.test', $index), sprintf('Carga Agente %03d', $index), DefaultRole::Agent, $member);
    $agents[$agent->id] = ['id' => $agent->id, 'email' => $agent->email, 'departments' => array_map(fn (Department $department): int => $department->id, $member)];

    foreach ($member as $department) {
        $agentsByDepartment[array_search($department->id, $departmentIds, true)][] = $agent->id;
    }
}

$secrets = ['ventas' => Str::random(40), 'soporte' => Str::random(40)];

$connections = [
    'ventas' => Connection::factory()->verified()->create([
        'name' => 'Carga WhatsApp Ventas (simulación)',
        'display_phone_number' => '+52 55 0000 0101',
        'phone_number_id' => '990000000000101',
        'business_account_id' => '990000000000901',
        'app_secret' => $secrets['ventas'],
        'is_active' => true,
    ]),
    'soporte' => Connection::factory()->verified()->create([
        'name' => 'Carga WhatsApp Soporte (simulación)',
        'display_phone_number' => '+52 55 0000 0102',
        'phone_number_id' => '990000000000102',
        'business_account_id' => '990000000000902',
        'app_secret' => $secrets['soporte'],
        'is_active' => true,
    ]),
];

$connections['ventas']->forceFill(['default_department_id' => $departments['ventas']->id, 'reopen_window_minutes' => null])->save();
$connections['soporte']->forceFill(['default_department_id' => $departments['soporte']->id, 'reopen_window_minutes' => null])->save();
$connections['ventas']->departments()->sync([$departmentIds['ventas'], $departmentIds['cobranza'], $departmentIds['postventa']]);
$connections['soporte']->departments()->sync([$departmentIds['soporte']]);

/*
| History. Ids are assigned here so related rows can be written in bulk.
*/

$texts = [
    'inbound' => [
        'Hola, quisiera saber el estado de mi pedido.',
        'Buenas tardes, ¿tienen disponible el modelo que vi en su página?',
        'Necesito ayuda con mi factura del mes pasado.',
        '¿Cuál es el horario de atención de la sucursal?',
        'Ya realicé el pago, les comparto la referencia.',
        'El producto llegó con un detalle, ¿cómo hago el cambio?',
        'Gracias por la información.',
        '¿Me pueden enviar la cotización actualizada?',
    ],
    'outbound' => [
        'Con gusto le ayudo, ¿me comparte su número de pedido?',
        'Su pedido va en camino y llegará en 2 días hábiles.',
        'Le envío la cotización solicitada.',
        'Ya registramos su pago, gracias.',
        'Nuestro horario es de lunes a viernes de 9 a 18 h.',
        'Quedo al pendiente de cualquier duda.',
    ],
];

$contactTotal = (int) round($conversationTotal * 0.75);
$open = (int) round($conversationTotal * 0.08);
$pending = (int) round($conversationTotal * 0.05);
$onHold = (int) round($conversationTotal * 0.02);

$contacts = [];
$identities = [];
$links = [];
$conversationRows = [];
$messageRows = [];
$eventRows = [];
$messageId = 0;
$eventId = 0;

$contactTimes = [];

$flush = function (string $table, array &$rows, bool $force = false): void {
    if ($rows === [] || (! $force && count($rows) < 1000)) {
        return;
    }

    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table($table)->insert($chunk);
    }

    $rows = [];
};

$waId = fn (int $contact): string => '52155'.str_pad((string) $contact, 8, '0', STR_PAD_LEFT);

/** @var array<int, list<int>> $openByAgent */
$openByAgent = [];
/** @var array<int, list<int>> $pendingByDepartment */
$pendingByDepartment = array_fill_keys(array_values($departmentIds), []);
$probeCandidates = [];
$statusTargets = [];
$existingSenders = [];

// Children are flushed while the loop runs, before their parents exist.
DB::statement('SET FOREIGN_KEY_CHECKS=0');

for ($conversation = 1; $conversation <= $conversationTotal; $conversation++) {
    $firstPass = $conversation <= $contactTotal;
    $contact = $firstPass ? $conversation : (($conversation - $contactTotal - 1) % $contactTotal) + 1;

    $status = 'closed';

    if ($firstPass && $conversation <= $open + $pending + $onHold) {
        $status = match (true) {
            $conversation <= $open => 'open',
            $conversation <= $open + $pending => 'pending',
            default => 'on_hold',
        };
    }

    $connectionKey = mt_rand(1, 100) <= 70 ? 'ventas' : 'soporte';

    // The automatic department never leaves conversations waiting in a queue.
    if ($status === 'pending' && $connectionKey === 'soporte') {
        $connectionKey = 'ventas';
    }

    $departmentSlug = $connectionKey === 'soporte' ? 'soporte' : ['ventas', 'ventas', 'cobranza', 'postventa'][mt_rand(0, 3)];
    $departmentId = $departmentIds[$departmentSlug];
    $pool = $agentsByDepartment[$departmentSlug];
    $agentId = $status === 'pending' ? null : $pool[mt_rand(0, count($pool) - 1)];
    $connectionId = $connections[$connectionKey]->id;

    $createdAt = $status === 'closed'
        ? $now->copy()->subSeconds(mt_rand(3600, 90 * 86400))
        : $now->copy()->subSeconds(mt_rand(600, 20 * 3600));

    $count = $status === 'pending' ? mt_rand(1, 3) : mt_rand(3, 12);
    $at = $createdAt->copy();
    $lastInbound = null;
    $lastOutbound = null;
    $firstResponse = null;
    $outboundIds = [];

    for ($position = 0; $position < $count; $position++) {
        $messageId++;
        $inbound = $status === 'pending' || $position === 0 || mt_rand(0, 1) === 1;

        if ($status !== 'closed' && $position === $count - 1) {
            $inbound = true;
        }

        if ($status !== 'closed' && $at->greaterThan($now->copy()->subMinutes(2))) {
            $at = $now->copy()->subMinutes(2);
        }

        $messageRows[] = [
            'id' => $messageId,
            'conversation_id' => $conversation,
            'whatsapp_message_id' => ($inbound ? 'wamid.seed.in.' : 'wamid.seed.out.').$messageId,
            'direction' => $inbound ? 'inbound' : 'outbound',
            'type' => 'text',
            'status' => $inbound ? 'received' : ($status === 'closed' ? 'read' : 'accepted'),
            'body' => $texts[$inbound ? 'inbound' : 'outbound'][mt_rand(0, $inbound ? 7 : 5)],
            'sender_user_id' => $inbound ? null : $agentId,
            'accepted_at' => $inbound ? null : $at,
            'sent_at' => $inbound ? null : ($status === 'closed' ? $at : null),
            'delivered_at' => $inbound || $status !== 'closed' ? null : $at,
            'read_at' => $inbound || $status !== 'closed' ? null : $at,
            'created_at' => $at,
            'updated_at' => $at,
        ];

        if ($inbound) {
            $lastInbound = $at->copy();
        } else {
            $lastOutbound = $at->copy();
            $firstResponse ??= $at->copy();
            $outboundIds[] = $messageId;
        }

        $at = $at->copy()->addSeconds(mt_rand(30, 1200));
    }

    $lastMessage = max($lastInbound->getTimestamp(), $lastOutbound === null ? 0 : $lastOutbound->getTimestamp());
    $closedAt = $status === 'closed' ? Carbon::createFromTimestamp($lastMessage)->addMinutes(mt_rand(1, 60)) : null;
    $assignedAt = $agentId === null ? null : $createdAt->copy()->addSeconds(mt_rand(5, 600));

    $conversationRows[] = [
        'id' => $conversation,
        'connection_id' => $connectionId,
        'contact_id' => $contact,
        'department_id' => $departmentId,
        'assigned_user_id' => $agentId,
        'status' => $status,
        'last_message_at' => Carbon::createFromTimestamp($lastMessage),
        'last_inbound_at' => $lastInbound,
        'last_outbound_at' => $lastOutbound,
        'first_response_at' => $firstResponse,
        'assigned_at' => $assignedAt,
        'on_hold_at' => $status === 'on_hold' ? $lastInbound : null,
        'on_hold_reason' => $status === 'on_hold' ? 'Esperando documentos del cliente (dato sintético)' : null,
        'closed_at' => $closedAt,
        'closed_by_user_id' => $status === 'closed' ? $agentId : null,
        'version' => $status === 'pending' ? 1 : 3,
        'created_at' => $createdAt,
        'updated_at' => $closedAt ?? Carbon::createFromTimestamp($lastMessage),
    ];

    $eventRows[] = ['id' => ++$eventId, 'conversation_id' => $conversation, 'type' => 'created', 'to_department_id' => $departmentId, 'actor_user_id' => null, 'to_user_id' => null, 'created_at' => $createdAt];

    if ($agentId !== null) {
        $eventRows[] = ['id' => ++$eventId, 'conversation_id' => $conversation, 'type' => $departmentSlug === 'soporte' ? 'auto_assigned' : 'accepted', 'to_department_id' => $departmentId, 'actor_user_id' => $departmentSlug === 'soporte' ? null : $agentId, 'to_user_id' => $agentId, 'created_at' => $assignedAt];
    }

    if ($closedAt !== null) {
        $eventRows[] = ['id' => ++$eventId, 'conversation_id' => $conversation, 'type' => 'closed', 'to_department_id' => null, 'actor_user_id' => $agentId, 'to_user_id' => null, 'created_at' => $closedAt];
    }

    $first = $contactTimes[$contact][0] ?? $createdAt;
    $last = $contactTimes[$contact][1] ?? $createdAt;
    $contactTimes[$contact] = [$createdAt->lessThan($first) ? $createdAt : $first, Carbon::createFromTimestamp(max($last->getTimestamp(), $lastMessage))];
    $links[$connectionId.':'.$contact] = [$connectionId, $contact];

    if ($status === 'open') {
        $openByAgent[$agentId][] = $conversation;

        if (count($probeCandidates) < 400) {
            $probeCandidates[] = ['conversation' => $conversation, 'connection' => $connectionKey, 'wa_id' => $waId($contact), 'agent' => $agentId, 'department' => $departmentId];
        }

        foreach ($outboundIds as $outbound) {
            if (count($statusTargets) < 3000) {
                $statusTargets[] = ['connection' => $connectionKey, 'wamid' => 'wamid.seed.out.'.$outbound, 'message' => $outbound];
            }
        }
    } elseif ($status === 'pending') {
        $pendingByDepartment[$departmentId][] = $conversation;
    } elseif ($status === 'closed' && $firstPass && count($existingSenders) < 2000) {
        $existingSenders[] = ['connection' => $connectionKey, 'wa_id' => $waId($contact)];
    }

    $flush('messages', $messageRows);
    $flush('conversation_events', $eventRows);
    $flush('conversations', $conversationRows);
}

for ($contact = 1; $contact <= $contactTotal; $contact++) {
    [$first, $last] = $contactTimes[$contact];
    $contacts[] = [
        'id' => $contact,
        'name' => sprintf('Cliente sintético %05d', $contact),
        'phone_e164' => '+'.$waId($contact),
        'profile_name' => sprintf('Cliente %05d', $contact),
        'status' => 'active',
        'source' => 'whatsapp',
        'first_interaction_at' => $first,
        'last_interaction_at' => $last,
        'created_at' => $first,
        'updated_at' => $last,
    ];
    $identities[] = [
        'contact_id' => $contact,
        'channel' => 'whatsapp',
        'provider' => 'meta_cloud_api',
        'external_id' => $waId($contact),
        'display_name' => sprintf('Cliente %05d', $contact),
        'last_seen_at' => $last,
        'created_at' => $first,
        'updated_at' => $last,
    ];
}

$flush('messages', $messageRows, true);
$flush('conversation_events', $eventRows, true);
$flush('contacts', $contacts, true);
$flush('contact_identities', $identities, true);
$linkRows = array_map(fn (array $link): array => ['connection_id' => $link[0], 'contact_id' => $link[1], 'first_interaction_at' => $contactTimes[$link[1]][0], 'last_interaction_at' => $contactTimes[$link[1]][1], 'created_at' => $contactTimes[$link[1]][0], 'updated_at' => $contactTimes[$link[1]][1]], array_values($links));
$flush('connection_contact', $linkRows, true);
$flush('conversations', $conversationRows, true);
DB::statement('SET FOREIGN_KEY_CHECKS=1');

// Every foreign key of the bulk rows must point somewhere (checks were off while inserting).
$orphans = DB::table('messages')->leftJoin('conversations', 'conversations.id', '=', 'messages.conversation_id')->whereNull('conversations.id')->count()
    + DB::table('conversations')->leftJoin('contacts', 'contacts.id', '=', 'conversations.contact_id')->whereNull('contacts.id')->count()
    + DB::table('conversation_events')->leftJoin('conversations', 'conversations.id', '=', 'conversation_events.conversation_id')->whereNull('conversations.id')->count();

if ($orphans !== 0) {
    fwrite(STDERR, "The synthetic dataset has {$orphans} orphan rows.".PHP_EOL);
    exit(1);
}

$userRow = fn (User $user): array => ['id' => $user->id, 'email' => $user->email];

$manifest = [
    'database' => DB::connection()->getDatabaseName(),
    'password' => $password,
    'generated_at' => $now->toIso8601String(),
    'seconds' => round(microtime(true) - $started, 2),
    'counts' => [
        'contacts' => DB::table('contacts')->count(),
        'conversations' => DB::table('conversations')->count(),
        'conversations_by_status' => DB::table('conversations')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all(),
        'messages' => DB::table('messages')->count(),
        'conversation_events' => DB::table('conversation_events')->count(),
        'users' => DB::table('users')->count(),
        'agents' => count($agents),
        'departments' => count($departments),
        'connections' => count($connections),
    ],
    'departments' => array_map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name, 'mode' => $department->distribution_mode->value], $departments),
    'connections' => array_map(fn (Connection $connection): array => [
        'id' => $connection->id,
        'webhook_key' => $connection->webhook_key,
        'phone_number_id' => $connection->phone_number_id,
        'business_account_id' => $connection->business_account_id,
    ], $connections),
    'webhook_secrets' => $secrets,
    'users' => [
        'superadmin' => $userRow($superAdministrator),
        'admin' => $userRow($administrator),
        'supervisors' => array_map($userRow, $supervisors),
        'agents' => array_values(array_map(fn (array $agent): array => [...$agent, 'open' => $openByAgent[$agent['id']] ?? []], $agents)),
    ],
    'pending_by_department' => $pendingByDepartment,
    'probes' => $probeCandidates,
    'status_targets' => $statusTargets,
    'existing_senders' => $existingSenders,
    'max_ids' => [
        'messages' => (int) DB::table('messages')->max('id'),
        'conversations' => (int) DB::table('conversations')->max('id'),
        'contacts' => (int) DB::table('contacts')->max('id'),
    ],
];

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
chmod($manifestPath, 0600);

echo json_encode(['seconds' => $manifest['seconds'], 'counts' => $manifest['counts']], JSON_UNESCAPED_UNICODE).PHP_EOL;
