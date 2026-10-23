<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Validation of the contacts and tags modules of Increment A: cases not covered
| by the module tests, plus regressions for the defects found during QA.
*/

/**
 * @param  list<Permission>  $permissions
 */
function probeContactsUser(array $permissions, DataScope $scope, ?Department $department = null): User
{
    $role = Role::factory()->scope($scope)->withPermissions([Permission::ViewContacts, ...$permissions])->create();
    $factory = User::factory()->withRole($role);

    return ($department === null ? $factory : $factory->inDepartments($department))->create();
}

test('mexican numbers written with or without the legacy 1 and in any separator style normalize to the same E.164', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBe('+525512345678');
})->with([
    'national' => '5512345678',
    'national with dashes' => '55-1234-5678',
    'plus and spaces' => '+52 55 1234 5678',
    'plus legacy 1' => '+52 1 55 1234 5678',
    '00 prefix legacy 1' => '0052 1 (55) 1234-5678',
    'parentheses around country code' => '(+52) 55 1234 5678',
    'surrounding whitespace' => "  55 1234 5678\u{00A0}",
]);

test('ambiguous mexican inputs without international prefix are rejected', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBeNull();
})->with([
    'country code without plus' => '52 55 1234 5678',
    'national with legacy 1' => '1 55 1234 5678',
    'old 044 mobile prefix' => '044 55 1234 5678',
    'eleven national digits' => '55 1234 56789',
    'en dash separator' => '55–1234–5678',
]);

test('+52 numbers whose national part is not 10 digits are rejected', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBeNull();
})->with([
    'nine national digits' => '+52 55 1234 567',
    'eleven national digits' => '+52 55 1234 56789',
    'legacy 1 with nine digits' => '+52 1 55 1234 567',
]);

test('a contact can be created with only an email and optional fields stored as null', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('contacts.store'), ['name' => 'Solo Correo', 'phone' => '', 'email' => '  Solo@Example.COM ', 'custom_fields' => []])
        ->assertSessionHasNoErrors();

    expect(Contact::query()->sole())
        ->phone_e164->toBeNull()
        ->email->toBe('solo@example.com')
        ->custom_fields->toBeNull();
});

test('editing clears optional data when it is sent empty', function () {
    $admin = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->create([
        'phone_e164' => '+525512345678',
        'email' => 'a@example.com',
        'custom_fields' => ['Sucursal' => 'Norte'],
    ]);

    $this->actingAs($admin)
        ->put(route('contacts.update', $contact), ['name' => $contact->name, 'phone' => '5512345678', 'email' => ''])
        ->assertSessionHasNoErrors();

    expect($contact->refresh())
        ->email->toBeNull()
        ->custom_fields->toBeNull()
        ->phone_e164->toBe('+525512345678');
});

test('the same phone typed in a different format is detected as duplicate, including archived contacts', function (string $typed) {
    $admin = User::factory()->superAdministrator()->create();
    $existing = Contact::factory()->archived()->create(['phone_e164' => '+525512345678']);

    $this->actingAs($admin)
        ->post(route('contacts.store'), ['name' => 'Nuevo', 'phone' => $typed])
        ->assertSessionHasErrors(['phone', 'confirm_duplicate']);

    expect(session(SessionKey::FLASH_DATA.'.duplicates.contacts.0'))->toMatchArray(['id' => $existing->id, 'status' => 'archived'])
        ->and(Contact::query()->count())->toBe(1);
})->with([
    'legacy 1' => '+52 1 55 1234 5678',
    '00 prefix' => '0052 55 1234 5678',
    'national with parentheses' => '(55) 1234-5678',
]);

test('re-saving a contact with its own phone in another format does not ask for duplicate confirmation', function () {
    $admin = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->create(['phone_e164' => '+525512345678']);

    $this->actingAs($admin)
        ->put(route('contacts.update', $contact), ['name' => 'Renombrado', 'phone' => '+52 1 55 1234 5678'])
        ->assertSessionHasNoErrors();

    expect($contact->refresh()->name)->toBe('Renombrado');
});

