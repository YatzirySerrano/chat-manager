<?php

use App\Enums\AuditAction;
use App\Enums\ContactStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  list<Permission>  $permissions
 */
function contactManager(array $permissions): User
{
    return User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewContacts, ...$permissions])->create())->create();
}

test('a contact can be deleted and restored keeping its history, and both changes are audited', function () {
    $admin = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);
    $note = ContactNote::factory()->create(['contact_id' => $contact->id]);

    $this->actingAs($admin)
        ->delete(route('contacts.destroy', $contact))
        ->assertSessionHasNoErrors();

    $this->assertModelExists($contact);
    expect($contact->refresh())
        ->status->toBe(ContactStatus::Archived)
        ->archived_at->not->toBeNull()
        ->and($conversation->refresh()->contact_id)->toBe($contact->id)
        ->and($note->refresh()->contact_id)->toBe($contact->id);

    $this->actingAs($admin)
        ->patch(route('contacts.restore', $contact))
        ->assertSessionHasNoErrors();

    expect($contact->refresh())
        ->status->toBe(ContactStatus::Active)
        ->archived_at->toBeNull();

    expect(AuditLog::query()->where('auditable_type', 'contact')->where('auditable_id', $contact->id)->orderBy('id')->pluck('action')->all())
        ->toBe([AuditAction::ContactDeleted, AuditAction::ContactRestored]);
});

test('deleting requires contacts.delete and restoring also requires records.view-deleted', function () {
    $active = Contact::factory()->create();
    $deleted = Contact::factory()->archived()->create();

    $this->actingAs(contactManager([Permission::UpdateContacts]))
        ->delete(route('contacts.destroy', $active))
        ->assertForbidden();

    $this->actingAs(contactManager([Permission::DeleteContacts]))
        ->patch(route('contacts.restore', $deleted))
        ->assertNotFound();

    $this->actingAs(contactManager([Permission::DeleteContacts, Permission::ViewDeletedRecords]))
        ->patch(route('contacts.restore', $deleted))
        ->assertSessionHasNoErrors();

    expect($active->refresh()->status)->toBe(ContactStatus::Active)
        ->and($deleted->refresh()->status)->toBe(ContactStatus::Active);
});

test('without records.view-deleted deleted contacts cannot be found through lists, search or their id', function () {
    $viewer = contactManager([Permission::UpdateContacts, Permission::DeleteContacts]);
    Contact::factory()->create(['name' => 'Zoe Activa']);
    $deleted = Contact::factory()->archived()->create(['name' => 'Zoe Eliminada']);
    commitForFullTextSearch();

    foreach (['deleted', 'all'] as $record) {
        $this->actingAs($viewer)
            ->get(route('contacts.index', ['search' => 'zoe', 'record' => $record]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'Zoe Activa')
                ->where('filters.record', 'active')
                ->where('recordStates', []));
    }

    $this->actingAs($viewer)->get(route('contacts.show', $deleted))->assertNotFound();
    $this->actingAs($viewer)->put(route('contacts.update', $deleted), ['name' => 'X', 'phone' => '5512345678'])->assertNotFound();
});

test('a deleted contact is read-only until it is restored', function () {
    $admin = User::factory()->superAdministrator()->create();
    $deleted = Contact::factory()->archived()->create();

    $this->actingAs($admin)
        ->get(route('contacts.show', $deleted))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('contact.is_deleted', true)
            ->where('can.update', false)
            ->where('can.delete', false)
            ->where('can.restore', true));

    $this->actingAs($admin)
        ->put(route('contacts.update', $deleted), ['name' => 'Editado', 'phone' => $deleted->phone_e164])
        ->assertForbidden();
});

test('a duplicate that is a deleted contact the employee cannot consult goes to administrative review', function () {
    $employee = contactManager([Permission::CreateContacts]);
    Contact::factory()->archived()->create(['phone_e164' => '+525512345678', 'name' => 'Oculto']);

    $this->actingAs($employee)
        ->post(route('contacts.store'), ['name' => 'Nuevo', 'phone' => '+52 55 1234 5678'])
        ->assertSessionHasNoErrors();

    expect(Contact::query()->where('name', 'Nuevo')->sole()->reviewCases()->count())->toBe(1);
});

test('deleting from the profile without records.view-deleted leaves the page that no longer exists', function () {
    $manager = contactManager([Permission::DeleteContacts]);
    $contact = Contact::factory()->create();

    $this->actingAs($manager)
        ->from(route('contacts.show', $contact))
        ->delete(route('contacts.destroy', $contact))
        ->assertRedirect(route('contacts.index'));

    $this->actingAs($manager)
        ->from(route('contacts.index', ['search' => 'x']))
        ->delete(route('contacts.destroy', Contact::factory()->create()))
        ->assertRedirect(route('contacts.index', ['search' => 'x']));
});
