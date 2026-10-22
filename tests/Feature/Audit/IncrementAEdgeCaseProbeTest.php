<?php

use App\Models\Connection;
use App\Models\Contact;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;

/*
| Malformed but plausible input must answer with a validation error, never a 500.
*/

test('malformed input never produces a server error', function (Closure $send) {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();

    $response = $send($this->actingAs($admin)->from('/dashboard'), $department);

    expect($response->status())->toBeLessThan(500);
})->with([
    'hours day as string' => [fn ($t, $d) => $t->put(route('departments.hours.update', $d), ['uses_custom_hours' => true, 'business_hours' => ['days' => ['monday' => 'x']]])],
    'hours days missing' => [fn ($t, $d) => $t->put(route('departments.hours.update', $d), ['uses_custom_hours' => true, 'business_hours' => 'x'])],
    'hours start as array' => [fn ($t, $d) => $t->put(route('departments.hours.update', $d), ['uses_custom_hours' => true, 'business_hours' => ['days' => ['monday' => ['open' => true, 'start' => ['a'], 'end' => '10:00']]]])],
    'welcome message array' => [fn ($t, $d) => $t->put(route('departments.operations.update', $d), ['welcome_message' => ['a']])],
    'contact custom fields string' => [fn ($t, $d) => $t->post(route('contacts.store'), ['name' => 'A', 'phone' => '5512345678', 'custom_fields' => 'x'])],
    'contact custom field scalar items' => [fn ($t, $d) => $t->post(route('contacts.store'), ['name' => 'A', 'phone' => '5512345678', 'custom_fields' => ['x', 'y']])],
    'contact custom field label array' => [fn ($t, $d) => $t->post(route('contacts.store'), ['name' => 'A', 'phone' => '5512345678', 'custom_fields' => [['label' => ['x'], 'value' => 'y']]])],
    'contact name array' => [fn ($t, $d) => $t->post(route('contacts.store'), ['name' => ['A'], 'phone' => '5512345678'])],
    'contact phone array' => [fn ($t, $d) => $t->post(route('contacts.store'), ['name' => 'A', 'phone' => ['5512345678']])],
    'contact email array' => [fn ($t, $d) => $t->post(route('contacts.store'), ['name' => 'A', 'email' => ['a@b.c']])],
    'contact name only tags' => [fn ($t, $d) => $t->post(route('contacts.store'), ['name' => '<b></b>', 'phone' => '5512345678'])],
    'contact index tags string' => [fn ($t, $d) => $t->get(route('contacts.index', ['tags' => 'x']))],
    'contact index search array' => [fn ($t, $d) => $t->get(route('contacts.index', ['search' => ['x']]))],
    'note body array' => [fn ($t, $d) => $t->post(route('contacts.notes.store', Contact::factory()->create()), ['body' => ['x']])],
    'origin url javascript' => [fn ($t, $d) => $t->post(route('contacts.attributions.store', Contact::factory()->create()), ['origin_type' => 'website', 'source_url' => 'javascript:alert(1)'])],
    'tag sync ids string' => [fn ($t, $d) => $t->put(route('contacts.tags.sync', Contact::factory()->create()), ['tag_ids' => 'x'])],
    'tag sync missing' => [fn ($t, $d) => $t->put(route('contacts.tags.sync', Contact::factory()->create()), [])],
    'tag name array' => [fn ($t, $d) => $t->post(route('tags.store'), ['name' => ['x'], 'color' => 'emerald'])],
    'tag name only symbols' => [fn ($t, $d) => $t->post(route('tags.store'), ['name' => '+++', 'color' => 'emerald'])],
    'quick reply departments string' => [fn ($t, $d) => $t->post(route('quick-replies.store'), ['title' => 'a', 'shortcut' => 'a', 'body' => 'b', 'is_global' => false, 'department_ids' => 'x'])],
    'quick reply global with bad ids' => [fn ($t, $d) => $t->post(route('quick-replies.store'), ['title' => 'a', 'shortcut' => 'a', 'body' => 'b', 'is_global' => true, 'department_ids' => ['x']])],
    'quick reply shortcut array' => [fn ($t, $d) => $t->post(route('quick-replies.store'), ['title' => 'a', 'shortcut' => ['a'], 'body' => 'b', 'is_global' => true])],
    'quick reply body nested braces' => [fn ($t, $d) => $t->post(route('quick-replies.store'), ['title' => 'a', 'shortcut' => 'a', 'body' => '{{contacto.nombre}}', 'is_global' => true])],
    'available q array' => [fn ($t, $d) => $t->getJson(route('quick-replies.available', ['q' => ['x']]))],
    'available contact id string' => [fn ($t, $d) => $t->getJson(route('quick-replies.available', ['contact_id' => 'abc']))],
    'connection departments string' => [fn ($t, $d) => $t->post(route('connections.store'), ['name' => 'a', 'department_ids' => 'x'])],
    'connection default without departments' => [fn ($t, $d) => $t->post(route('connections.store'), ['name' => 'a', 'department_ids' => [], 'default_department_id' => $d->id])],
    'connection token array' => [fn ($t, $d) => $t->post(route('connections.store'), ['name' => 'a', 'department_ids' => [], 'access_token' => ['x']])],
    'connection message enabled without text' => [fn ($t, $d) => $t->post(route('connections.store'), ['name' => 'a', 'department_ids' => [], 'welcome_enabled' => true])],
    'connection reopen too big' => [fn ($t, $d) => $t->post(route('connections.store'), ['name' => 'a', 'department_ids' => [], 'reopen_window_minutes' => 70000])],
    'connection index department string' => [fn ($t, $d) => $t->get(route('connections.index', ['department' => 'x']))],
    'verify without credentials' => [fn ($t, $d) => $t->post(route('connections.verify', Connection::factory()->unconfigured()->create()))],
    'activate without credentials' => [fn ($t, $d) => $t->patch(route('connections.status.update', Connection::factory()->unconfigured()->create()), ['is_active' => true])],
    'restore a tag that is not deleted' => [fn ($t, $d) => $t->patch(route('tags.restore', Tag::factory()->create()), [])],
    'quick reply delete' => [fn ($t, $d) => $t->delete(route('quick-replies.destroy', QuickReply::factory()->forDepartments($d)->create()))],
]);

test('creating two tags whose names produce the same slug works', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)->post(route('tags.store'), ['name' => 'C++', 'color' => 'emerald'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('tags.store'), ['name' => 'C#', 'color' => 'emerald'])->assertSessionHasNoErrors();

    expect(Tag::query()->pluck('slug')->all())->toEqualCanonicalizing(['c', 'c-2']);
});

test('tag names differing only by accents are both accepted or a clear message is shown', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)->post(route('tags.store'), ['name' => 'Café', 'color' => 'emerald'])->assertSessionHasNoErrors();
    $response = $this->actingAs($admin)->post(route('tags.store'), ['name' => 'Cafe', 'color' => 'emerald']);

    expect($response->status())->toBeLessThan(500);
});
