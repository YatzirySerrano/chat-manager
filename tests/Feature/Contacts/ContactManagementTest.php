<?php

use App\Enums\AuditAction;
use App\Enums\ContactSource;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A global role that can view, create and edit contacts but not archive them.
 */
function contactEditor(): User
{
    $role = Role::factory()->scope(DataScope::Global)
        ->withPermissions([Permission::ViewContacts, Permission::CreateContacts, Permission::UpdateContacts])
        ->create();

    return User::factory()->withRole($role)->create();
}

test('the contact list hides archived contacts by default', function () {
    $admin = User::factory()->administrator()->create();
    Contact::factory()->create(['name' => 'Ana Activa']);
    Contact::factory()->archived()->create(['name' => 'Arturo Archivado']);

    $this->actingAs($admin)
        ->get(route('contacts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'Ana Activa')
            ->where('filters.status', 'active')
            ->where('can.create', true)
        );

    $this->actingAs($admin)
        ->get(route('contacts.index', ['status' => 'archived']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'Arturo Archivado')
        );

    $this->actingAs($admin)
        ->get(route('contacts.index', ['status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 2));
});

test('users without contacts.view cannot open the contact list', function () {
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewDashboard])->create();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('contacts.index'))
        ->assertForbidden();
});

test('quick search matches name, email and partial phone digits in any format', function (string $search) {
    $admin = User::factory()->administrator()->create();
    Contact::factory()->create(['name' => 'María López', 'phone_e164' => '+525512345678', 'email' => 'maria@example.com']);
    Contact::factory()->create(['name' => 'Pedro Ruiz', 'phone_e164' => '+528187654321', 'email' => 'pedro@example.com']);

    $this->actingAs($admin)
        ->get(route('contacts.index', ['search' => $search]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'María López')
        );
})->with([
    'name' => 'maría',
    'email' => 'maria@',
    'partial phone' => '1234',
    'formatted phone' => '(55) 1234-56',
]);

test('contacts can be filtered by tags, source and origin', function () {
    $admin = User::factory()->administrator()->create();
    [$vip, $wholesale] = Tag::factory()->count(2)->create();

    $tagged = Contact::factory()->create(['name' => 'Etiquetado']);
    $tagged->tags()->attach($vip);
    $whatsapp = Contact::factory()->fromWhatsapp()->create(['name' => 'De WhatsApp']);
    $fromAd = Contact::factory()->create(['name' => 'De anuncio']);
    ContactAttribution::factory()->fromMetaAd()->for($fromAd)->create();
    $manualWebsite = Contact::factory()->create(['name' => 'Desde sitio']);
    ContactAttribution::factory()->for($manualWebsite)->create(['origin_type' => 'website']);

    $names = fn (array $query) => $this->actingAs($admin)->get(route('contacts.index', $query))
        ->assertOk()
        ->inertiaProps('contacts.data');

    expect(collect($names(['tags' => [$vip->id, $wholesale->id]]))->pluck('name')->all())->toBe(['Etiquetado'])
        ->and(collect($names(['source' => ContactSource::Whatsapp->value]))->pluck('name')->all())->toBe(['De WhatsApp'])
        ->and(collect($names(['origin' => 'facebook_ad']))->pluck('name')->all())->toBe(['De anuncio'])
        ->and(collect($names(['origin' => 'website']))->pluck('name')->all())->toBe(['Desde sitio'])
        ->and(collect($names(['origin' => 'unknown']))->pluck('name')->all())
        ->toEqualCanonicalizing(['Etiquetado', 'De WhatsApp']);
});

test('the origin filter follows the winning attribution, not any attribution', function () {
    $admin = User::factory()->administrator()->create();
    $contact = Contact::factory()->create();
    ContactAttribution::factory()->fromMetaAd()->for($contact)->create(['captured_at' => now()->subDay()]);
    ContactAttribution::factory()->for($contact)->create(['origin_type' => 'referral', 'captured_at' => now()]);

    $this->actingAs($admin)
        ->get(route('contacts.index', ['origin' => 'referral']))
        ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 0));

    $this->actingAs($admin)
        ->get(route('contacts.index', ['origin' => 'facebook_ad']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.origin.reliability', 'provider_confirmed')
        );
});

