<?php

/*
| Data of the toast scenario (tests/e2e/toast-oscuro.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php): three
| contacts, so the contacts export has rows and, with
| EXPORTS_XLSX_SYNC_ROWS=0, its Excel is prepared in the background.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one.
|
|   php tests/e2e/toast-oscuro-seed.php
*/

use App\Models\Contact;
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

Contact::factory()->count(3)->sequence(
    ['name' => 'E2E Contacto uno'],
    ['name' => 'E2E Contacto dos'],
    ['name' => 'E2E Contacto tres'],
)->create();

echo json_encode(['contacts' => Contact::query()->count()]).PHP_EOL;
