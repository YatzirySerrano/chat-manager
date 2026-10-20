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
    $this->actingAs($supervisor)->patch(route('tags.status.update', $tag), ['is_active' => false])->assertForbidden();
});

test('managing tags is reserved to global roles even when the permission is granted', function () {
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewTags, Permission::ManageTags])->create();
    $manager = User::factory()->withRole($role)->create();

    $this->actingAs($manager)->post(route('tags.store'), ['name' => 'VIP', 'color' => 'blue'])->assertForbidden();
});

test('the catalog shows how many contacts and conversations use each tag', function () {
    $admin = User::factory()->administrator()->create();
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

test('the catalog can be searched and filtered by status', function () {
    $admin = User::factory()->administrator()->create();
    Tag::factory()->create(['name' => 'Cliente VIP']);
    Tag::factory()->inactive()->create(['name' => 'VIP antiguo']);
    Tag::factory()->create(['name' => 'Mayorista']);

    $this->actingAs($admin)
        ->get(route('tags.index', ['search' => 'vip', 'status' => 'active']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tags.data', 1)
            ->where('tags.data.0.name', 'Cliente VIP')
            ->where('filters.status', 'active')
        );
});

test('administrators can create a tag and the action is audited', function () {
    $admin = User::factory()->administrator()->create();

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
    $admin = User::factory()->administrator()->create();
    Tag::factory()->create(['name' => 'VIP']);

    $this->actingAs($admin)
        ->post(route('tags.store'), ['name' => 'vip', 'color' => 'neon'])
        ->assertSessionHasErrors([
            'name' => 'Ya existe una etiqueta con ese nombre (no se distinguen mayúsculas de minúsculas).',
            'color' => 'El valor seleccionado en color no es válido.',
        ]);

    expect(Tag::query()->count())->toBe(1);
});

test('administrators can update a tag keeping its own name', function () {
    $admin = User::factory()->administrator()->create();
    $tag = Tag::factory()->create(['name' => 'VIP', 'color' => 'blue']);

    $this->actingAs($admin)
        ->put(route('tags.update', $tag), ['name' => 'VIP', 'color' => 'rose'])
        ->assertSessionHasNoErrors();

    expect($tag->refresh()->color->value)->toBe('rose');

    $log = AuditLog::query()->where('action', AuditAction::TagUpdated)->sole();
    expect($log->properties['changes']['color'])->toBe(['old' => 'blue', 'new' => 'rose']);
});

test('deactivating a tag keeps it on the records that already have it', function () {
    $admin = User::factory()->administrator()->create();
    $tag = Tag::factory()->create();
    $contact = Contact::factory()->create();
    $tag->contacts()->attach($contact);

    $this->actingAs($admin)
        ->patch(route('tags.status.update', $tag), ['is_active' => false])
        ->assertSessionHasNoErrors();

    expect($tag->refresh()->is_active)->toBeFalse()
        ->and($contact->tags()->pluck('tags.id')->all())->toBe([$tag->id])
        ->and(AuditLog::query()->where('action', AuditAction::TagDeactivated)->count())->toBe(1);

    $this->actingAs($admin)->patch(route('tags.status.update', $tag), ['is_active' => true]);

    expect($tag->refresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::TagActivated)->count())->toBe(1);
});
