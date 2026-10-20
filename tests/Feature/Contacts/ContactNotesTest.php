<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  list<Permission>  $permissions
 */
function userWithContactPermissions(array $permissions, DataScope $scope = DataScope::Global): User
{
    $role = Role::factory()->scope($scope)->withPermissions([Permission::ViewContacts, ...$permissions])->create();

    return User::factory()->withRole($role)->create();
}

test('users with contacts.notes add private plain-text notes and see them newest first', function () {
    $user = userWithContactPermissions([Permission::ManageContactNotes]);
    $contact = Contact::factory()->create();
    ContactNote::factory()->for($contact)->create(['body' => 'Nota antigua', 'created_at' => now()->subDay()]);

    $this->actingAs($user)
        ->post(route('contacts.notes.store', $contact), ['body' => "  Prefiere llamadas\r\npor la tarde <script>x</script>  "])
        ->assertSessionHasNoErrors();

    $note = $contact->notes()->first();

    expect($note)
        ->body->toBe("Prefiere llamadas\npor la tarde <script>x</script>")
        ->author_user_id->toBe($user->id);

    $audit = AuditLog::query()->where('action', AuditAction::ContactNoteAdded)->sole();

    expect($audit->properties)->toBe(['note_id' => $note->id, 'length' => mb_strlen($note->body)]);

    $this->actingAs($user)
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->has('notes', 2)
            ->where('notes.0.body', $note->body)
            ->where('notes.0.author.name', $user->name)
            ->where('notes.1.body', 'Nota antigua')
            ->where('can.manageNotes', true)
        );
});

test('notes are hidden from and cannot be added by users without contacts.notes', function () {
    $user = userWithContactPermissions([]);
    $contact = Contact::factory()->create();
    ContactNote::factory()->for($contact)->create(['body' => 'Confidencial']);

    $this->actingAs($user)
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notes', null)
            ->where('can.manageNotes', false)
        );

    $this->actingAs($user)
        ->post(route('contacts.notes.store', $contact), ['body' => 'Intento'])
        ->assertForbidden();

    expect($contact->notes()->count())->toBe(1);
});

test('notes cannot be added to contacts outside the actor scope', function () {
    $user = userWithContactPermissions([Permission::ManageContactNotes], DataScope::Assigned);
    $contact = Contact::factory()->create();

    $this->actingAs($user)
        ->post(route('contacts.notes.store', $contact), ['body' => 'Intento'])
        ->assertNotFound();

    expect($contact->notes()->count())->toBe(0);
});

test('note bodies are required and limited to 2000 characters', function (string $body) {
    $contact = Contact::factory()->create();

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('contacts.notes.store', $contact), ['body' => $body])
        ->assertSessionHasErrors('body');
})->with([
    'empty' => '',
    'too long' => str_repeat('a', 2001),
]);
