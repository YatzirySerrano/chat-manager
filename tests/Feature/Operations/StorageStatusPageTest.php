<?php

use App\Enums\DefaultRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get(route('admin.storage.index'))->assertRedirect(route('login'));
    $this->getJson(route('admin.storage.status'))->assertUnauthorized();
});

test('roles without system.view cannot see the storage status', function (DefaultRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get(route('admin.storage.index'))->assertForbidden();
    $this->actingAs($user)->getJson(route('admin.storage.status'))->assertForbidden();
    $this->actingAs($user)->postJson(route('admin.storage.probe'))->assertForbidden();
})->with([
    'supervisor' => DefaultRole::Supervisor,
    'agent' => DefaultRole::Agent,
]);

test('the superadministrator sees the storage page', function () {
    $this->actingAs(User::factory()->withRole(DefaultRole::SuperAdministrator)->create())
        ->get(route('admin.storage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/storage/Index')
            ->where('status.disk.kind', 'local')
            ->where('status.disk.external', false)
            ->has('status.checks', 2)
            ->has('status.activity.hourly', 24)
            ->where('canManage', true));
});

test('the live status is a sanitized snapshot', function () {
    config(['filesystems.disks.local.root' => storage_path('framework/testing/disks/storage-status')]);

    $response = $this->actingAs(User::factory()->withRole(DefaultRole::SuperAdministrator)->create())
        ->getJson(route('admin.storage.status'))
        ->assertOk()
        ->assertJsonStructure([
            'state', 'headline', 'message', 'problem', 'checked_at', 'check_age_seconds', 'now',
            'disk' => ['name', 'kind', 'label', 'location', 'required', 'external'],
            'checks' => [['key', 'label', 'status', 'detail']],
            'usage' => ['used_bytes', 'quota_bytes', 'percent', 'level', 'warning_percent'],
            'activity' => ['total_files', 'files_today', 'bytes_today', 'files_7d', 'pending', 'failed_24h', 'last_saved_at', 'hourly', 'recent', 'other_disks'],
        ])
        ->assertJsonPath('disk.kind', 'local')
        ->assertJsonPath('disk.label', 'Disco local del servidor');

    expect($response->json('state'))->toBeIn(['ok', 'warning'])
        ->and($response->getContent())->not->toContain(base_path());
});

test('a disk that refuses files is reported as down', function () {
    config(['attachments.storage.media_disk_required' => true]);

    $this->actingAs(User::factory()->withRole(DefaultRole::SuperAdministrator)->create())
        ->getJson(route('admin.storage.status'))
        ->assertOk()
        ->assertJsonPath('state', 'down')
        ->assertJsonPath('problem', 'local_not_allowed')
        ->assertJsonPath('checks.0.status', 'fail')
        ->assertJsonPath('checks.1.status', 'skipped');
});

test('probing now needs system.manage and returns the snapshot', function () {
    $this->actingAs(User::factory()->withRole(DefaultRole::SuperAdministrator)->create())
        ->postJson(route('admin.storage.probe'))
        ->assertOk()
        ->assertJsonPath('disk.kind', 'local')
        ->assertJsonPath('checks.1.key', 'write');
});
