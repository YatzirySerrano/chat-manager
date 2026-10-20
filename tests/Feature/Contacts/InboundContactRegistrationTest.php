<?php

use App\Actions\Contacts\ProcessInboundContacts;
use App\Actions\Contacts\RegisterInboundContact;
use App\Enums\AttributionReliability;
use App\Enums\AuditAction;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\ContactOriginType;
use App\Enums\ContactReviewReason;
use App\Enums\ContactSource;
use App\Enums\InboundContactOutcome;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\ContactIdentity;
use App\Models\ContactReviewCase;
use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use App\Services\ResolveContactOrigin;
use App\Support\InboundContactRegistration;
use App\Support\InboundSender;
use App\Support\ProviderReferral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
| Automatic registration of contacts from inbound WhatsApp messages. Events
| are built from the documented WhatsApp Cloud API webhook format; no real
| Meta traffic is involved.
*/

/**
 * @param  array<string, mixed>|null  $referral
 */
function inboundSender(string $waId = '5215512345678', ?string $profileName = 'Laura Pérez', ?string $messageId = null, ?CarbonImmutable $at = null, ?array $referral = null): InboundSender
{
    return new InboundSender(
        channel: ConnectionChannel::Whatsapp,
        provider: ConnectionProvider::MetaCloudApi,
        externalId: $waId,
        profileName: $profileName,
        messageId: $messageId ?? 'wamid.'.fake()->unique()->bothify('??########'),
        occurredAt: $at ?? CarbonImmutable::now(),
        referral: $referral === null ? null : ProviderReferral::fromArray($referral),
    );
}

function registerInbound(Connection $connection, InboundSender $sender): InboundContactRegistration
{
    return app(RegisterInboundContact::class)->handle($connection, $sender);
}

/**
 * A webhook body shaped like the WhatsApp Cloud API "messages" notification.
 *
 * @param  list<array<string, mixed>>  $messages
 * @param  list<array<string, mixed>>  $contacts
 * @return array<string, mixed>
 */
function metaWebhook(string $phoneNumberId, array $messages, array $contacts = []): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '102290129340398',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $phoneNumberId],
                    'contacts' => $contacts,
                    'messages' => $messages,
                ],
            ]],
        ]],
    ];
}

