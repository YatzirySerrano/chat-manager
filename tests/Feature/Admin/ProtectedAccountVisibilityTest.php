<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\User;
use App\Reports\Rendering\PdfRenderer;
use App\Reports\ReportRegistry;
use App\Services\Exports\ReportExporter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->root = User::factory()->superAdministrator()->create(['name' => 'Cuenta Técnica', 'email' => 'tecnica@empresa.test']);
    $this->administrator = User::factory()->administrator()->create();
});

/**
 * Rendered HTML (PDF source) of a report for the actor.
 *
 * @param  array<string, mixed>  $input
 */
function protectedReportHtml(string $key, User $actor, array $input = []): string
{
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find($key);

    return app(PdfRenderer::class)->html($definition, $exporter->prepare($definition, $actor, $input));
}

test('an administrator never sees the root role, its reserved permissions nor references to it in the roles screen', function () {
    $this->actingAs($this->administrator)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles): bool => collect($roles)->where('is_root', true)->isEmpty()
                && collect($roles)->pluck('label')->doesntContain('Superadministrador'))
            ->where('roles', fn ($roles): bool => collect($roles)->firstWhere('name', DefaultRole::Administrator->value)['description']
                === 'Administra usuarios, departamentos, conversaciones y configuraciones de toda la organización.')
            ->where('catalog', fn ($catalog): bool => collect($catalog)->flatMap(fn (array $group): array => $group['permissions'])
                ->where('reserved', true)->isEmpty())
            ->where('rootCapabilities', []));
});

test('a stored description of a default role that mentions the root account is presented with the current wording', function () {
    role(DefaultRole::Administrator)->forceFill(['description' => 'Administra la operación de toda la organización por debajo del superadministrador.'])->save();

    $this->actingAs($this->administrator)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'roles',
            fn ($roles): bool => ! str_contains(json_encode($roles, JSON_UNESCAPED_UNICODE), 'superadministrador'),
        ));
});

test('an administrator never sees root accounts in the user list, its search, filters or counters', function () {
    $agent = User::factory()->agent()->create();
    $activeVisible = User::query()->where('is_active', true)->whereKeyNot($this->root->id)->count();

    $this->actingAs($this->administrator)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.data', fn ($users): bool => collect($users)->pluck('id')->doesntContain($this->root->id)
                && collect($users)->pluck('id')->contains($agent->id))
            ->where('users.meta.total', $activeVisible)
            ->where('stats.active', $activeVisible)
            ->where('roles', fn ($roles): bool => collect($roles)->where('is_root', true)->isEmpty()));

    $this->actingAs($this->administrator)
        ->get(route('admin.users.index', ['search' => 'Técnica']))
        ->assertInertia(fn (Assert $page) => $page->where('users.meta.total', 0));

    $this->actingAs($this->administrator)
        ->get(route('admin.users.index', ['role' => role(DefaultRole::SuperAdministrator)->id]))
        ->assertInertia(fn (Assert $page) => $page->where('users.meta.total', 0));
});

test('the users and roles exports of an administrator leave root accounts and the root role out', function () {
    $users = protectedReportHtml('users', $this->administrator);
    $roles = protectedReportHtml('roles', $this->administrator);

    expect($users)->not->toContain('tecnica@empresa.test')
        ->not->toContain('Superadministrador')
        ->and($roles)->not->toContain('Superadministrador')
        ->not->toContain('superadministrador')
        ->not->toContain('raíz')
        ->not->toContain(Permission::ImportContacts->label());
});

test('the root authority still sees root accounts, the root role and its exports', function () {
    $this->actingAs($this->root)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.data', fn ($users): bool => collect($users)->pluck('id')->contains($this->root->id))
            ->where('roles', fn ($roles): bool => collect($roles)->where('is_root', true)->isNotEmpty()));

    $this->actingAs($this->root)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles.0.is_root', true)
            ->where('rootCapabilities', fn ($capabilities): bool => count($capabilities) > 0));

    expect(protectedReportHtml('users', $this->root))->toContain('tecnica@empresa.test')
        ->and(protectedReportHtml('roles', $this->root))->toContain('Superadministrador');
});

test('operations on root accounts and the root role answer as if they did not exist for anyone else', function () {
    $rootRole = role(DefaultRole::SuperAdministrator);
    $agent = User::factory()->agent()->create();

    $this->actingAs($this->administrator)->put(route('admin.users.update', $this->root), ['name' => 'X', 'email' => $this->root->email])->assertNotFound();
    $this->actingAs($this->administrator)->delete(route('admin.users.destroy', $this->root))->assertNotFound();
    $this->actingAs($this->administrator)->put(route('admin.roles.update', $rootRole), ['label' => 'Tomado', 'scope' => 'global', 'permissions' => []])->assertNotFound();
    $this->actingAs($this->administrator)->delete(route('admin.roles.destroy', $rootRole))->assertNotFound();

    $this->actingAs($this->administrator)
        ->postJson(route('admin.users.role-impact', $agent), ['role_id' => $rootRole->id])
        ->assertUnprocessable()
        ->assertJsonMissing(['label' => 'Superadministrador']);

    $this->actingAs($this->administrator)
        ->put(route('admin.users.update', $agent), ['name' => $agent->name, 'email' => $agent->email, 'role_id' => $rootRole->id])
        ->assertSessionHasErrors('role_id');

    expect(session('errors')->first('role_id'))->not->toContain('Superadministrador')
        ->and($this->root->refresh()->name)->toBe('Cuenta Técnica')
        ->and($rootRole->refresh()->label)->toBe('Superadministrador')
        ->and($agent->refresh()->hasRootAuthority())->toBeFalse();
});

test('agents and administrators never reach root accounts through the shared user scope', function () {
    $agent = User::factory()->agent()->create();

    expect(User::query()->withoutProtectedFor($this->administrator)->whereKey($this->root->id)->exists())->toBeFalse()
        ->and(User::query()->withoutProtectedFor($agent)->whereKey($this->root->id)->exists())->toBeFalse()
        ->and(User::query()->withoutProtectedFor($this->root)->whereKey($this->root->id)->exists())->toBeTrue();

    $this->actingAs($agent)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($agent)->get(route('admin.roles.index'))->assertForbidden();
});

test('assigning the root role is refused exactly like assigning a role that does not exist', function () {
    $rootRole = role(DefaultRole::SuperAdministrator);
    $missingId = $rootRole->id + 100000;
    $agent = User::factory()->agent()->create();
    $roleError = function (string $method, string $url, array $payload): string {
        $this->actingAs($this->administrator)->{$method}($url, $payload)->assertSessionHasErrors('role_id');

        return (string) session('errors')->first('role_id');
    };
    $newUser = fn (int $roleId): array => [
        'name' => 'Nueva', 'email' => 'nueva@empresa.test', 'password' => 'password', 'password_confirmation' => 'password', 'role_id' => $roleId,
    ];
    $update = fn (int $roleId): array => ['name' => $agent->name, 'email' => $agent->email, 'role_id' => $roleId];

    expect($roleError('post', route('admin.users.store'), $newUser($rootRole->id)))
        ->toBe($roleError('post', route('admin.users.store'), $newUser($missingId)))
        ->and($roleError('put', route('admin.users.update', $agent), $update($rootRole->id)))
        ->toBe($roleError('put', route('admin.users.update', $agent), $update($missingId)));
});
