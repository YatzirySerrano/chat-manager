<?php

/*
| Data for the inbox filter panel scenario (tests/e2e/filtros.mjs), on top
| of tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php:
| superadministrator, Ana and Beto in E2E Ventas, Carla in E2E Soporte and
| the E2E WhatsApp connection).
|
| It makes the "Filtros" panel as tall as a real organization's: many
| departments, agents, connections of several channels and tags, so every
| section (departments, agents, connections, tags, order, search) is there
| and the panel needs its own scroll on short screens. A few conversations
| carry the tags, so applying a tag changes the list.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one. SIMULACIÓN: nothing is sent to or received from Meta.
|
|   php tests/e2e/filtros-seed.php prepare
*/

use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
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

if (($argv[1] ?? 'prepare') !== 'prepare') {
    fwrite(STDERR, 'Unknown command.'.PHP_EOL);
    exit(1);
}

$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$connection = Connection::query()->where('name', 'E2E WhatsApp Ventas')->firstOrFail();

// Throwaway values only in chatmanager_testing, so Ana's composer is the
// real one (a connection without credentials cannot send).
$connection->forceFill([
    'is_active' => true,
    'business_account_id' => $connection->business_account_id ?? '990000000000002',
    'access_token' => $connection->access_token ?? 'E2E-SIMULATED-'.Str::random(32),
    'app_secret' => $connection->app_secret ?? Str::random(40),
])->save();

$departments = collect(['Cobranza', 'Garantías', 'Mayoreo', 'Postventa', 'Logística', 'Facturación', 'Recursos Humanos', 'Compras'])
    ->map(fn (string $name) => Department::factory()->create(['name' => "E2E {$name}"]));

$agents = collect(range(1, 10))->map(fn (int $number) => User::factory()->agent()->inDepartments($sales)->create([
    'name' => "E2E Agente Filtros {$number}",
    'email' => "e2e-filtros-{$number}@example.test",
    'password' => 'e2e-password',
]));

Connection::factory()->webChat()->create(['name' => 'E2E Chat web Filtros']);
Connection::factory()->messenger()->create(['name' => 'E2E Messenger Filtros']);

$tags = collect(range(1, 18))->map(fn (int $number) => Tag::factory()->create([
    'name' => sprintf('E2E Filtro %02d', $number),
    'slug' => sprintf('e2e-filtro-%02d', $number),
    'is_active' => true,
]));

$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();

$tagged = collect(['Cliente Filtro Uno', 'Cliente Filtro Dos', 'Cliente Filtro Tres'])
    ->map(function (string $name, int $index) use ($sales, $connection, $agents, $tags, $ana) {
        // The first one is Ana's (she may reply: the 200 % zoom check).
        $conversation = Conversation::factory()->inDepartment($sales)->assignedTo($index === 0 ? $ana : $agents[$index])->create([
            'connection_id' => $connection->id,
            'contact_id' => Contact::factory()->create(['name' => $name])->id,
            'last_message_at' => now()->subMinutes(10 + $index),
        ]);
        $conversation->tags()->attach($tags[0]->id, ['tagged_by_user_id' => $ana->id, 'created_at' => now()]);

        return $conversation;
    });

// Untagged: the tag filter has something to leave out.
Conversation::factory()->inDepartment($sales)->assignedTo($agents[5])->create([
    'connection_id' => $connection->id,
    'contact_id' => Contact::factory()->create(['name' => 'Cliente Filtro Sin Etiqueta'])->id,
]);

echo json_encode([
    'departments' => $departments->count() + 2,
    'agents' => $agents->count(),
    'tags' => ['first' => $tags[0]->name, 'count' => $tags->count()],
    'tagged' => $tagged->map(fn (Conversation $conversation) => $conversation->contact->name)->all(),
    'ana_conversation' => $tagged[0]->id,
]), PHP_EOL;