test('a new sender is registered automatically with its verified identity', function () {
    $this->freezeTime();
    $connection = Connection::factory()->create();

    $registration = registerInbound($connection, inboundSender('5215512345678', 'Laura Pérez'));

    $contact = $registration->contact->refresh();

    expect(Auth::check())->toBeFalse()
        ->and($registration->outcome)->toBe(InboundContactOutcome::Created)
        ->and($contact->name)->toBe('Laura Pérez')
        ->and($contact->profile_name)->toBe('Laura Pérez')
        ->and($contact->phone_e164)->toBe('+525512345678')
        ->and($contact->source)->toBe(ContactSource::Whatsapp)
        ->and($contact->created_by_user_id)->toBeNull()
        ->and($contact->first_interaction_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($contact->last_interaction_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($contact->identities()->sole()->only(['external_id', 'display_name']))->toBe(['external_id' => '5215512345678', 'display_name' => 'Laura Pérez'])
        ->and($contact->connections()->sole()->id)->toBe($connection->id)
        ->and(app(ResolveContactOrigin::class)->handle($contact))->toBeNull();

    $audit = AuditLog::query()->where('action', AuditAction::ContactCreated)->sole();
    expect($audit->user_id)->toBeNull()
        ->and($audit->actor_name)->toBe('Sistema')
        ->and($audit->properties)->toMatchArray(['source' => 'whatsapp', 'automatic' => true, 'connection_id' => $connection->id]);
});

test('a known sender reuses its contact and keeps what employees edited', function () {
    $connection = Connection::factory()->create();
    $first = registerInbound($connection, inboundSender('5215512345678', 'Laura', at: CarbonImmutable::parse('2026-10-01 10:00')));
    $contact = $first->contact;

    $contact->forceFill(['name' => 'Laura Pérez (cliente mayorista)', 'email' => 'laura@example.com'])->save();
    $tag = Tag::factory()->create();
    $contact->tags()->attach($tag);
    $contact->notes()->create(['body' => 'Prefiere llamadas por la tarde.', 'author_user_id' => User::factory()->create()->id]);

    $second = registerInbound($connection, inboundSender('5215512345678', 'Laurita', at: CarbonImmutable::parse('2026-10-05 09:30')));

    $contact->refresh();

    expect($second->outcome)->toBe(InboundContactOutcome::Matched)
        ->and($second->contact->id)->toBe($contact->id)
        ->and(Contact::query()->count())->toBe(1)
        ->and(ContactIdentity::query()->count())->toBe(1)
        ->and($contact->name)->toBe('Laura Pérez (cliente mayorista)')
        ->and($contact->email)->toBe('laura@example.com')
        ->and($contact->profile_name)->toBe('Laurita')
        ->and($contact->tags()->pluck('tags.id')->all())->toBe([$tag->id])
        ->and($contact->notes()->count())->toBe(1)
        ->and($contact->first_interaction_at->toDateTimeString())->toBe('2026-10-01 10:00:00')
        ->and($contact->last_interaction_at->toDateTimeString())->toBe('2026-10-05 09:30:00')
        ->and(AuditLog::query()->where('action', AuditAction::ContactCreated)->count())->toBe(1);
});

test('a repeated delivery of the same message changes nothing', function () {
    $connection = Connection::factory()->create();
    $referral = ['source_type' => 'ad', 'source_id' => '120226305854810726', 'source_url' => 'https://fb.me/abc', 'ctwa_clid' => 'ARAkLkA8'];
    $sender = inboundSender(messageId: 'wamid.HBgLMTY1MDM4Nzk0MzkVAgASGBQzQTRBNjU5OUFFRTAzODEwMTQ0RgA=', at: CarbonImmutable::parse('2026-10-02 08:00'), referral: $referral);

    $once = registerInbound($connection, $sender);
    $snapshot = [
        Contact::query()->firstOrFail()->toArray(),
        DB::table('connection_contact')->get()->toArray(),
        ContactIdentity::query()->firstOrFail()->toArray(),
    ];

    $twice = registerInbound($connection, $sender);

    expect($twice->contact->id)->toBe($once->contact->id)
        ->and($twice->attribution?->id)->toBe($once->attribution?->id)
        ->and(ContactAttribution::query()->count())->toBe(1)
        ->and([
            Contact::query()->firstOrFail()->toArray(),
            DB::table('connection_contact')->get()->toArray(),
            ContactIdentity::query()->firstOrFail()->toArray(),
        ])->toEqual($snapshot);
});

test('events processed out of order never move dates backwards nor restore an older profile name', function () {
    $connection = Connection::factory()->create();

    registerInbound($connection, inboundSender(profileName: 'Nombre nuevo', at: CarbonImmutable::parse('2026-10-10 12:00')));
    $late = registerInbound($connection, inboundSender(profileName: 'Nombre viejo', at: CarbonImmutable::parse('2026-10-08 12:00')));

    $contact = $late->contact->refresh();
    $pivot = DB::table('connection_contact')->sole();

    expect($contact->profile_name)->toBe('Nombre nuevo')
        ->and($late->identity->refresh()->display_name)->toBe('Nombre nuevo')
        ->and($contact->first_interaction_at->toDateTimeString())->toBe('2026-10-08 12:00:00')
        ->and($contact->last_interaction_at->toDateTimeString())->toBe('2026-10-10 12:00:00')
        ->and($pivot->first_interaction_at)->toBe('2026-10-08 12:00:00')
        ->and($pivot->last_interaction_at)->toBe('2026-10-10 12:00:00');
});

test('consecutive messages keep a single contact and advance the last interaction', function () {
    $connection = Connection::factory()->create();

    foreach (['09:00', '09:01', '09:05'] as $time) {
        registerInbound($connection, inboundSender(at: CarbonImmutable::parse("2026-10-03 {$time}")));
    }

    $contact = Contact::query()->sole();

    expect(ContactIdentity::query()->count())->toBe(1)
        ->and($contact->first_interaction_at->toDateTimeString())->toBe('2026-10-03 09:00:00')
        ->and($contact->last_interaction_at->toDateTimeString())->toBe('2026-10-03 09:05:00');
});

test('the same person writing to two connections is one contact related to both', function () {
    [$sales, $support] = Connection::factory()->count(2)->create();

    registerInbound($sales, inboundSender(at: CarbonImmutable::parse('2026-10-01 10:00')));
    $registration = registerInbound($support, inboundSender(at: CarbonImmutable::parse('2026-10-04 10:00')));

    expect(Contact::query()->count())->toBe(1)
        ->and($registration->outcome)->toBe(InboundContactOutcome::Matched)
        ->and($registration->contact->connections()->orderBy('connections.id')->pluck('connections.id')->all())->toBe([$sales->id, $support->id]);
});

test('a contact registered by hand with the same phone receives the WhatsApp identity', function () {
    $connection = Connection::factory()->create();
    $manual = Contact::factory()->create(['name' => 'Laura (capturada en tienda)', 'phone_e164' => '+525512345678', 'profile_name' => null]);

    $registration = registerInbound($connection, inboundSender('5215512345678', 'Laura P'));

    $manual->refresh();

    expect($registration->outcome)->toBe(InboundContactOutcome::Linked)
        ->and($registration->contact->id)->toBe($manual->id)
        ->and(Contact::query()->count())->toBe(1)
        ->and($manual->name)->toBe('Laura (capturada en tienda)')
        ->and($manual->source)->toBe(ContactSource::Manual)
        ->and($manual->profile_name)->toBe('Laura P')
        ->and($manual->identities()->sole()->external_id)->toBe('5215512345678')
        ->and(AuditLog::query()->where('action', AuditAction::ContactIdentityLinked)->sole()->auditable_id)->toBe($manual->id)
        ->and(ContactReviewCase::query()->count())->toBe(0);
});

test('an ambiguous phone match registers a new contact and opens a review case instead of merging', function (Closure $existing) {
    $connection = Connection::factory()->create();
    $candidateIds = $existing();

    $registration = registerInbound($connection, inboundSender('5215512345678'));

    $case = $registration->reviewCase;

    expect($registration->outcome)->toBe(InboundContactOutcome::Created)
        ->and($case)->not->toBeNull()
        ->and($case->reason)->toBe(ContactReviewReason::AmbiguousIdentityMatch)
        ->and($case->contact_id)->toBe($registration->contact->id)
        ->and($case->candidate_contact_ids)->toBe($candidateIds)
        ->and(ContactIdentity::query()->where('external_id', '5215512345678')->sole()->contact_id)->toBe($registration->contact->id);
})->with([
    'two contacts registered by hand' => [fn () => Contact::factory()->count(2)->create(['phone_e164' => '+525512345678'])->modelKeys()],
    'a contact that already has another WhatsApp identity' => [fn () => [
        ContactIdentity::factory()->for(Contact::factory()->state(['phone_e164' => '+525512345678']))->create(['external_id' => '525512345678'])->contact_id,
    ]],
]);

test('a sender whose id is not a valid phone is registered without a phone', function (string $waId, ?string $profileName, string $expectedName) {
    $registration = registerInbound(Connection::factory()->create(), inboundSender($waId, $profileName));

    expect($registration->contact->phone_e164)->toBeNull()
        ->and($registration->contact->name)->toBe($expectedName)
        ->and($registration->identity->external_id)->toBe($waId);
})->with([
    'too short, with profile' => ['12345', 'Pedro', 'Pedro'],
    'too short, without profile' => ['12345', null, 'WhatsApp 12345'],
    'non numeric provider id' => ['MX.abc123', null, 'WhatsApp MX.abc123'],
]);

test('a sender without profile name is named after the verified phone', function () {
    $registration = registerInbound(Connection::factory()->create(), inboundSender('5215512345678', null));

    expect($registration->contact->name)->toBe('+525512345678')
        ->and($registration->contact->profile_name)->toBeNull();
});

test('profile names are stored as plain text', function () {
    $registration = registerInbound(Connection::factory()->create(), inboundSender(profileName: "<b>Ana</b>\u{0007} <script>x</script>"));

    expect($registration->contact->name)->toBe('Ana x');
});

test('unusable sender data is rejected before touching the directory', function (string $waId, string $messageId) {
    expect(fn () => inboundSender($waId, messageId: $messageId))->toThrow(InvalidArgumentException::class)
        ->and(Contact::query()->count())->toBe(0);
})->with([
    'empty id' => ['', 'wamid.1'],
    'id with spaces' => ['52 155 1234', 'wamid.1'],
    'id too long' => [str_repeat('1', 65), 'wamid.1'],
    'missing message id' => ['5215512345678', ''],
]);

test('an official ad referral is kept as origin confirmed by Meta without guessing missing data', function () {
    $connection = Connection::factory()->create();
    $referral = [
        'source_url' => 'https://fb.me/2ZulEu42P',
        'source_id' => '120226305854810726',
        'source_type' => 'ad',
        'body' => 'Promoción de temporada',
        'headline' => 'Escríbenos por WhatsApp',
        'media_type' => 'image',
        'image_url' => 'https://scontent.xx.fbcdn.net/image.jpg',
        'ctwa_clid' => 'ARAkLkA8rmlFeiCktEJQ-QTwRiyYHAFDLMNDBH0CD3qpjd0HR4irJ6LEkR7JwFF4XvnO2E4Nx0-eM-GABDLOPaOdRMv-_zfUQ2a',
    ];

    $registration = registerInbound($connection, inboundSender(messageId: 'wamid.ad1', at: CarbonImmutable::parse('2026-10-06 18:00'), referral: $referral));

    $attribution = $registration->attribution;

    expect($attribution->provider_message_id)->toBe('wamid.ad1')
        ->and($attribution->origin_type)->toBe(ContactOriginType::MetaAd)
        ->and($attribution->reliability)->toBe(AttributionReliability::ProviderConfirmed)
        ->and($attribution->only(['provider', 'source_id', 'source_type', 'source_url', 'headline', 'body', 'media_type', 'ctwa_clid']))->toBe([
            'provider' => 'meta',
            'source_id' => '120226305854810726',
            'source_type' => 'ad',
            'source_url' => 'https://fb.me/2ZulEu42P',
            'headline' => 'Escríbenos por WhatsApp',
            'body' => 'Promoción de temporada',
            'media_type' => 'image',
            'ctwa_clid' => $referral['ctwa_clid'],
        ])
        ->and($attribution->only(['ad_platform', 'campaign_id', 'campaign_name', 'page_name', 'conversation_id']))->each->toBeNull()
        ->and($attribution->raw_referral)->toBe($referral)
        ->and($attribution->captured_at->toDateTimeString())->toBe('2026-10-06 18:00:00')
        ->and(app(ResolveContactOrigin::class)->handle($registration->contact)?->id)->toBe($attribution->id);
});

test('incomplete referrals keep only what Meta sent', function (array $referral, ContactOriginType $type, array $expected) {
    $registration = registerInbound(Connection::factory()->create(), inboundSender(referral: $referral));

    expect($registration->attribution->origin_type)->toBe($type)
        ->and($registration->attribution->only(array_keys($expected)))->toBe($expected);
})->with([
    'ad id without campaign nor media' => [['source_type' => 'ad', 'source_id' => '120226305854810726'], ContactOriginType::MetaAd, ['source_id' => '120226305854810726', 'source_url' => null, 'campaign_id' => null, 'headline' => null]],
    'post referral' => [['source_type' => 'post', 'source_url' => 'https://www.instagram.com/p/xyz'], ContactOriginType::MetaPost, ['source_url' => 'https://www.instagram.com/p/xyz', 'source_id' => null]],
    'unknown source type' => [['source_type' => 'story', 'ctwa_clid' => 'ARA1'], ContactOriginType::Other, ['source_type' => 'story', 'ctwa_clid' => 'ARA1']],
    'unsafe url is dropped' => [['source_type' => 'ad', 'source_id' => '1', 'source_url' => 'javascript:alert(1)'], ContactOriginType::MetaAd, ['source_url' => null]],
]);

test('a message without referral keeps the origin unidentified', function () {
    $registration = registerInbound(Connection::factory()->create(), inboundSender(referral: ['image_url' => 'https://x.test/a.jpg']));

    expect($registration->attribution)->toBeNull()
        ->and(ContactAttribution::query()->count())->toBe(0)
        ->and(app(ResolveContactOrigin::class)->handle($registration->contact))->toBeNull();
});

test('a referral confirmed by Meta outranks an earlier manual declaration, which stays in the history', function () {
    $connection = Connection::factory()->create();
    $first = registerInbound($connection, inboundSender(at: CarbonImmutable::parse('2026-10-01 10:00')));
    $manual = ContactAttribution::factory()->for($first->contact)->create(['origin_type' => ContactOriginType::Website, 'captured_at' => '2026-10-02 10:00']);

    $later = registerInbound($connection, inboundSender(at: CarbonImmutable::parse('2026-10-03 10:00'), referral: ['source_type' => 'ad', 'source_id' => '99']));
    $contact = $later->contact->load('attributions');

    expect(app(ResolveContactOrigin::class)->handle($contact)?->id)->toBe($later->attribution->id)
        ->and($contact->attributions->pluck('id')->sort()->values()->all())->toBe([$manual->id, $later->attribution->id]);
});

test('a manual classification cannot claim to come from Meta', function (string $originType) {
    $admin = User::factory()->administrator()->create();
    $contact = Contact::factory()->create();

    $this->actingAs($admin)
        ->post(route('contacts.attributions.store', $contact), ['origin_type' => $originType])
        ->assertSessionHasErrors('origin_type');

    expect($contact->attributions()->count())->toBe(0);
})->with(['meta_ad', 'meta_post']);

test('automatic registration does not widen what each employee can see', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $admin = User::factory()->administrator()->create();
    $registration = registerInbound(Connection::factory()->create(), inboundSender(profileName: 'Cliente Reservado'));

    $this->actingAs($agent)->get(route('contacts.show', $registration->contact))->assertNotFound();
    $this->actingAs($agent)->get(route('contacts.index', ['search' => 'Reservado']))
        ->assertInertia(fn ($page) => $page->has('contacts.data', 0));
    $this->actingAs($admin)->get(route('contacts.index', ['search' => 'Reservado']))
        ->assertInertia(fn ($page) => $page->has('contacts.data', 1)->where('contacts.data.0.source', 'whatsapp'));
});

test('a documented webhook body registers its senders', function () {
    $connection = Connection::factory()->create(['phone_number_id' => '106540352242922']);
    $other = Connection::factory()->create(['phone_number_id' => '999']);

    $registrations = app(ProcessInboundContacts::class)->handle(metaWebhook('106540352242922', [
        ['from' => '5215512345678', 'id' => 'wamid.1', 'timestamp' => '1760637600', 'type' => 'text', 'text' => ['body' => 'Hola'],
            'referral' => ['source_type' => 'ad', 'source_id' => '120226305854810726', 'source_url' => 'https://fb.me/x', 'ctwa_clid' => 'ARA']],
        ['from' => '5215587654321', 'id' => 'wamid.2', 'timestamp' => '1760637660', 'type' => 'image', 'image' => ['id' => '1', 'mime_type' => 'image/jpeg']],
    ], [
        ['profile' => ['name' => 'Sheena Nelson'], 'wa_id' => '5215512345678'],
        ['profile' => ['name' => 'Marco Ruiz'], 'wa_id' => '5215587654321'],
    ]));

    expect($registrations)->toHaveCount(2)
        ->and(collect($registrations)->map(fn ($registration) => [$registration->contact->name, $registration->connection->id, $registration->outcome])->all())->toBe([
            ['Sheena Nelson', $connection->id, InboundContactOutcome::Created],
            ['Marco Ruiz', $connection->id, InboundContactOutcome::Created],
        ])
        ->and($registrations[0]->attribution?->origin_type)->toBe(ContactOriginType::MetaAd)
        ->and($registrations[0]->contact->first_interaction_at->getTimestamp())->toBe(1760637600)
        ->and($other->contacts()->count())->toBe(0);
});

test('webhook items without a usable sender are skipped and logged', function () {
    Log::spy();
    Connection::factory()->create(['phone_number_id' => '106540352242922']);

    $statusesOnly = metaWebhook('106540352242922', []);
    $statusesOnly['entry'][0]['changes'][0]['value']['statuses'] = [['id' => 'wamid.out', 'status' => 'delivered', 'timestamp' => '1760637600', 'recipient_id' => '5215512345678']];

    $results = [
        'statuses' => app(ProcessInboundContacts::class)->handle($statusesOnly),
        'unknown connection' => app(ProcessInboundContacts::class)->handle(metaWebhook('000', [['from' => '5215512345678', 'id' => 'wamid.3', 'timestamp' => '1760637600', 'type' => 'text']])),
        'mixed' => app(ProcessInboundContacts::class)->handle(metaWebhook('106540352242922', [
            ['from' => '5215512345678', 'id' => 'wamid.4', 'timestamp' => '1760637600', 'type' => 'system', 'system' => ['body' => 'cambió de número']],
            ['from' => '5215512345678', 'id' => 'wamid.5', 'type' => 'text'],
            ['from' => 'no válido', 'id' => 'wamid.6', 'timestamp' => '1760637600', 'type' => 'text'],
            ['from' => '5215587654321', 'id' => 'wamid.7', 'timestamp' => '1760637600', 'type' => 'text'],
        ])),
        'other object' => app(ProcessInboundContacts::class)->handle(['object' => 'page', 'entry' => []]),
    ];

    expect(array_map('count', $results))->toBe(['statuses' => 0, 'unknown connection' => 0, 'mixed' => 1, 'other object' => 0])
        ->and(Contact::query()->pluck('phone_e164')->all())->toBe(['+525587654321']);

    Log::shouldHaveReceived('warning')->with('Inbound message ignored: no connection uses this phone number id.', Mockery::any())->once();
    Log::shouldHaveReceived('warning')->with('Meta webhook message ignored: The message lacks its sender, id or timestamp.', Mockery::any())->once();
    Log::shouldHaveReceived('warning')->with('Meta webhook message ignored: The sender identity is missing or malformed.', Mockery::any())->once();
    Log::shouldHaveReceived('info')->with('Meta webhook system notice ignored.', Mockery::any())->once();
});
