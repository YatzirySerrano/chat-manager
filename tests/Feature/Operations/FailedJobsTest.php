<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Jobs\DeliverOutboundMessage;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores a failed job the way the database-uuids provider does.
 */
function storeFailedJob(object $job, string $queue, string $exception = 'RuntimeException: Algo falló in /var/www/app/Jobs/Example.php:10'): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => $queue,
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => $job::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'attempts' => 5,
            'data' => ['commandName' => $job::class, 'command' => serialize($job)],
        ]),
        'exception' => $exception."\nStack trace:\n#0 {main}",
        'failed_at' => now(),
    ]);

    return $uuid;
}

function systemManager(): User
{
    return conversationUser([Permission::ViewSystem, Permission::ManageSystem], DataScope::Global);
}

test('an allowlisted failed job is sent back to its queue and the retry is audited', function () {
    $manager = systemManager();
    $uuid = storeFailedJob(new ProcessWhatsAppWebhook(42), 'webhooks');

    $this->actingAs($manager)
        ->from(route('admin.system.index'))
        ->post(route('admin.system.failed-jobs.retry', $uuid))
        ->assertRedirect(route('admin.system.index'));

    $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    $this->assertDatabaseHas('jobs', ['queue' => 'webhooks']);

    $log = AuditLog::query()->where('action', AuditAction::SystemJobRetried)->sole();
    expect($log->user_id)->toBe($manager->id)
        ->and($log->properties)->toMatchArray(['uuid' => $uuid, 'job' => ProcessWhatsAppWebhook::class, 'queue' => 'webhooks']);
});

test('jobs outside the allowlist cannot be retried, such as outbound messages', function () {
    $uuid = storeFailedJob(new DeliverOutboundMessage(7), 'messages');

    $this->actingAs(systemManager())
        ->post(route('admin.system.failed-jobs.retry', $uuid))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    $this->assertDatabaseCount('jobs', 0);
    expect(AuditLog::query()->where('action', AuditAction::SystemJobRetried)->exists())->toBeFalse();
});

test('a failed job can be discarded and the discard is audited', function () {
    $uuid = storeFailedJob(new DeliverOutboundMessage(7), 'messages');

    $this->actingAs(systemManager())
        ->delete(route('admin.system.failed-jobs.destroy', $uuid))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    expect(AuditLog::query()->where('action', AuditAction::SystemJobForgotten)->sole()->properties)
        ->toMatchArray(['uuid' => $uuid, 'job' => DeliverOutboundMessage::class]);
});

test('retrying a job that is no longer failed reports it instead of failing', function () {
    $this->actingAs(systemManager())
        ->post(route('admin.system.failed-jobs.retry', (string) Str::uuid()))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    $this->assertDatabaseCount('jobs', 0);
});

test('viewing the system is not enough to retry or discard failed jobs', function () {
    $viewer = conversationUser([Permission::ViewSystem], DataScope::Global);
    $uuid = storeFailedJob(new ProcessWhatsAppWebhook(42), 'webhooks');

    $this->actingAs($viewer)->post(route('admin.system.failed-jobs.retry', $uuid))->assertForbidden();
    $this->actingAs($viewer)->delete(route('admin.system.failed-jobs.destroy', $uuid))->assertForbidden();

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
});

test('system management requires the organization-wide scope', function () {
    $departmental = conversationUser([Permission::ViewSystem, Permission::ManageSystem], DataScope::Departments);
    $uuid = storeFailedJob(new ProcessWhatsAppWebhook(42), 'webhooks');

    $this->actingAs($departmental)->post(route('admin.system.failed-jobs.retry', $uuid))->assertForbidden();

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
});

test('failed job routes only accept uuids', function () {
    $this->actingAs(systemManager())
        ->post('/admin/system/failed-jobs/1/retry')
        ->assertNotFound();
});
