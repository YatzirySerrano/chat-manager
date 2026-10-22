<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the catalog requires permission to view tags', function () {
    $agent = User::factory()->agent()->create();

    $this->actingAs($agent)->get(route('tags.index'))->assertForbidden();
});

test('supervisors can consult the catalog but not manage it', function () {
    $supervisor = User::factory()->supervisor()->create();
    $tag = Tag::factory()->create();

    $this->actingAs($supervisor)
        ->get(route('tags.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('tags/Index')
            ->has('tags.data', 1)
            ->where('can.manage', false)
        );

    $this->actingAs($supervisor)->post(route('tags.store'), ['name' => 'VIP', 'color' => 'blue'])->assertForbidden();
    $this->actingAs($supervisor)->put(route('tags.update', $tag), ['name' => 'VIP', 'color' => 'blue'])->assertForbidden();
    $this->actingAs($supervisor)->delete(route('tags.destroy', $tag))->assertForbidden();
});

test('managing tags is reserved to global roles even when the permission is granted', function () {
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewTags, Permission::ManageTags])->create();
    $manager = User::factory()->withRole($role)->create();

    $this->actingAs($manager)->post(route('tags.store'), ['name' => 'VIP', 'color' => 'blue'])->assertForbidden();
});

test('the catalog shows how many contacts and conversations use each tag', function () {
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'VIP']);
    Tag::factory()->create(['name' => 'Sin uso']);
    $tag->contacts()->attach(Contact::factory()->count(3)->create());
    $tag->conversations()->attach(Conversation::factory()->create());

    $this->actingAs($admin)
        ->get(route('tags.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.manage', true)
            ->where('tags.data.0.name', 'Sin uso')
            ->where('tags.data.0.contacts_count', 0)
            ->where('tags.data.1.name', 'VIP')
            ->where('tags.data.1.contacts_count', 3)
            ->where('tags.data.1.conversations_count', 1)
        );
});

test('the catalog can be searched and filtered by record state', function () {
    $admin = User::factory()->superAdministrator()->create();
    Tag::factory()->create(['name' => 'Cliente VIP']);
    Tag::factory()->inactive()->create(['name' => 'VIP antiguo']);
    Tag::factory()->create(['name' => 'Mayorista']);

    $this->actingAs($admin)
        ->get(route('tags.index', ['search' => 'vip']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tags.data', 1)
            ->where('tags.data.0.name', 'Cliente VIP')
            ->where('filters.record', 'active')
        );

    $this->actingAs($admin)
        ->get(route('tags.index', ['search' => 'vip', 'record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tags.data', 1)
            ->where('tags.data.0.name', 'VIP antiguo')
            ->where('tags.data.0.can', ['update' => false, 'delete' => false, 'restore' => true])
        );

    $this->actingAs($admin)
        ->get(route('tags.index', ['search' => 'vip', 'record' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('tags.data', 2));
});

test('without records.view-deleted deleted tags are neither listed, counted nor restorable', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewTags, Permission::ManageTags])->create();
    $manager = User::factory()->withRole($role)->create();
    $deleted = Tag::factory()->inactive()->create(['name' => 'VIP antiguo']);

    $this->actingAs($manager)
        ->get(route('tags.index', ['record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tags.data', 0)
            ->where('tags.meta.total', 0)
            ->where('recordStates', []));

    $this->actingAs($manager)->patch(route('tags.restore', $deleted))->assertForbidden();

    expect($deleted->refresh()->is_active)->toBeFalse();
});

test('administrators can create a tag and the action is audited', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('tags.store'), ['name' => '  Cliente   frecuente ', 'color' => 'emerald', 'description' => 'Compra cada mes'])
        ->assertSessionHasNoErrors();

    $tag = Tag::query()->sole();

    expect($tag)
        ->name->toBe('Cliente frecuente')
        ->slug->toBe('cliente-frecuente')
        ->is_active->toBeTrue()
        ->created_by_user_id->toBe($admin->id);

    expect(AuditLog::query()->where('action', AuditAction::TagCreated)->sole()->auditable_id)->toBe($tag->id);
});

test('tag names are unique regardless of letter case', function () {
    $admin = User::factory()->superAdministrator()->create();
    Tag::factory()->create(['name' => 'VIP']);

    $this->actingAs($admin)
        ->post(route('tags.store'), ['name' => 'vip', 'color' => 'neon'])
        ->assertSessionHasErrors([
            'name' => 'Ya existe una etiqueta con ese nombre (no se distinguen mayúsculas de minúsculas).',
            'color' => 'El color no es válido. Elige un color de la paleta o un color personalizado con el formato #rrggbb.',
        ]);

    expect(Tag::query()->count())->toBe(1);
});

test('administrators can update a tag keeping its own name', function () {
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'VIP', 'color' => 'blue']);

    $this->actingAs($admin)
        ->put(route('tags.update', $tag), ['name' => 'VIP', 'color' => 'rose'])
        ->assertSessionHasNoErrors();

    expect($tag->refresh()->color->value)->toBe('rose');

    $log = AuditLog::query()->where('action', AuditAction::TagUpdated)->sole();
    expect($log->properties['changes']['color'])->toBe(['old' => 'blue', 'new' => 'rose']);
});

test('deleting a tag keeps it on the records that already have it and it can be restored', function () {
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create();
    $contact = Contact::factory()->create();
    $tag->contacts()->attach($contact);

    $this->actingAs($admin)
        ->delete(route('tags.destroy', $tag))
        ->assertSessionHasNoErrors();

    $this->assertModelExists($tag);
    expect($tag->refresh()->isDeleted())->toBeTrue()
        ->and($contact->tags()->pluck('tags.id')->all())->toBe([$tag->id])
        ->and(AuditLog::query()->where('action', AuditAction::TagDeleted)->sole()->properties)->toBe(['contacts_kept' => 1, 'conversations_kept' => 0]);

    $this->actingAs($admin)->put(route('tags.update', $tag), ['name' => 'Otro', 'color' => 'blue'])->assertForbidden();
    $this->actingAs($admin)->patch(route('tags.restore', $tag))->assertSessionHasNoErrors();

    expect($tag->refresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::TagRestored)->count())->toBe(1);
});

test('each listed tag exposes only the operations the viewer may perform', function () {
    Tag::factory()->create();

    $this->actingAs(User::factory()->supervisor()->create())
        ->get(route('tags.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tags.data.0.can', ['update' => false, 'delete' => false, 'restore' => false]));

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('tags.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tags.data.0.can', ['update' => true, 'delete' => true, 'restore' => false]));
});

test('saving a tag without effective changes writes nothing and says so', function () {
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'Cliente VIP', 'description' => null, 'color' => 'blue']);
    $updatedAt = $tag->updated_at;
    $this->travel(1)->hours();

    $writes = databaseWritesDuring(fn () => $this->actingAs($admin)
        ->put(route('tags.update', $tag), ['name' => ' Cliente   VIP ', 'description' => ' ', 'color' => 'blue'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No había cambios por guardar.']));

    expect($writes)->toBe([])
        ->and($tag->refresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::TagUpdated)->exists())->toBeFalse();
});

test('a real change to a tag is confirmed with a success toast', function () {
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'VIP', 'color' => 'blue']);

    $this->actingAs($admin)
        ->put(route('tags.update', $tag), ['name' => 'VIP', 'color' => 'rose'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Etiqueta actualizada.']);
});
