<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('agents only list and open contacts of their conversations or created by them', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $assigned = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create()->contact;
    $created = Contact::factory()->createdBy($agent)->create();
    $foreign = Conversation::factory()->inDepartment($department)->create()->contact;

    $this->actingAs($agent)
        ->get(route('contacts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 2)
            ->where('can.create', false)
        );

    expect(collect($this->actingAs($agent)->get(route('contacts.index'))->inertiaProps('contacts.data'))->pluck('id')->all())
        ->toEqualCanonicalizing([$assigned->id, $created->id]);

    $this->actingAs($agent)->get(route('contacts.show', $assigned))->assertOk();
    $this->actingAs($agent)->get(route('contacts.show', $foreign))->assertNotFound();
});

test('supervisors reach contacts of their departments but not of other departments', function () {
    [$support, $sales] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($support)->create();
    $own = Conversation::factory()->inDepartment($support)->create()->contact;
    $other = Conversation::factory()->inDepartment($sales)->create()->contact;

    $this->actingAs($supervisor)->get(route('contacts.show', $own))->assertOk();
    $this->actingAs($supervisor)->get(route('contacts.show', $other))->assertNotFound();
});

test('a custom role cannot read or change a contact outside its scope by guessing the id', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewContacts,
        Permission::UpdateContacts,
        Permission::ArchiveContacts,
        Permission::ManageContactNotes,
        Permission::ClassifyContactOrigin,
    ])->create();
    $user = User::factory()->withRole($role)->inDepartments($own)->create();
    $outside = Conversation::factory()->inDepartment($other)->create()->contact;

    $this->actingAs($user)->get(route('contacts.show', $outside))->assertNotFound();
    $this->actingAs($user)->put(route('contacts.update', $outside), ['name' => 'Hackeado', 'phone' => '5512345678'])->assertNotFound();
    $this->actingAs($user)->patch(route('contacts.status.update', $outside), ['status' => 'archived'])->assertNotFound();
    $this->actingAs($user)->post(route('contacts.notes.store', $outside), ['body' => 'x'])->assertNotFound();
    $this->actingAs($user)->post(route('contacts.attributions.store', $outside), ['origin_type' => 'website'])->assertNotFound();

    expect($outside->refresh())
        ->name->not->toBe('Hackeado')
        ->isArchived()->toBeFalse();
});

test('the conversation history only lists conversations the actor can view', function () {
    [$support, $sales] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($support)->create();
    $visible = Conversation::factory()->inDepartment($support)->create();
    Conversation::factory()->inDepartment($sales)->closed()->create(['contact_id' => $visible->contact_id]);

    $this->actingAs($supervisor)
        ->get(route('contacts.show', $visible->contact))
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversations', 1)
            ->where('conversations.0.id', $visible->id)
            ->where('conversations.0.status', 'pending')
            ->where('conversations.0.department.id', $support->id)
        );
});

test('users without contacts.view are forbidden from profiles', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewDashboard])->create();
    $contact = Contact::factory()->create();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('contacts.show', $contact))
        ->assertForbidden();
});
