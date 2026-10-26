<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('every permission explains in plain words what it allows, without technical names', function (Permission $permission) {
    $description = $permission->description();

    expect($description)->not->toBe('')
        ->and(mb_strlen($description))->toBeLessThanOrEqual(140)
        ->and($description)->not->toContain($permission->value)
        ->and($description)->not->toMatch('/\b[a-z-]+\.[a-z-]+\b/');
})->with(Permission::cases());

test('every permission group has a description and a known icon', function (PermissionGroup $group) {
    expect($group->description())->not->toBe('')
        ->and(mb_strlen($group->description()))->toBeLessThanOrEqual(140)
        ->and($group->icon())->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');
})->with(PermissionGroup::cases());

test('the roles screen catalog carries the descriptions and icons the role cards and the permission detail need', function () {
    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog', fn ($catalog): bool => collect($catalog)->every(
                fn (array $group): bool => $group['description'] === PermissionGroup::from($group['value'])->description()
                    && $group['icon'] === PermissionGroup::from($group['value'])->icon()
                    && collect($group['permissions'])->every(
                        fn (array $permission): bool => $permission['description'] === Permission::from($permission['value'])->description(),
                    ),
            ))
            ->where('scopes', fn ($scopes): bool => collect($scopes)->pluck('value')->all() === ['global', 'departments', 'assigned']
                && collect($scopes)->every(fn (array $scope): bool => $scope['example'] === DataScope::from($scope['value'])->example()
                    && $scope['icon'] === DataScope::from($scope['value'])->icon()
                    && is_bool($scope['grantable']))));
});

test('the catalog shown to a non-root administrator keeps the descriptions but never the reserved permissions', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog', fn ($catalog): bool => collect($catalog)->every(fn (array $group): bool => filled($group['description']) && filled($group['icon']))
                && collect($catalog)->flatMap(fn (array $group): array => $group['permissions'])->every(
                    fn (array $permission): bool => ! $permission['reserved'] && filled($permission['description']),
                )));
});
