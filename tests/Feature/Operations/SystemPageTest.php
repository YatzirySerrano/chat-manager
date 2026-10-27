<?php

use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Connection;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get(route('admin.system.index'))->assertRedirect(route('login'));
    $this->getJson(route('admin.system.health'))->assertUnauthorized();
});

test('roles without system.view cannot open the system page', function (DefaultRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get(route('admin.system.index'))->assertForbidden();
    $this->actingAs($user)->getJson(route('admin.system.health'))->assertForbidden();
})->with([
    'supervisor' => DefaultRole::Supervisor,
    'agent' => DefaultRole::Agent,
]);

test('system.view is ignored outside the organization-wide scope', function () {
    $user = conversationUser([Permission::ViewSystem], DataScope::Departments);

    $this->actingAs($user)->get(route('admin.system.index'))->assertForbidden();
});

test('administrators see health, queues, failed jobs and connections', function (DefaultRole $role) {
    Connection::factory()->create(['name' => 'Ventas WhatsApp']);
    Connection::factory()->webChat()->create(['name' => 'Tienda web']);

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('admin.system.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/system/Index')
            ->has('checks', 9)
            ->where('checks.0.key', 'database')
            ->where('checks.0.status', 'ok')
            ->has('queues', count(Queues::all()))
            ->where('failedJobs.meta.total', 0)
            ->has('connections', 2)
            ->where('connections.0.name', 'Tienda web')
            ->where('connections.1.name', 'Ventas WhatsApp')
            ->where('canManage', true)
        );
})->with([
    'superadministrator' => DefaultRole::SuperAdministrator,
    'administrator' => DefaultRole::Administrator,
]);

test('failed jobs are shown without payloads, secrets or personal data', function () {
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'webhooks',
        'payload' => json_encode([
            'displayName' => ProcessWhatsAppWebhook::class,
            'data' => ['command' => serialize(new ProcessWhatsAppWebhook(9)), 'secret' => 'payload-secret-value'],
        ]),
        'exception' => "Illuminate\\Http\\Client\\RequestException: HTTP 401 access_token=EAAGm0PX4ZCpsBAAbcdefghijklmnopqrstuvwxyz Bearer abc.def.ghi para 5215512345678 cliente@example.com in /var/www/vendor/x.php:12\nStack trace:\n#0 /var/www/app/Secret.php(12)",
        'failed_at' => now(),
    ]);

    $response = $this->actingAs(conversationUser([Permission::ViewSystem], DataScope::Global))
        ->get(route('admin.system.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('failedJobs.data.0.uuid', $uuid)
            ->where('failedJobs.data.0.job', 'ProcessWhatsAppWebhook')
            ->where('failedJobs.data.0.retryable', true)
            ->where('failedJobs.data.0.exception', 'RequestException: HTTP 401 access_token=[oculto] Bearer [oculto] para [número] [correo]')
            ->missing('failedJobs.data.0.payload')
            ->where('canManage', false)
            ->where('connections', null)
        );

    $content = $response->getContent();

    expect($content)->not->toContain('payload-secret-value')
        ->and($content)->not->toContain('EAAGm0PX4')
        ->and($content)->not->toContain('5215512345678')
        ->and($content)->not->toContain('Stack trace')
        ->and($content)->not->toContain('/var/www');
});

test('failed jobs can be filtered by queue', function () {
    foreach (['webhooks', 'default'] as $queue) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode(['displayName' => ProcessWhatsAppWebhook::class]),
            'exception' => 'RuntimeException: fallo',
            'failed_at' => now(),
        ]);
    }

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('admin.system.index', ['queue' => 'webhooks']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('failedJobs.data', 1)
            ->where('failedJobs.data.0.queue', 'webhooks')
            ->where('failedQueues', ['default', 'webhooks'])
        );

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('admin.system.index', ['queue' => 'inexistente']))
        ->assertSessionHasErrors('queue');
});

test('the detailed health JSON is available to administrators', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->getJson(route('admin.system.health'))
        ->assertOk()
        ->assertJsonStructure(['status', 'checked_at', 'checks' => [['key', 'label', 'status', 'summary', 'details']]])
        ->assertJsonPath('checks.0.key', 'database');
});

test('the system page writes nothing to the database', function () {
    $admin = User::factory()->superAdministrator()->create();

    $writes = databaseWritesDuring(fn () => $this->actingAs($admin)->get(route('admin.system.index'))->assertOk());

    expect(array_filter($writes, fn (string $sql): bool => ! str_contains($sql, '`sessions`') && ! str_contains($sql, '`cache`')))->toBe([]);
});
