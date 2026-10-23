<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;

/**
 * A departments-scoped user that can see contacts and assign tags.
 */
function tagAssigner(Department $department): User
{
    $role = Role::factory()
        ->scope(DataScope::Departments)
        ->withPermissions([Permission::ViewContacts, Permission::AssignTags])
        ->create();

    return User::factory()->withRole($role)->inDepartments($department)->create();
}

test('tags of a visible contact are replaced and the change is audited', function () {
    $department = Department::factory()->create();
    $assigner = tagAssigner($department);
    $contact = Contact::factory()->create();
    Conversation::factory()->inDepartment($department)->for($contact)->create();
    [$kept, $removed, $added] = [
        Tag::factory()->create(['name' => 'Mayorista']),
        Tag::factory()->create(['name' => 'Prospecto']),
        Tag::factory()->create(['name' => 'VIP']),
    ];
    $contact->tags()->attach([$kept->id, $removed->id]);

    $this->actingAs($assigner)
        ->put(route('contacts.tags.sync', $contact), ['tag_ids' => [$kept->id, $added->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($contact->tags()->pluck('tags.id')->all())->toEqualCanonicalizing([$kept->id, $added->id])
        ->and($contact->tags()->whereKey($added->id)->sole()->pivot->tagged_by_user_id)->toBe($assigner->id);

    $log = AuditLog::query()->where('action', AuditAction::ContactTagsChanged)->sole();

    expect($log)
        ->auditable_id->toBe($contact->id)
        ->user_id->toBe($assigner->id)
        ->and($log->properties)->toBe(['added' => ['VIP'], 'removed' => ['Prospecto']]);
});

test('saving the same tags records nothing', function () {
    $admin = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->create();
    $tag = Tag::factory()->create();
    $contact->tags()->attach($tag);

    $this->actingAs($admin)
        ->put(route('contacts.tags.sync', $contact), ['tag_ids' => [$tag->id]])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', AuditAction::ContactTagsChanged)->exists())->toBeFalse();
});

test('assigning tags requires the assign permission', function () {
    $supervisor = User::factory()->withRole(Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewContacts, Permission::CreateContacts, Permission::ViewTags])->create())->create();
    $contact = Contact::factory()->createdBy($supervisor)->create();
    $tag = Tag::factory()->create();

    $this->actingAs($supervisor)
        ->put(route('contacts.tags.sync', $contact), ['tag_ids' => [$tag->id]])
        ->assertForbidden();

    expect($contact->tags()->count())->toBe(0);
});

test('contacts outside the actor scope are reported as not found', function () {
    $assigner = tagAssigner(Department::factory()->create());
    $contact = Contact::factory()->create();
    Conversation::factory()->inDepartment(Department::factory()->create())->for($contact)->create();
    $tag = Tag::factory()->create();

    $this->actingAs($assigner)
        ->put(route('contacts.tags.sync', $contact), ['tag_ids' => [$tag->id]])
        ->assertNotFound();

    expect($contact->tags()->count())->toBe(0);
});

test('deleted tags cannot be newly assigned but may remain on the contact', function () {
    $admin = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->create();
    $existing = Tag::factory()->inactive()->create(['name' => 'Antiguo']);
    $other = Tag::factory()->inactive()->create(['name' => 'Retirado']);
    $active = Tag::factory()->create(['name' => 'VIP']);
    $contact->tags()->attach($existing);

    $this->actingAs($admin)
        ->put(route('contacts.tags.sync', $contact), ['tag_ids' => [$existing->id, $other->id, $active->id]])
        ->assertSessionHasErrors(['tag_ids' => 'La etiqueta «Retirado» está eliminada y no puede asignarse.']);

    expect($contact->tags()->pluck('tags.id')->all())->toBe([$existing->id]);

    $this->actingAs($admin)
        ->put(route('contacts.tags.sync', $contact), ['tag_ids' => [$existing->id, $active->id]])
        ->assertSessionHasNoErrors();

    expect($contact->tags()->pluck('tags.id')->all())->toEqualCanonicalizing([$existing->id, $active->id]);
});

test('unknown tags are rejected', function () {
    $admin = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->create();

    $this->actingAs($admin)
        ->put(route('contacts.tags.sync', $contact), ['tag_ids' => [999999]])
        ->assertSessionHasErrors(['tag_ids.0']);
});
