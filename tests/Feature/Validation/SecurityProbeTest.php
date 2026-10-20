<?php

use App\Actions\Connections\CreateConnection;
use App\Actions\Tags\CreateTag;
use App\Enums\ColorSwatch;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Security probes for Increment A: adversarial cases not covered by the module
| tests, plus regressions for the defects found during the audit.
*/

/**
 * A departments-scoped user holding the given permissions.
 *
 * @param  list<Permission>  $permissions
 */
function securityProbeScopedUser(Department $department, array $permissions): User
{
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions($permissions)->create();

    return User::factory()->withRole($role)->inDepartments($department)->create();
}

/*
| Existence leaks
*/

test('a hidden contact is indistinguishable from a nonexistent one in json 404 responses', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $user = securityProbeScopedUser($own, [Permission::ViewContacts, Permission::ManageContactNotes]);
    $hidden = Conversation::factory()->inDepartment($other)->create()->contact;
    $missingId = Contact::query()->max('id') + 1000;

    $hiddenResponse = $this->actingAs($user)->getJson(route('contacts.show', $hidden));
    $missingResponse = $this->actingAs($user)->getJson(route('contacts.show', $missingId));

    expect($hiddenResponse->status())->toBe(404)
        ->and($missingResponse->status())->toBe(404)
        ->and($missingResponse->json('message'))->toBe($hiddenResponse->json('message'));

    $hiddenNote = $this->actingAs($user)->postJson(route('contacts.notes.store', $hidden), ['body' => 'x']);
    $missingNote = $this->actingAs($user)->postJson(route('contacts.notes.store', $missingId), ['body' => 'x']);

    expect($missingNote->json('message'))->toBe($hiddenNote->json('message'));
});

test('the contact search never matches hidden contacts by phone digits, name or email', function (string $search) {
    [$own, $other] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();
    $hidden = Contact::factory()->create(['name' => 'Oculta Zeta', 'phone_e164' => '+525598761234', 'email' => 'oculta@example.com']);
    Conversation::factory()->inDepartment($other)->create(['contact_id' => $hidden->id]);

    $this->actingAs($supervisor)
        ->get(route('contacts.index', ['search' => $search, 'status' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 0));
})->with(['98761234', 'Oculta', 'oculta@example.com']);

test('tag usage counts only include contacts and conversations in the viewer scope', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();
    $tag = Tag::factory()->create();
    $visible = Conversation::factory()->inDepartment($own)->create();
    $hidden = Conversation::factory()->inDepartment($other)->create();
    $tag->contacts()->attach([$visible->contact_id, $hidden->contact_id]);
    $tag->conversations()->attach([$visible->id, $hidden->id]);

    $this->actingAs($supervisor)
        ->get(route('tags.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tags.data.0.contacts_count', 1)
            ->where('tags.data.0.conversations_count', 1));
});

test('the quick replies list never names departments the viewer does not belong to', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $other->update(['name' => 'Ventas Secretas']);
    QuickReply::factory()->forDepartments($own, $other)->create(['shortcut' => 'compartida']);
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();

    $response = $this->actingAs($supervisor)->get(route('quick-replies.index'))->assertOk();

    expect(json_encode($response->inertiaProps()))->not->toContain('Ventas Secretas');
});

/*
| Validation gaps
*/

test('a note made only of control characters is rejected instead of stored empty', function () {
    $admin = User::factory()->administrator()->create();
    $contact = Contact::factory()->create();

    $this->actingAs($admin)
        ->post(route('contacts.notes.store', $contact), ['body' => "\u{0007}\u{0008}\u{001B}"])
        ->assertSessionHasErrors('body');

    expect(ContactNote::query()->where('body', '')->exists())->toBeFalse();
});

test('markup typed in contact names and custom fields is stored as plain text', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->post(route('contacts.store'), [
        'name' => '<img src=x onerror=alert(1)>Juan',
        'phone' => '5511112222',
        'custom_fields' => [['label' => '<b>Empresa</b>', 'value' => '<script>alert(1)</script>Acme']],
    ])->assertSessionHasNoErrors();

    $contact = Contact::query()->sole();

    expect($contact->name)->toBe('Juan')
        ->and($contact->custom_fields)->toBe(['Empresa' => 'alert(1)Acme']);
});

/*
| Mass assignment of protected fields
*/

