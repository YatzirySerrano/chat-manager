<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('only users with the permission can open the personalization module', function (DefaultRole $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('admin.appearance.edit'))
        ->assertForbidden();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->put(route('admin.appearance.update'), ['palette' => 'rose'])
        ->assertForbidden();
})->with(['supervisor' => DefaultRole::Supervisor, 'agent' => DefaultRole::Agent]);

test('the platform uses the green palette by default', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('admin.appearance.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/appearance/Edit')
            ->where('palette', 'emerald')
            ->where('defaultPalette', 'emerald')
            ->where('platform.palette', 'emerald')
            ->where('can.update', true)
        );

    $this->get(route('dashboard'))->assertSee('data-palette="emerald"', false);
});

test('the global palette can be changed, is shared with every page and is audited', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->put(route('admin.appearance.update'), ['palette' => 'indigo'])
        ->assertSessionHasNoErrors();

    $this->actingAs(User::factory()->agent()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('platform.palette', 'indigo'));

    expect(AuditLog::query()->where('action', AuditAction::AppearanceUpdated)->sole()->properties['changes'])
        ->toBe(['appearance.palette' => ['old' => 'emerald', 'new' => 'indigo']]);
});

test('only curated palettes are accepted', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->put(route('admin.appearance.update'), ['palette' => 'red; background: url(x)'])
        ->assertSessionHasErrors('palette');
});
