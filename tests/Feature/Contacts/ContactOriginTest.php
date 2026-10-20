<?php

use App\Enums\AttributionReliability;
use App\Enums\AuditAction;
use App\Enums\ContactOriginType;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a contact without attribution shows an unidentified origin', function () {
    $contact = Contact::factory()->fromWhatsapp()->create();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('origin.current', null)
            ->has('origin.history', 0)
        );
});

test('a Meta ad referral is shown as confirmed by Meta with its ad details', function () {
    $contact = Contact::factory()->fromWhatsapp()->create();
    $referral = ContactAttribution::factory()->fromMetaAd()->for($contact)->create([
        'headline' => 'Promoción de otoño',
        'source_id' => '120211234567890',
        'ctwa_clid' => 'click-id-secreto',
        'raw_referral' => ['ctwa_clid' => 'click-id-secreto'],
    ]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('origin.current.id', $referral->id)
            ->where('origin.current.origin_label', 'Anuncio de Facebook')
            ->where('origin.current.reliability', 'provider_confirmed')
            ->where('origin.current.reliability_label', 'Confirmado por Meta')
            ->where('origin.current.headline', 'Promoción de otoño')
            ->where('origin.current.source_id', '120211234567890')
            ->missing('origin.current.ctwa_clid')
            ->missing('origin.current.raw_referral')
        );
});

test('an incomplete referral only exposes the data Meta actually sent', function () {
    $contact = Contact::factory()->create();
    ContactAttribution::factory()->fromMetaAd()->for($contact)->create([
        'headline' => null,
        'body' => null,
        'source_url' => null,
        'media_type' => null,
        'campaign_name' => null,
        'page_name' => null,
    ]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('origin.current.origin_type', 'facebook_ad')
            ->where('origin.current.headline', null)
            ->where('origin.current.source_url', null)
            ->where('origin.current.campaign_name', null)
            ->where('origin.current.page_name', null)
        );
});

test('origin can be classified manually with optional details and the change is audited', function () {
    $admin = User::factory()->administrator()->create();
    $contact = Contact::factory()->create();

    $this->actingAs($admin)
        ->post(route('contacts.attributions.store', $contact), [
            'origin_type' => 'instagram_ad',
            'ad_platform' => 'Instagram',
            'campaign_name' => 'Temporada alta',
            'page_name' => 'Mi Negocio',
            'source_url' => 'https://instagram.com/p/abc',
            'notes' => 'Lo comentó al llamar.',
        ])
        ->assertSessionHasNoErrors();

    $attribution = $contact->attributions()->sole();

    expect($attribution)
        ->origin_type->toBe(ContactOriginType::InstagramAd)
        ->reliability->toBe(AttributionReliability::ManuallyDeclared)
        ->campaign_name->toBe('Temporada alta')
        ->source_url->toBe('https://instagram.com/p/abc')
        ->body->toBe('Lo comentó al llamar.')
        ->recorded_by_user_id->toBe($admin->id);

    expect(AuditLog::query()->where('action', AuditAction::ContactOriginClassified)->sole()->properties)
        ->toMatchArray(['origin_type' => 'instagram_ad', 'reliability' => 'manually_declared']);

    $this->actingAs($admin)
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('origin.current.reliability_label', 'Declarado manualmente')
            ->where('origin.current.recorded_by.name', $admin->name)
        );
});

test('a manual classification never alters nor outranks a provider confirmed origin', function () {
    $admin = User::factory()->administrator()->create();
    $contact = Contact::factory()->create();
    $confirmed = ContactAttribution::factory()->fromMetaAd()->for($contact)->create(['captured_at' => now()->subWeek()]);
    $snapshot = $confirmed->fresh()->getAttributes();

    $this->actingAs($admin)
        ->post(route('contacts.attributions.store', $contact), ['origin_type' => 'referral'])
        ->assertSessionHasNoErrors();

    expect($confirmed->fresh()->getAttributes())->toBe($snapshot)
        ->and($contact->attributions()->count())->toBe(2);

    $this->actingAs($admin)
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page
            ->where('origin.current.id', $confirmed->id)
            ->has('origin.history', 2)
            ->where('origin.history.0.origin_type', 'referral')
        );
});

test('the most recent manual declaration wins among manual ones', function () {
    $contact = Contact::factory()->create();
    ContactAttribution::factory()->for($contact)->create(['origin_type' => 'website', 'captured_at' => now()->subDay()]);
    $latest = ContactAttribution::factory()->for($contact)->create(['origin_type' => 'qr_code', 'captured_at' => now()]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('contacts.show', $contact))
        ->assertInertia(fn (Assert $page) => $page->where('origin.current.id', $latest->id));
});

test('manual classification validates the origin type and only accepts web URLs', function (array $input, string $error) {
    $contact = Contact::factory()->create();

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('contacts.attributions.store', $contact), [...['origin_type' => 'website'], ...$input])
        ->assertSessionHasErrors($error);

    expect($contact->attributions()->count())->toBe(0);
})->with([
    'unknown type cannot be declared' => [['origin_type' => 'unknown'], 'origin_type'],
    'invented type' => [['origin_type' => 'organic'], 'origin_type'],
    'javascript url' => [['source_url' => 'javascript:alert(1)'], 'source_url'],
    'ftp url' => [['source_url' => 'ftp://example.com/file'], 'source_url'],
]);

test('classifying the origin requires contacts.classify-origin and a visible contact', function () {
    $withoutPermission = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewContacts])->create();
    $assignedClassifier = Role::factory()->scope(DataScope::Assigned)
        ->withPermissions([Permission::ViewContacts, Permission::ClassifyContactOrigin])
        ->create();
    $contact = Contact::factory()->create();

    $this->actingAs(User::factory()->withRole($withoutPermission)->create())
        ->post(route('contacts.attributions.store', $contact), ['origin_type' => 'website'])
        ->assertForbidden();

    $this->actingAs(User::factory()->withRole($assignedClassifier)->create())
        ->post(route('contacts.attributions.store', $contact), ['origin_type' => 'website'])
        ->assertNotFound();

    expect($contact->attributions()->count())->toBe(0);
});