test('protected contact fields cannot be mass assigned on create or update', function () {
    $admin = User::factory()->administrator()->create();
    $other = User::factory()->administrator()->create();

    $this->actingAs($admin)->post(route('contacts.store'), [
        'name' => 'Ana',
        'phone' => '5511113333',
        'status' => 'archived',
        'source' => 'whatsapp',
        'created_by_user_id' => $other->id,
        'profile_name' => 'Falso',
        'blocked_at' => now()->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $contact = Contact::query()->sole();

    expect($contact->status)->toBe(ContactStatus::Active)
        ->and($contact->source)->toBe(ContactSource::Manual)
        ->and($contact->created_by_user_id)->toBe($admin->id)
        ->and($contact->profile_name)->toBeNull()
        ->and($contact->blocked_at)->toBeNull();

    $this->actingAs($admin)->put(route('contacts.update', $contact), [
        'name' => 'Ana',
        'phone' => '5511113333',
        'status' => 'archived',
        'created_by_user_id' => $other->id,
    ])->assertSessionHasNoErrors();

    expect($contact->refresh()->status)->toBe(ContactStatus::Active)
        ->and($contact->created_by_user_id)->toBe($admin->id);
});

test('protected connection, tag and quick reply fields cannot be mass assigned', function () {
    $admin = User::factory()->administrator()->create();
    $other = User::factory()->administrator()->create();

    $this->actingAs($admin)->post(route('connections.store'), [
        'name' => 'Principal',
        'department_ids' => [],
        'is_active' => true,
        'created_by_user_id' => $other->id,
        'credentials_verified_at' => now()->toDateTimeString(),
        'webhook_verified_at' => now()->toDateTimeString(),
        'last_inbound_at' => now()->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($admin)->post(route('tags.store'), [
        'name' => 'VIP', 'color' => ColorSwatch::Slate->value, 'is_active' => false, 'slug' => 'otro', 'created_by_user_id' => $other->id,
    ])->assertSessionHasNoErrors();

    $this->actingAs($admin)->post(route('quick-replies.store'), [
        'title' => 'Hola', 'shortcut' => 'hola', 'body' => 'Hola', 'is_global' => true,
        'is_active' => false, 'created_by_user_id' => $other->id,
    ])->assertSessionHasNoErrors();

    $connection = Connection::query()->sole();
    $tag = Tag::query()->sole();
    $reply = QuickReply::query()->sole();

    expect($connection->is_active)->toBeFalse()
        ->and($connection->created_by_user_id)->toBe($admin->id)
        ->and($connection->credentials_verified_at)->toBeNull()
        ->and($connection->webhook_verified_at)->toBeNull()
        ->and($connection->last_inbound_at)->toBeNull()
        ->and($tag->is_active)->toBeTrue()
        ->and($tag->slug)->toBe('vip')
        ->and($tag->created_by_user_id)->toBe($admin->id)
        ->and($reply->is_active)->toBeTrue()
        ->and($reply->created_by_user_id)->toBe($admin->id);
});

test('department managers cannot attach a quick reply to a department they do not belong to on update', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $manager = securityProbeScopedUser($own, [Permission::ManageQuickReplies]);
    $reply = QuickReply::factory()->forDepartments($own)->create(['shortcut' => 'mia']);

    $this->actingAs($manager)->put(route('quick-replies.update', $reply), [
        'title' => 'Mia', 'shortcut' => 'mia', 'body' => 'Hola', 'is_global' => false,
        'department_ids' => [$own->id, $other->id],
    ])->assertSessionHasErrors('department_ids');

    $this->actingAs($manager)->put(route('quick-replies.update', $reply), [
        'title' => 'Mia', 'shortcut' => 'mia', 'body' => 'Hola', 'is_global' => true,
    ])->assertSessionHasErrors('is_global');

    expect($reply->refresh()->is_global)->toBeFalse()
        ->and($reply->departments()->pluck('departments.id')->all())->toBe([$own->id]);
});

/*
| Secrets
*/

test('connection secrets are not flashed as old input nor written to the audit log when updating', function () {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->create();
    $secrets = [
        'access_token' => 'EAAGupdatedSecretToken1234567890',
        'app_secret' => 'updated-app-secret-value-123',
        'webhook_verify_token' => 'updated-verify-token-value-123',
    ];

    $this->actingAs($admin)
        ->from(route('connections.show', $connection))
        ->put(route('connections.update', $connection), ['name' => str_repeat('x', 200), 'department_ids' => [], ...$secrets])
        ->assertSessionHasErrors('name');

    expect(json_encode(session()->all()))->not->toContain($secrets['access_token'])
        ->not->toContain($secrets['app_secret'])
        ->not->toContain($secrets['webhook_verify_token']);

    $this->actingAs($admin)
        ->put(route('connections.update', $connection), ['name' => 'Renombrada', 'department_ids' => [], ...$secrets])
        ->assertSessionHasNoErrors();

    $page = $this->actingAs($admin)->get(route('connections.show', $connection))->assertOk()->getContent();
    $audit = AuditLog::query()->get()->toJson();

    foreach ($secrets as $value) {
        expect($page)->not->toContain($value)
            ->and($audit)->not->toContain($value);
    }
});

/*
| Revocation in open sessions
*/

test('revoking contacts.view from a role closes contact profiles in open sessions', function () {
    $department = Department::factory()->create();
    $supervisor = User::factory()->supervisor()->inDepartments($department)->create();
    $contact = Conversation::factory()->inDepartment($department)->create()->contact;

    $this->actingAs($supervisor)->get(route('contacts.show', $contact))->assertOk();

    role(DefaultRole::Supervisor)->revokePermissionTo(Permission::ViewContacts->value);

    $this->actingAs($supervisor->refresh())->get(route('contacts.show', $contact))->assertForbidden();
    $this->actingAs($supervisor)->get(route('contacts.index'))->assertForbidden();
});

test('leaving a department removes access to its contacts and quick replies in open sessions', function () {
    $department = Department::factory()->create();
    $user = securityProbeScopedUser($department, [Permission::ViewContacts, Permission::UpdateContacts, Permission::ManageQuickReplies]);
    $contact = Conversation::factory()->inDepartment($department)->create()->contact;
    $reply = QuickReply::factory()->forDepartments($department)->create();

    $this->actingAs($user)->get(route('contacts.show', $contact))->assertOk();

    $user->departments()->detach($department);

    $this->actingAs($user->refresh())->get(route('contacts.show', $contact))->assertNotFound();
    $this->actingAs($user)->put(route('contacts.update', $contact), ['name' => 'X', 'phone' => '5512345678'])->assertNotFound();
    $this->actingAs($user)->patch(route('quick-replies.status.update', $reply), ['is_active' => false])->assertForbidden();
    $this->actingAs($user)->delete(route('quick-replies.destroy', $reply))->assertForbidden();

    expect($reply->refresh()->is_active)->toBeTrue();
});

test('narrowing a role scope or revoking tags.manage stops global-only operations in open sessions', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ManageConnections, Permission::ManageTags])->create();
    $user = User::factory()->withRole($role)->create();
    $connection = Connection::factory()->inactive()->create();
    $tag = Tag::factory()->create();

    $this->actingAs($user)->patch(route('tags.status.update', $tag), ['is_active' => false])->assertSessionHasNoErrors();

    $role->revokePermissionTo(Permission::ManageTags->value);
    $this->actingAs($user->refresh())->patch(route('tags.status.update', $tag), ['is_active' => true])->assertForbidden();

    $role->forceFill(['scope' => DataScope::Departments])->save();
    $this->actingAs($user->refresh())->patch(route('connections.status.update', $connection), ['is_active' => true])->assertForbidden();
    $this->actingAs($user)->post(route('connections.verify', $connection))->assertForbidden();

    expect($tag->refresh()->is_active)->toBeFalse()
        ->and($connection->refresh()->is_active)->toBe(false);
});

