<?php

/*
| Data of the internal chat alerts scenario (tests/e2e/chat-interno-avisos.mjs),
| on top of tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php):
| a direct chat between E2E Ana and E2E Beto and a group with both and the
| administrator, created through the application's own actions.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one.
|
|   php tests/e2e/chat-interno-avisos-seed.php
*/

use App\Actions\TeamChat\CreateGroupThread;
use App\Actions\TeamChat\StartDirectThread;
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

$user = fn (string $email): User => User::query()->where('email', $email)->firstOrFail();
$admin = $user('e2e-admin@example.test');
$ana = $user('e2e-ana@example.test');
$beto = $user('e2e-beto@example.test');

$direct = app(StartDirectThread::class)->handle($ana, $beto);
$group = app(CreateGroupThread::class)->handle($admin, [
    'name' => 'E2E Avisos',
    'description' => null,
    'participant_ids' => [$ana->id, $beto->id],
]);

echo json_encode(['direct' => $direct->id, 'group' => $group->id]).PHP_EOL;
