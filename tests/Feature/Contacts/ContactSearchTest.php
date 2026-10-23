<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| The contacts search (ContactSearch, shared with the inbox): word prefixes
| of the name through the FULLTEXT index, the beginning of the phone or the
| email, and a name prefix for terms too short for the index. It never
| widens the viewer's scope.
*/

/**
 * Names of the contacts listed for the viewer with the given query.
 *
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function contactNames(object $test, User $viewer, array $query): array
{
    return collect($test->actingAs($viewer)->get(route('contacts.index', $query))->assertOk()->inertiaProps('contacts.data'))
        ->pluck('name')
        ->all();
}

test('the search finds word prefixes with or without accents, phone prefixes and emails, and ignores search operators', function () {
    $admin = User::factory()->superAdministrator()->create();
    Contact::factory()->create(['name' => 'Rocío González', 'phone_e164' => '+525512345678', 'email' => 'rocio@example.com']);
    Contact::factory()->create(['name' => 'Ramón Gonzalez', 'phone_e164' => '+528187654321']);
    Contact::factory()->create(['name' => 'Li Wei', 'phone_e164' => '+14155552671']);
    commitForFullTextSearch();

    expect(contactNames($this, $admin, ['search' => 'gonz', 'sort' => 'name']))->toBe(['Ramón Gonzalez', 'Rocío González'])
        ->and(contactNames($this, $admin, ['search' => 'rocio gonzález']))->toBe(['Rocío González'])
        ->and(contactNames($this, $admin, ['search' => '5512345678']))->toBe(['Rocío González'])
        ->and(contactNames($this, $admin, ['search' => '+52 81']))->toBe(['Ramón Gonzalez'])
        ->and(contactNames($this, $admin, ['search' => 'rocio@exa']))->toBe(['Rocío González'])
        ->and(contactNames($this, $admin, ['search' => 'Li']))->toBe(['Li Wei'])
        ->and(contactNames($this, $admin, ['search' => '"ram*" +(-) @~<>']))->toBe(['Ramón Gonzalez'])
        ->and(contactNames($this, $admin, ['search' => 'zález']))->toBe([]);
});

test('a supervisor never finds contacts outside their departments by name', function () {
    [$own, $other] = Department::factory()->count(2)->create();
    $supervisor = User::factory()->supervisor()->inDepartments($own)->create();
    $visible = Conversation::factory()->inDepartment($own)->create(['contact_id' => Contact::factory()->create(['name' => 'Valeria Visible'])->id]);
    Conversation::factory()->inDepartment($other)->create(['contact_id' => Contact::factory()->create(['name' => 'Valeria Oculta'])->id]);
    commitForFullTextSearch();

    expect(contactNames($this, $supervisor, ['search' => 'valeria']))->toBe([$visible->contact->name]);
});

test('the recent order leaves contacts without interaction last and breaks ties by id', function () {
    $admin = User::factory()->superAdministrator()->create();
    $at = CarbonImmutable::parse('2026-10-20 10:00:00');
    $never = Contact::factory()->create(['name' => 'Sin interacción', 'last_interaction_at' => null]);
    $older = Contact::factory()->create(['name' => 'Anterior', 'last_interaction_at' => $at->subDay()]);
    $first = Contact::factory()->create(['name' => 'Empate 1', 'last_interaction_at' => $at]);
    $second = Contact::factory()->create(['name' => 'Empate 2', 'last_interaction_at' => $at]);

    expect(contactNames($this, $admin, ['sort' => 'recent']))->toBe([$second->name, $first->name, $older->name, $never->name]);
});
