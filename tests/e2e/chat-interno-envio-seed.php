<?php

/*
| Data of the internal chat sending scenario (tests/e2e/chat-interno-envio.mjs),
| on top of tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php:
| Ana and Beto in E2E Ventas). Adds the group «E2E Envío» of Ana (admin) and
| Beto with 40 messages; the newest one quotes the first, which is outside
| the first page of the history (30).
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one.
|
|   php tests/e2e/chat-interno-envio-seed.php
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

$group = TeamThread::query()->create(['type' => TeamThreadType::Group, 'name' => 'E2E Envío']);
$group->participations()->create(['user_id' => $ana->id, 'role' => TeamParticipantRole::Admin, 'joined_at' => now()->subDay()]);
$group->participations()->create(['user_id' => $beto->id, 'role' => TeamParticipantRole::Member, 'joined_at' => now()->subDay()]);

$first = null;
$last = null;

foreach (range(1, 40) as $index) {
    $author = $index % 2 === 0 ? $ana : $beto;
    $last = TeamMessage::factory()->in($group, $author)->create([
        'body' => $index === 1 ? 'Primer mensaje citado más tarde' : "Mensaje de relleno {$index}",
        'reply_to_message_id' => $index === 40 ? $first?->id : null,
        'created_at' => now()->subMinutes(60 - $index),
    ]);
    $first ??= $last;
}

$group->forceFill(['last_message_id' => $last->id, 'last_message_at' => $last->created_at])->save();
$group->participations()->update(['last_read_message_id' => $last->id]);

echo json_encode(['group' => $group->id, 'quoted' => $first->id]).PHP_EOL;
