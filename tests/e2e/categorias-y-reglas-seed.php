<?php

/*
| Reads for the categories and service rules scenario
| (tests/e2e/categorias-y-reglas.mjs), on top of
| tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php: the
| superadministrator, E2E Ana in E2E Ventas).
|
| The scenario writes everything through the interface; this script only
| tells it what was stored, so every step is checked against the database
| and not only against what the page says.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one.
|
|   php tests/e2e/categorias-y-reglas-seed.php state
|       -> {"sales", "department_rules", "platform", "categories": [{id, name,
|           description, is_active, service_rules}], "audit": [actions]}
*/

use App\Models\AuditLog;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Services\PlatformSettings;
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

if (($argv[1] ?? 'state') !== 'state') {
    fwrite(STDERR, 'Unknown command.'.PHP_EOL);
    exit(1);
}

$sales = Department::query()->where('slug', 'e2e-ventas')->firstOrFail();
$settings = app(PlatformSettings::class);

echo json_encode([
    'sales' => $sales->id,
    'department_rules' => $sales->service_rules,
    'platform' => [
        'sla' => $settings->get('sla'),
        'intake' => $settings->get('intake'),
        'queue' => $settings->get('queue'),
        'sla_alerts' => $settings->get('sla_alerts'),
    ],
    'categories' => ConversationCategory::query()->orderBy('id')->get()->map(fn (ConversationCategory $category): array => [
        'id' => $category->id,
        'name' => $category->name,
        'description' => $category->description,
        'is_active' => (bool) $category->is_active,
        'service_rules' => $category->service_rules,
    ])->all(),
    'audit' => AuditLog::query()->orderBy('id')->get(['id', 'action'])->map(fn (AuditLog $log): string => $log->action instanceof BackedEnum ? $log->action->value : (string) $log->action)->all(),
]), PHP_EOL;
