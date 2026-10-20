<?php

use App\Enums\AuditAction;
use App\Enums\ContactStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;

test('a contact can be archived and restored, and both changes are audited', function () {
    $admin = User::factory()->administrator()->create();
    $contact = Contact::factory()->create();

    $this->actingAs($admin)
        ->patch(route('contacts.status.update', $contact), ['status' => 'archived'])
        ->assertSessionHasNoErrors();

    expect($contact->refresh())
        ->status->toBe(ContactStatus::Archived)
        ->archived_at->not->toBeNull();

    $this->actingAs($admin)
        ->patch(route('contacts.status.update', $contact), ['status' => 'active'])
        ->assertSessionHasNoErrors();

    expect($contact->refresh())
        ->status->toBe(ContactStatus::Active)
        ->archived_at->toBeNull();

    expect(AuditLog::query()->where('auditable_id', $contact->id)->orderBy('id')->pluck('action')->all())
        ->toBe([AuditAction::ContactArchived, AuditAction::ContactRestored]);
});

test('archiving requires contacts.archive', function () {
    $role = Role::factory()->scope(DataScope::Global)
        ->withPermissions([Permission::ViewContacts, Permission::UpdateContacts])
        ->create();
    $contact = Contact::factory()->create();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->patch(route('contacts.status.update', $contact), ['status' => 'archived'])
        ->assertForbidden();

    expect($contact->refresh()->status)->toBe(ContactStatus::Active);
});

test('the status must be a known value', function () {
    $contact = Contact::factory()->create();

    $this->actingAs(User::factory()->administrator()->create())
        ->patch(route('contacts.status.update', $contact), ['status' => 'deleted'])
        ->assertSessionHasErrors('status');
});