/*
| CSRF
*/

test('state-changing routes of the new modules reject requests without a CSRF token', function (Closure $request) {
    $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $admin = User::factory()->administrator()->create();

    $request($this->actingAs($admin))->assertStatus(419);
})->with([
    'contact create' => [fn ($t) => $t->post(route('contacts.store'), ['name' => 'A', 'phone' => '5512345678'])],
    'contact note' => [fn ($t) => $t->post(route('contacts.notes.store', Contact::factory()->create()), ['body' => 'x'])],
    'contact status' => [fn ($t) => $t->patch(route('contacts.status.update', Contact::factory()->create()), ['status' => 'archived'])],
    'contact tags' => [fn ($t) => $t->put(route('contacts.tags.sync', Contact::factory()->create()), ['tag_ids' => []])],
    'tag create' => [fn ($t) => $t->post(route('tags.store'), ['name' => 'x', 'color' => 'slate'])],
    'quick reply delete' => [fn ($t) => $t->delete(route('quick-replies.destroy', QuickReply::factory()->create()))],
    'connection create' => [fn ($t) => $t->post(route('connections.store'), ['name' => 'x', 'department_ids' => []])],
    'connection verify' => [fn ($t) => $t->post(route('connections.verify', Connection::factory()->create()))],
    'department hours' => [fn ($t) => $t->put(route('departments.hours.update', Department::factory()->create()), ['uses_custom_hours' => false])],
]);

/*
| Uniqueness under races (validation passed, then the row appeared)
*/

test('a tag name taken after validation answers a validation error instead of a server error', function () {
    $admin = User::factory()->administrator()->create();
    Tag::factory()->create(['name' => 'Repetida']);

    expect(fn () => app(CreateTag::class)->handle(['name' => 'repetida', 'color' => ColorSwatch::Slate], $admin))
        ->toThrow(ValidationException::class);
});

test('a phone number id taken after validation answers a validation error instead of a server error', function () {
    $admin = User::factory()->administrator()->create();
    Connection::factory()->create(['phone_number_id' => '123456789']);

    expect(fn () => app(CreateConnection::class)->handle([
        'attributes' => ['name' => 'Dup', 'phone_number_id' => '123456789'],
        'secrets' => [],
        'department_ids' => [],
    ], $admin))->toThrow(ValidationException::class);
});

/*
| Repeated requests
*/

test('archiving the same contact twice records a single audit entry', function () {
    $admin = User::factory()->administrator()->create();
    $contact = Contact::factory()->create();

    $this->actingAs($admin)->patch(route('contacts.status.update', $contact), ['status' => 'archived'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->patch(route('contacts.status.update', $contact), ['status' => 'archived'])->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('auditable_type', $contact->getMorphClass())->where('auditable_id', $contact->id)->count())->toBe(1);
});