test('pagination is server side and keeps the query string', function () {
    $admin = User::factory()->administrator()->create();
    Contact::factory()->count(17)->create(['name' => 'Cliente Frecuente']);

    $this->actingAs($admin)
        ->get(route('contacts.index', ['search' => 'Frecuente', 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 2)
            ->where('contacts.meta.total', 17)
            ->where('contacts.meta.current_page', 2)
            ->where('contacts.meta.links.1.url', fn (string $url) => str_contains($url, 'search=Frecuente'))
        );
});

test('a contact is created by hand with a normalized phone and audited', function () {
    $editor = contactEditor();

    $response = $this->actingAs($editor)->post(route('contacts.store'), [
        'name' => '  Lucía   Fernández ',
        'phone' => '(55) 1234-5678',
        'email' => 'Lucia@Example.com',
        'custom_fields' => [
            ['label' => 'Número de cliente', 'value' => 'C-1029'],
            ['label' => 'Sucursal', 'value' => '<b>Centro</b>'],
        ],
    ]);

    $contact = Contact::query()->sole();

    $response->assertSessionHasNoErrors()->assertRedirect(route('contacts.show', $contact));

    expect($contact)
        ->name->toBe('Lucía Fernández')
        ->phone_e164->toBe('+525512345678')
        ->email->toBe('lucia@example.com')
        ->source->toBe(ContactSource::Manual)
        ->created_by_user_id->toBe($editor->id)
        ->custom_fields->toBe(['Número de cliente' => 'C-1029', 'Sucursal' => 'Centro']);

    $audit = AuditLog::query()->where('action', AuditAction::ContactCreated)->sole();

    expect($audit->auditable_id)->toBe($contact->id)
        ->and($audit->user_id)->toBe($editor->id);
});

test('contact input is validated', function (array $input, array $errors) {
    $this->actingAs(contactEditor())
        ->post(route('contacts.store'), [...['name' => 'Persona'], ...$input])
        ->assertSessionHasErrors($errors);

    expect(Contact::query()->count())->toBe(0);
})->with([
    'invalid phone' => [['phone' => '12345'], ['phone']],
    'phone with letters' => [['phone' => '55-CALL-NOW'], ['phone']],
    'neither phone nor email' => [['phone' => '', 'email' => ''], ['phone', 'email']],
    'invalid email' => [['email' => 'no-es-correo'], ['email']],
    'blank name after cleaning' => [['name' => '<i></i>', 'phone' => '5512345678'], ['name']],
    'too many custom fields' => [[
        'phone' => '5512345678',
        'custom_fields' => array_map(fn (int $i) => ['label' => "Campo {$i}", 'value' => 'x'], range(1, 11)),
    ], ['custom_fields']],
    'repeated custom field label' => [[
        'phone' => '5512345678',
        'custom_fields' => [['label' => 'Sucursal', 'value' => 'A'], ['label' => ' sucursal ', 'value' => 'B']],
    ], ['custom_fields.1.label']],
    'custom field value that is not text' => [[
        'phone' => '5512345678',
        'custom_fields' => [['label' => 'Datos', 'value' => ['nested' => 'x']]],
    ], ['custom_fields.0.value']],
]);

test('users without contacts.create cannot create contacts', function () {
    $this->actingAs(User::factory()->agent()->create())
        ->post(route('contacts.store'), ['name' => 'Nuevo', 'phone' => '5512345678'])
        ->assertForbidden();

    expect(Contact::query()->count())->toBe(0);
});

test('a contact can be updated and the changes are audited', function () {
    $editor = contactEditor();
    $contact = Contact::factory()->create(['name' => 'Juan', 'phone_e164' => '+525511112222']);

    $this->actingAs($editor)
        ->put(route('contacts.update', $contact), [
            'name' => 'Juan Pérez',
            'phone' => '+52 1 55 3333 4444',
        ])
        ->assertSessionHasNoErrors();

    expect($contact->refresh())
        ->name->toBe('Juan Pérez')
        ->phone_e164->toBe('+525533334444');

    expect(AuditLog::query()->where('action', AuditAction::ContactUpdated)->sole()->properties['changes'])
        ->toMatchArray([
            'name' => ['old' => 'Juan', 'new' => 'Juan Pérez'],
            'phone_e164' => ['old' => '+525511112222', 'new' => '+525533334444'],
        ]);
});

test('users without contacts.update cannot edit a visible contact', function () {
    $agent = User::factory()->agent()->create();
    $contact = Contact::factory()->createdBy($agent)->create(['name' => 'Original']);

    $this->actingAs($agent)
        ->put(route('contacts.update', $contact), ['name' => 'Cambiado', 'phone' => '5512345678'])
        ->assertForbidden();

    expect($contact->refresh()->name)->toBe('Original');
});

test('the profile shows identity, custom fields and the actor capabilities', function () {
    $admin = User::factory()->administrator()->create();
    $contact = Contact::factory()->fromWhatsapp('5215512345678')->create([
        'name' => 'Rosa',
        'profile_name' => 'Rosa M.',
        'custom_fields' => ['Sucursal' => 'Norte'],
    ]);

    $this->actingAs($admin)
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Show')
            ->where('contact.name', 'Rosa')
            ->where('contact.profile_name', 'Rosa M.')
            ->where('contact.identities.0.channel', 'whatsapp')
            ->where('contact.identities.0.external_id', '5215512345678')
            ->where('contact.custom_fields.Sucursal', 'Norte')
            ->where('origin.current', null)
            ->has('notes', 0)
            ->has('conversations', 0)
            ->where('can.update', true)
            ->where('can.archive', true)
            ->where('can.manageNotes', true)
            ->where('can.classifyOrigin', true)
        );
});
