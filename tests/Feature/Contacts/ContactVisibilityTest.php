<?php

use App\Enums\DefaultRole;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;

test('global users see every contact, archived ones included', function () {
    $admin = User::factory()->administrator()->create();
    Contact::factory()->create();
    Contact::factory()->archived()->create();

    expect(Contact::query()->visibleTo($admin)->count())->toBe(2);
});

test('department users see contacts of their departments, their assignments and the ones they created', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($support)->create();
    $inDepartment = Conversation::factory()->inDepartment($support)->create()->contact;
    $assignedElsewhere = Conversation::factory()->inDepartment($sales)->assignedTo($supervisor)->create()->contact;
    $created = Contact::factory()->createdBy($supervisor)->create();
    Conversation::factory()->inDepartment($sales)->create();
    Contact::factory()->create();

    expect(Contact::query()->visibleTo($supervisor)->pluck('id')->all())
        ->toEqualCanonicalizing([$inDepartment->id, $assignedElsewhere->id, $created->id]);
});

test('assigned users only see contacts of their conversations and the ones they created', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $assigned = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create()->contact;
    $created = Contact::factory()->createdBy($agent)->create();
    Conversation::factory()->inDepartment($department)->create();

    expect(Contact::query()->visibleTo($agent)->pluck('id')->all())
        ->toEqualCanonicalizing([$assigned->id, $created->id]);
});

test('users without the permission or with a deactivated account see no contacts', function (Closure $user) {
    $user = $user();
    Contact::factory()->createdBy($user)->create();

    expect(Contact::query()->visibleTo($user)->count())->toBe(0);
})->with([
    'deactivated agent' => [fn () => User::factory()->agent()->inactive()->create()],
    'agent whose role lost contacts.view' => [function () {
        role(DefaultRole::Agent)->revokePermissionTo('contacts.view');

        return User::factory()->agent()->create();
    }],
]);