test('a provider identity can belong to only one contact per channel', function () {
    $identity = ContactIdentity::factory()->create(['external_id' => '5215512345678']);

    expect(fn () => ContactIdentity::factory()->create([
        'channel' => $identity->channel,
        'external_id' => '5215512345678',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('searching the full number in WhatsApp or 00 format finds the contact', function (string $search) {
    $admin = User::factory()->superAdministrator()->create();
    Contact::factory()->create(['name' => 'Rosa', 'phone_e164' => '+525512345678']);

    $this->actingAs($admin)
        ->get(route('contacts.index', ['search' => $search]))
        ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 1));
})->with([
    'wa_id format' => '5215512345678',
    'legacy 1 formatted' => '+52 1 55 1234 5678',
    '00 prefix' => '0052 55 1234 5678',
]);

test('deleted contacts are hidden from the default search and reappear after restore', function () {
    $admin = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->archived()->create(['name' => 'Zoe Archivada']);
    commitForFullTextSearch();

    $search = fn () => $this->actingAs($admin)->get(route('contacts.index', ['search' => 'Zoe']))->inertiaProps('contacts.data');

    expect($search())->toHaveCount(0);

    $this->actingAs($admin)->patch(route('contacts.restore', $contact))->assertSessionHasNoErrors();

    expect($search())->toHaveCount(1);
});

test('deleted contacts refuse profile edits until restored but keep accepting notes and tags', function () {
    $admin = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->archived()->create(['name' => 'Original']);
    $tag = Tag::factory()->create();

    $this->actingAs($admin)->put(route('contacts.update', $contact), ['name' => 'Editado', 'phone' => $contact->phone_e164])->assertForbidden();
    $this->actingAs($admin)->post(route('contacts.notes.store', $contact), ['body' => 'Nota'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->put(route('contacts.tags.sync', $contact), ['tag_ids' => [$tag->id]])->assertSessionHasNoErrors();

    expect($contact->refresh())->name->toBe('Original')->isDeleted()->toBeTrue()
        ->and($contact->notes()->count())->toBe(1)
        ->and($contact->tags()->count())->toBe(1);
});

test('a note made only of control characters is rejected', function () {
    $admin = User::factory()->superAdministrator()->create();
    $contact = Contact::factory()->create();

    $this->actingAs($admin)
        ->post(route('contacts.notes.store', $contact), ['body' => "\u{0007}\u{0008}"])
        ->assertSessionHasErrors('body');

    expect($contact->notes()->count())->toBe(0);
});

test('out of range or malformed pages answer an empty page instead of an error', function (mixed $page) {
    $admin = User::factory()->superAdministrator()->create();
    Contact::factory()->count(3)->create();

    $response = $this->actingAs($admin)->get(route('contacts.index', ['page' => $page]))->assertOk();

    expect($response->inertiaProps('contacts.meta.per_page'))->toBe(15);
})->with([999, 'abc', -1]);

test('outside the scope every contact endpoint answers exactly like a nonexistent contact', function (string $method, string $route, array $data) {
    [$own, $other] = Department::factory()->count(2)->create();
    $agent = User::factory()->agent()->inDepartments($own)->create();
    $outside = Conversation::factory()->inDepartment($other)->create()->contact;
    $missingId = $outside->id + 10000;

    $outsideResponse = $this->actingAs($agent)->{$method}(route($route, $outside), $data);
    $missingResponse = $this->actingAs($agent)->{$method}(route($route, $missingId), $data);

    expect($outsideResponse->status())->toBe(404)->toBe($missingResponse->status());
})->with([
    'show' => ['get', 'contacts.show', []],
    'update' => ['put', 'contacts.update', ['name' => 'X', 'phone' => '5512345678']],
    'delete' => ['delete', 'contacts.destroy', []],
    'restore' => ['patch', 'contacts.restore', []],
    'notes' => ['post', 'contacts.notes.store', ['body' => 'x']],
    'origin' => ['post', 'contacts.attributions.store', ['origin_type' => 'website']],
    'tags' => ['put', 'contacts.tags.sync', ['tag_ids' => []]],
]);

test('a departments user without contacts.update gets 403 inside the scope and 404 outside', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();
    $inside = Conversation::factory()->inDepartment($own)->create()->contact;
    $outside = Conversation::factory()->inDepartment($other)->create()->contact;

    $this->actingAs($supervisor)->put(route('contacts.update', $inside), ['name' => 'X', 'phone' => '5512345678'])->assertForbidden();
    $this->actingAs($supervisor)->put(route('contacts.update', $outside), ['name' => 'X', 'phone' => '5512345678'])->assertNotFound();
});

test('tag sync accepts an empty list to remove every tag and usage counts follow assign and unassign', function () {
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'VIP']);
    $other = Tag::factory()->create(['name' => 'Mayorista']);
    $contact = Contact::factory()->create();
    $archived = Contact::factory()->archived()->create();
    $archived->tags()->attach($tag);

    $count = fn (): int => collect($this->actingAs($admin)->get(route('tags.index'))->inertiaProps('tags.data'))
        ->firstWhere('name', 'VIP')['contacts_count'];

    expect($count())->toBe(1);

    $this->actingAs($admin)->put(route('contacts.tags.sync', $contact), ['tag_ids' => [$tag->id, $other->id]])->assertSessionHasNoErrors();
    expect($count())->toBe(2)->and($contact->tags()->count())->toBe(2);

    $this->actingAs($admin)->put(route('contacts.tags.sync', $contact), ['tag_ids' => []])->assertSessionHasNoErrors();
    expect($count())->toBe(1)->and($contact->tags()->count())->toBe(0);
});

test('tag usage counts only include contacts within the actor scope', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();
    $tag = Tag::factory()->create();
    $tag->contacts()->attach([
        Conversation::factory()->inDepartment($own)->create()->contact_id,
        Conversation::factory()->inDepartment($other)->create()->contact_id,
    ]);

    $this->actingAs($supervisor)
        ->get(route('tags.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tags.data.0.contacts_count', 1));
});

test('tag edits only accept palette or hex colors and valid names', function (array $input, string $error) {
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'VIP', 'color' => 'blue']);
    Tag::factory()->create(['name' => 'Mayorista']);

    $this->actingAs($admin)
        ->put(route('tags.update', $tag), [...['name' => 'VIP', 'color' => 'rose'], ...$input])
        ->assertSessionHasErrors($error);

    expect($tag->refresh())->name->toBe('VIP')->color->value->toBe('blue');
})->with([
    'shorthand hex color' => [['color' => '#f00'], 'color'],
    'unknown swatch' => [['color' => 'neon'], 'color'],
    'missing color' => [['color' => ''], 'color'],
    'name of another tag in other case' => [['name' => 'MAYORISTA'], 'name'],
    'name too long' => [['name' => str_repeat('a', 61)], 'name'],
    'blank name' => [['name' => '   '], 'name'],
]);

test('a long tag name with symbols that expand in the slug is saved without a server error', function () {
    $admin = User::factory()->superAdministrator()->create();
    $name = trim(str_repeat('@ ', 30));

    $response = $this->actingAs($admin)->post(route('tags.store'), ['name' => $name, 'color' => 'blue']);

    expect($response->status())->toBeLessThan(500);
});
