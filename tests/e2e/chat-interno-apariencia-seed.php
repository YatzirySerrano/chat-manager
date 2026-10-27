<?php

/*
| Data of the internal chat appearance scenario
| (tests/e2e/chat-interno-apariencia.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php: Ana and
| Beto in E2E Ventas, Carla in E2E Soporte). Adds a group of Ana (admin)
| and Beto with messages of both and a
| direct chat of Ana and Carla.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one.
|
|   php tests/e2e/chat-interno-apariencia-seed.php
*/

use App\Enums\TeamChat\TeamParticipantRole;
use App\Enums\TeamChat\TeamThreadType;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
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

$ana = User::query()->where('email', 'e2e-ana@example.test')->firstOrFail();
$beto = User::query()->where('email', 'e2e-beto@example.test')->firstOrFail();
$carla = User::query()->where('email', 'e2e-carla@example.test')->firstOrFail();

$group = TeamThread::query()->create(['type' => TeamThreadType::Group, 'name' => 'E2E Apariencia']);
$group->participations()->create(['user_id' => $ana->id, 'role' => TeamParticipantRole::Admin, 'joined_at' => now()->subDay()]);
$group->participations()->create(['user_id' => $beto->id, 'role' => TeamParticipantRole::Member, 'joined_at' => now()->subDay()]);

$direct = TeamThread::query()->create(['type' => TeamThreadType::Direct, 'name' => null]);
$direct->forceFill(['direct_key' => TeamThread::directKeyFor($ana->id, $carla->id)])->save();
$direct->participations()->create(['user_id' => $ana->id, 'role' => TeamParticipantRole::Member, 'joined_at' => now()->subDay()]);
$direct->participations()->create(['user_id' => $carla->id, 'role' => TeamParticipantRole::Member, 'joined_at' => now()->subDay()]);

$lines = [
    [$beto, 'Hola Ana, ¿revisamos el pendiente de hoy?'],
    [$ana, 'Claro, Beto. Ya tengo el resumen listo.'],
    [$beto, 'Perfecto, te paso los datos del cliente en un momento.'],
    [$ana, 'Gracias. Cuando lo tengas, lo cierro.'],
];

$last = null;

foreach ($lines as $index => [$author, $body]) {
    $last = TeamMessage::factory()->in($group, $author)->create([
        'body' => $body,
        'created_at' => now()->subMinutes(20 - $index),
    ]);
}

$directMessage = TeamMessage::factory()->in($direct, $carla)->create(['body' => 'Ana, ¿me ayudas con un reporte?']);

$group->forceFill(['last_message_id' => $last->id, 'last_message_at' => $last->created_at])->save();
$direct->forceFill(['last_message_id' => $directMessage->id, 'last_message_at' => $directMessage->created_at])->save();

echo json_encode(['group' => $group->id, 'direct' => $direct->id]).PHP_EOL;
