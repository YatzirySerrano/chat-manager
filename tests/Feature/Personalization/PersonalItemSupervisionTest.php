<?php

use App\Enums\AuditAction;
use App\Enums\ChatAppearancePreset;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\ChatAppearance;
use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Private between colleagues is not private from an authorized
| administrator: view-personal consults, manage-personal edits or deletes,
| always within the role's data scope, and every intervention is audited
| without the alias text.
*/

beforeEach(function () {
    $this->sales = Department::factory()->create();
    $this->support = Department::factory()->create();
    $this->salesAgent = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ana Ventas']);
    $this->supportAgent = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Beto Soporte']);
    $this->contact = Contact::factory()->create(['name' => 'Laura Pérez']);
    $this->salesAlias = ContactAlias::factory()->for($this->salesAgent)->for($this->contact)->create(['alias' => 'Cliente de las cajas']);
    $this->supportAlias = ContactAlias::factory()->for($this->supportAgent)->for($this->contact)->create(['alias' => 'Otra']);
});

test('an administrator with view-personal lists aliases and appearances but cannot change them', function () {
    $viewer = conversationUser([Permission::ViewPersonalItems, Permission::ViewContacts], DataScope::Global);
    $appearance = ChatAppearance::factory()->for($this->salesAgent)->create(['preset' => 'ocean', 'colors' => ChatAppearancePreset::Ocean->colors()]);

    $this->actingAs($viewer)
        ->get(route('admin.personal-items.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/personal-items/Index')
            ->has('items.data', 2)
            ->where('items.data.0.contact.name', 'Laura Pérez')
            ->where('can.manage', false));

    $this->actingAs($viewer)
        ->get(route('admin.personal-items.index', ['type' => 'appearance']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.owner.name', 'Ana Ventas')
            ->where('items.data.0.preset_label', 'Océano')
            ->where('items.data.0.scope_label', 'Todas sus conversaciones'));

    $this->actingAs($viewer)
        ->put(route('admin.personal-items.aliases.update', $this->salesAlias), ['alias' => 'Cambiado'])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->delete(route('admin.personal-items.aliases.destroy', $this->salesAlias))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->put(route('admin.personal-items.appearances.update', $appearance), ['preset' => 'forest', 'colors' => ChatAppearancePreset::Forest->colors()])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->delete(route('admin.personal-items.appearances.destroy', $appearance))
        ->assertForbidden();

    expect($this->salesAlias->refresh()->alias)->toBe('Cliente de las cajas')
        ->and($appearance->refresh()->preset)->toBe('ocean')
        ->and($appearance->colors)->toBe(ChatAppearancePreset::Ocean->colors())
        ->and(AuditLog::query()->count())->toBe(0);
});

test('an administrator with manage-personal edits and deletes another user\'s alias, audited without its text', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->put(route('admin.personal-items.aliases.update', $this->salesAlias), ['alias' => 'Nombre corregido'])
        ->assertRedirect();

    expect($this->salesAlias->refresh()->alias)->toBe('Nombre corregido')
        ->and($this->contact->refresh()->name)->toBe('Laura Pérez');

    $this->actingAs($admin)
        ->delete(route('admin.personal-items.aliases.destroy', $this->salesAlias))
        ->assertRedirect();

    expect(ContactAlias::query()->whereKey($this->salesAlias->id)->exists())->toBeFalse()
        // Another user's alias of the same contact is never touched.
        ->and($this->supportAlias->refresh()->alias)->toBe('Otra');

    $entries = AuditLog::query()->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('action')->all())->toBe([AuditAction::SupervisionPersonalItemUpdated, AuditAction::SupervisionPersonalItemDeleted])
        ->and($entries->every(fn (AuditLog $entry): bool => $entry->user_id === $admin->id
            && $entry->properties['owner_user_id'] === $this->salesAgent->id
            && $entry->properties['item_type'] === 'contact_alias'
            && $entry->properties['item_id'] === $this->salesAlias->id))->toBeTrue()
        ->and(json_encode($entries->map->only(['properties', 'auditable_label'])->all()))
        ->not->toContain('Nombre corregido')
        ->not->toContain('Cliente de las cajas');
});

test('an administrator with manage-personal restores or changes another user\'s appearance, audited', function () {
    $admin = User::factory()->administrator()->create();
    $appearance = ChatAppearance::factory()->for($this->salesAgent)->create();

    $this->actingAs($admin)
        ->put(route('admin.personal-items.appearances.update', $appearance), ['preset' => 'forest', 'colors' => ChatAppearancePreset::Forest->colors()])
        ->assertRedirect();

    expect($appearance->refresh())->preset->toBe('forest')->user_id->toBe($this->salesAgent->id)
        // The administrator's own view is untouched.
        ->and(ChatAppearance::query()->where('user_id', $admin->id)->exists())->toBeFalse();

    $this->actingAs($admin)
        ->delete(route('admin.personal-items.appearances.destroy', $appearance))
        ->assertRedirect();

    expect(ChatAppearance::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::SupervisionPersonalItemDeleted)->first()->properties)
        ->toMatchArray(['owner_user_id' => $this->salesAgent->id, 'item_type' => 'chat_appearance', 'item_id' => $appearance->id]);
});

