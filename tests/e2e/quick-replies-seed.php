<?php

/*
| Data of the quick replies scenario (tests/e2e/quick-replies.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php): one
| corporate reply, two personal replies of the superadministrator (E2E
| Administración) and fourteen personal replies of E2E Ana, so the three
| tabs have different lengths.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one.
|
|   php tests/e2e/quick-replies-seed.php
*/

use App\Models\QuickReply;
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

$admin = User::query()->where('email', 'e2e-admin@example.test')->sole();
$ana = User::query()->where('email', 'e2e-ana@example.test')->sole();

QuickReply::factory()->create(['shortcut' => 'e2e-corporativa', 'title' => 'Bienvenida corporativa']);

foreach (['uno', 'dos'] as $name) {
    QuickReply::factory()->personal($admin)->create(['shortcut' => "e2e-mia-{$name}", 'title' => "Mía {$name}"]);
}

for ($index = 1; $index <= 14; $index++) {
    QuickReply::factory()->personal($ana)->create(['shortcut' => "e2e-ana-{$index}", 'title' => "De Ana {$index}"]);
}

echo json_encode(['corporate' => 1, 'mine' => 2, 'others' => 14]).PHP_EOL;
