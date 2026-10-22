<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Inertia\Support\SessionKey;

/**
 * A role limited to departments that may create and edit contacts.
 */
function departmentContactEditor(Department $department): User
{
    $role = Role::factory()->scope(DataScope::Departments)
        ->withPermissions([Permission::ViewContacts, Permission::CreateContacts, Permission::UpdateContacts])
        ->create();

    return User::factory()->withRole($role)->inDepartments($department)->create();
}

test('creating a contact with a phone already in use asks for confirmation and lists the visible duplicate', function () {
    $admin = User::factory()->superAdministrator()->create();
    $existing = Contact::factory()->create(['name' => 'Ya Registrado', 'phone_e164' => '+525512345678']);

    $this->actingAs($admin)
        ->from(route('contacts.index'))
        ->post(route('contacts.store'), ['name' => 'Otra Persona', 'phone' => '55 1234 5678'])
        ->assertRedirect(route('contacts.index'))
        ->assertSessionHasErrors(['phone', 'confirm_duplicate'])
        ->assertSessionDoesntHaveErrors('email');

    expect(session(SessionKey::FLASH_DATA.'.duplicates'))->toBe([
        'contacts' => [[
            'id' => $existing->id,
            'name' => 'Ya Registrado',
            'phone_e164' => '+525512345678',
            'email' => null,
            'status' => 'active',
            'matches' => ['phone'],
        ]],
    ])->and(Contact::query()->count())->toBe(1);
});

test('email duplicates are detected ignoring case', function () {
    $admin = User::factory()->superAdministrator()->create();
    Contact::factory()->create(['email' => 'Cliente@Example.com']);

    $this->actingAs($admin)
        ->post(route('contacts.store'), ['name' => 'Otra', 'email' => 'cliente@EXAMPLE.com'])
        ->assertSessionHasErrors(['email', 'confirm_duplicate']);

    expect(Contact::query()->count())->toBe(1);
});

test('confirming the duplicate creates a separate contact without merging', function () {
    $admin = User::factory()->superAdministrator()->create();
    $existing = Contact::factory()->create(['name' => 'Original', 'phone_e164' => '+525512345678']);

    $this->actingAs($admin)
        ->post(route('contacts.store'), [
            'name' => 'Homónimo',
            'phone' => '5512345678',
            'confirm_duplicate' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(Contact::query()->where('phone_e164', '+525512345678')->count())->toBe(2)
        ->and($existing->refresh()->name)->toBe('Original')
        ->and(AuditLog::query()->where('action', AuditAction::ContactCreated)->sole()->properties)
        ->toMatchArray(['confirmed_duplicate' => true]);
});

test('a contact outside the actor scope is never reported as a duplicate', function () {
    $own = Department::factory()->create();
    $editor = departmentContactEditor($own);
    Contact::factory()->create(['name' => 'Secreto Ajeno', 'phone_e164' => '+525512345678', 'email' => 'ajeno@example.com']);

    $this->actingAs($editor)
        ->post(route('contacts.store'), ['name' => 'Nuevo', 'phone' => '5512345678', 'email' => 'ajeno@example.com'])
        ->assertSessionHasNoErrors();

    expect(session(SessionKey::FLASH_DATA.'.duplicates'))->toBeNull()
        ->and(json_encode(session()->all()))->not->toContain('Secreto Ajeno')
        ->and(Contact::query()->where('created_by_user_id', $editor->id)->sole()->name)->toBe('Nuevo');
});

test('editing a contact only warns when the phone or email changes to one already in use', function () {
    $admin = User::factory()->superAdministrator()->create();
    $first = Contact::factory()->create(['phone_e164' => '+525511111111']);
    $twin = Contact::factory()->create(['phone_e164' => '+525511111111']);
    $other = Contact::factory()->create(['phone_e164' => '+525522222222']);

    $this->actingAs($admin)
        ->put(route('contacts.update', $twin), ['name' => 'Nombre nuevo', 'phone' => '5511111111'])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->put(route('contacts.update', $other), ['name' => $other->name, 'phone' => '5511111111'])
        ->assertSessionHasErrors(['phone', 'confirm_duplicate']);

    expect($other->refresh()->phone_e164)->toBe('+525522222222')
        ->and(collect(session(SessionKey::FLASH_DATA.'.duplicates.contacts'))->pluck('id')->all())
        ->toEqualCanonicalizing([$first->id, $twin->id]);
});