test('a departments-scope supervisor only reaches users sharing one of their departments', function () {
    $supervisor = conversationUser([Permission::ViewPersonalItems, Permission::ManagePersonalItems], DataScope::Departments, [$this->sales]);

    $this->actingAs($supervisor)
        ->get(route('admin.personal-items.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.id', $this->salesAlias->id)
            ->where('users', fn ($users) => collect($users)->pluck('id')->all() === [$this->salesAgent->id]));

    // Filtering by a user outside the scope shows nothing.
    $this->actingAs($supervisor)
        ->get(route('admin.personal-items.index', ['user' => $this->supportAgent->id]))
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));

    $this->actingAs($supervisor)
        ->put(route('admin.personal-items.aliases.update', $this->supportAlias), ['alias' => 'Cambiado'])
        ->assertNotFound();
    $this->actingAs($supervisor)
        ->delete(route('admin.personal-items.aliases.destroy', $this->supportAlias))
        ->assertNotFound();

    expect($this->supportAlias->refresh()->alias)->toBe('Otra');
});

test('managing another user\'s alias or appearance needs authority over their account', function () {
    // In scope and with manage-personal, but unable to assign the agent's
    // role (it lacks the agent's permissions): consult only.
    $supervisor = conversationUser([Permission::ViewPersonalItems, Permission::ManagePersonalItems], DataScope::Departments, [$this->sales]);
    $appearance = ChatAppearance::factory()->for($this->salesAgent)->create();

    $this->actingAs($supervisor)
        ->get(route('admin.personal-items.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.id', $this->salesAlias->id)
            ->where('items.data.0.can', ['update' => false, 'delete' => false]));

    $this->actingAs($supervisor)
        ->put(route('admin.personal-items.aliases.update', $this->salesAlias), ['alias' => 'Cambiado'])
        ->assertForbidden();
    $this->actingAs($supervisor)
        ->delete(route('admin.personal-items.aliases.destroy', $this->salesAlias))
        ->assertForbidden();
    $this->actingAs($supervisor)
        ->delete(route('admin.personal-items.appearances.destroy', $appearance))
        ->assertForbidden();

    expect($this->salesAlias->refresh()->alias)->toBe('Cliente de las cajas')
        ->and($appearance->fresh())->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::SupervisionPersonalItemDeleted)->exists())->toBeFalse();
});

test('an assigned-scope user with the permissions supervises nobody', function () {
    $user = conversationUser([Permission::ViewPersonalItems, Permission::ManagePersonalItems], DataScope::Assigned, [$this->sales]);

    $this->actingAs($user)
        ->get(route('admin.personal-items.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 0)->where('users', []));

    $this->actingAs($user)
        ->delete(route('admin.personal-items.aliases.destroy', $this->salesAlias))
        ->assertNotFound();
});

test('the items of a Superadministrador stay out of reach of other administrators', function () {
    $root = User::factory()->superAdministrator()->create();
    $rootAlias = ContactAlias::factory()->for($root)->for($this->contact)->create();
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('admin.personal-items.index'))
        ->assertInertia(fn (Assert $page) => $page->where('items.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($rootAlias->id)));

    $this->actingAs($admin)
        ->delete(route('admin.personal-items.aliases.destroy', $rootAlias))
        ->assertNotFound();
});

test('the supervision screen hides contacts outside the supervisor\'s own reach', function () {
    $colleague = User::factory()->agent()->inDepartments([$this->sales, $this->support])->create(['name' => 'Carla Mixta']);
    $reachable = Contact::factory()->create(['name' => 'Cliente de Ventas', 'phone_e164' => '+5215511111111']);
    $hidden = Contact::factory()->create(['name' => 'Cliente de Soporte', 'phone_e164' => '+5215522222222']);
    Conversation::factory()->for($reachable)->inDepartment($this->sales)->create();
    $foreignConversation = Conversation::factory()->for($hidden)->inDepartment($this->support)->create();
    $visibleAlias = ContactAlias::factory()->for($colleague)->for($reachable)->create();
    $hiddenAlias = ContactAlias::factory()->for($colleague)->for($hidden)->create();
    ChatAppearance::factory()->for($colleague)->create(['conversation_id' => $foreignConversation->id]);
    $supervisor = conversationUser([Permission::ViewPersonalItems, Permission::ViewContacts], DataScope::Departments, [$this->sales]);

    $rows = collect($this->actingAs($supervisor)
        ->get(route('admin.personal-items.index', ['user' => $colleague->id]))
        ->viewData('page')['props']['items']['data']);

    expect($rows->firstWhere('id', $visibleAlias->id)['contact'])->toMatchArray(['name' => 'Cliente de Ventas', 'phone_e164' => '+5215511111111'])
        ->and($rows->firstWhere('id', $hiddenAlias->id)['contact'])->toMatchArray(['name' => 'Contacto que no puedes ver', 'phone_e164' => null]);

    $this->actingAs($supervisor)
        ->get(route('admin.personal-items.index', ['type' => 'appearance', 'user' => $colleague->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.scope_label', 'Conversación #'.$foreignConversation->id.' · Contacto que no puedes ver'));
});
