<?php

use App\Actions\Contacts\RegisterInboundContact;
use App\Enums\AuditAction;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\ContactReviewReason;
use App\Enums\ContactReviewResolution;
use App\Enums\ContactReviewStatus;
use App\Enums\ContactStatus;
use App\Enums\DataScope;
use App\Enums\InboundContactOutcome;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\ContactIdentity;
use App\Models\ContactReviewCase;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Support\InboundSender;
use App\Support\ProviderReferral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Possible duplicates that the system does not resolve on its own: manual
| registrations that match contacts outside the employee's scope, and
| WhatsApp senders whose phone matches existing contacts ambiguously.
*/

function departmentContactManager(Department $department): User
{
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([
        Permission::ViewContacts,
        Permission::CreateContacts,
        Permission::UpdateContacts,
    ])->create();

    return User::factory()->withRole($role)->inDepartments($department)->create();
}

/**
 * The contact a WhatsApp sender ends up in when its phone matches two
 * contacts registered by hand: a new automatic contact under review.
 *
 * @return array{ContactReviewCase, Contact, list<Contact>}
 */
function ambiguousWhatsappSender(): array
{
    $candidates = Contact::factory()->count(2)->create(['phone_e164' => '+525512345678'])->all();
    $registration = app(RegisterInboundContact::class)->handle(Connection::factory()->create(), new InboundSender(
        channel: ConnectionChannel::Whatsapp,
        provider: ConnectionProvider::MetaCloudApi,
        externalId: '5215512345678',
        profileName: 'Laura',
        messageId: 'wamid.review',
        occurredAt: CarbonImmutable::parse('2026-10-07 10:00'),
        referral: ProviderReferral::fromArray(['source_type' => 'ad', 'source_id' => '120']),
    ));

    return [$registration->reviewCase, $registration->contact, $candidates];
}

test('registering by hand a phone of a hidden contact answers exactly like a new phone and goes to review', function () {
    $department = Department::factory()->create();
    $employee = departmentContactManager($department);
    $hidden = Contact::factory()->create(['name' => 'Cliente de otro departamento', 'phone_e164' => '+525512345678']);

    $answer = function (string $phone) use ($employee) {
        $response = $this->actingAs($employee)->post(route('contacts.store'), ['name' => "Alta {$phone}", 'phone' => $phone]);
        $created = Contact::query()->where('name', "Alta {$phone}")->sole();

        return [
            $response->status(),
            $response->headers->get('Location') === route('contacts.show', $created),
            session('errors')?->getBag('default')->toArray(),
            session(SessionKey::FLASH_DATA),
        ];
    };

    $withHiddenMatch = $answer('55 1234 5678');
    $withoutMatch = $answer('55 8765 4321');

    $flashOf = fn (array $answer) => collect($answer[3])->except('toast')->all();

    expect([$withHiddenMatch[0], $withHiddenMatch[1], $withHiddenMatch[2], $flashOf($withHiddenMatch)])
        ->toBe([$withoutMatch[0], $withoutMatch[1], $withoutMatch[2], $flashOf($withoutMatch)])
        ->and(json_encode(session()->all()))->not->toContain('Cliente de otro departamento');

    $case = ContactReviewCase::query()->sole();
    expect($case->reason)->toBe(ContactReviewReason::PossibleDuplicate)
        ->and($case->contact->name)->toBe('Alta 55 1234 5678')
        ->and($case->candidate_contact_ids)->toBe([$hidden->id]);

    $this->actingAs($employee)->get(route('contacts.reviews.index'))->assertForbidden();
});

test('matches the employee can see are still warned and never sent to review', function () {
    $admin = User::factory()->superAdministrator()->create();
    Contact::factory()->create(['phone_e164' => '+525512345678']);

    $this->actingAs($admin)
        ->post(route('contacts.store'), ['name' => 'Otra', 'phone' => '5512345678'])
        ->assertSessionHasErrors('confirm_duplicate');

    $this->actingAs($admin)
        ->post(route('contacts.store'), ['name' => 'Otra', 'phone' => '5512345678', 'confirm_duplicate' => true])
        ->assertSessionHasNoErrors();

    expect(ContactReviewCase::query()->count())->toBe(0);
});

test('editing a contact into a hidden match keeps a single open case per contact', function () {
    $department = Department::factory()->create();
    $employee = departmentContactManager($department);
    [$firstHidden, $secondHidden] = Contact::factory()->count(2)->sequence(
        ['phone_e164' => '+525511111111'],
        ['email' => 'compartido@example.com'],
    )->create();
    $own = Contact::factory()->createdBy($employee)->create(['phone_e164' => '+525599999999']);

    $this->actingAs($employee)->put(route('contacts.update', $own), ['name' => $own->name, 'phone' => '5511111111'])->assertSessionHasNoErrors();
    $this->actingAs($employee)->put(route('contacts.update', $own), ['name' => $own->name, 'phone' => '5511111111', 'email' => 'compartido@example.com'])->assertSessionHasNoErrors();

    expect(ContactReviewCase::query()->open()->sole()->candidate_contact_ids)->toBe([$firstHidden->id, $secondHidden->id]);
});

test('only administrators with organization-wide scope review possible duplicates', function (Closure $makeUser) {
    [$case] = ambiguousWhatsappSender();
    $user = $makeUser();

    $this->actingAs($user)->get(route('contacts.reviews.index'))->assertForbidden();
    $this->actingAs($user)
        ->patch(route('contacts.reviews.update', $case), ['resolution' => 'distinct_people'])
        ->assertForbidden();

    expect($case->refresh()->status)->toBe(ContactReviewStatus::Open);
})->with([
    'agent' => [fn () => User::factory()->agent()->create()],
    'supervisor' => [fn () => User::factory()->supervisor()->create()],
    'departments scope with contact permissions' => [fn () => departmentContactManager(Department::factory()->create())],
    'global scope without contacts.update' => [fn () => User::factory()
        ->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewContacts])->create())
        ->create()],
]);

test('administrators see each case with the contacts it compares', function () {
    [$case, $automatic, $candidates] = ambiguousWhatsappSender();
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->get(route('contacts.reviews.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Reviews')
            ->has('cases.data', 1)
            ->where('cases.data.0.id', $case->id)
            ->where('cases.data.0.reason', 'ambiguous_identity_match')
            ->where('cases.data.0.contact.id', $automatic->id)
            ->where('cases.data.0.contact.whatsapp_ids', ['5215512345678'])
            ->where('cases.data.0.candidates.0.id', $candidates[0]->id)
            ->where('cases.data.0.candidates.1.id', $candidates[1]->id));

    $this->actingAs($admin)
        ->get(route('contacts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('openReviews', 1)->where('can.reviewDuplicates', true));
});

test('marking them as different people closes the case and changes no contact', function () {
    [$case, $automatic, $candidates] = ambiguousWhatsappSender();
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->patch(route('contacts.reviews.update', $case), ['resolution' => 'distinct_people'])
        ->assertSessionHasNoErrors();

    $case->refresh();

    expect($case->status)->toBe(ContactReviewStatus::Resolved)
        ->and($case->resolution)->toBe(ContactReviewResolution::DistinctPeople)
        ->and($case->resolved_by_user_id)->toBe($admin->id)
        ->and(Contact::query()->where('status', ContactStatus::Active)->count())->toBe(3)
        ->and($automatic->identities()->count())->toBe(1);

    $this->actingAs($admin)
        ->patch(route('contacts.reviews.update', $case), ['resolution' => 'distinct_people'])
        ->assertSessionHasErrors(['resolution' => 'Este caso ya fue resuelto.']);
});

test('linking moves the WhatsApp identity and its evidence to the chosen contact and archives the automatic one', function () {
    [$case, $automatic, [$chosen]] = ambiguousWhatsappSender();
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create();
    $automatic->tags()->attach($tag, ['tagged_by_user_id' => $admin->id, 'created_at' => now()]);
    $automatic->notes()->create(['body' => 'Pidió catálogo.', 'author_user_id' => $admin->id]);
    $chosenName = $chosen->name;

    $this->actingAs($admin)
        ->patch(route('contacts.reviews.update', $case), ['resolution' => 'identity_linked', 'target_contact_id' => $chosen->id])
        ->assertSessionHasNoErrors();

    $chosen->refresh();
    $automatic->refresh();

    expect(ContactIdentity::query()->where('external_id', '5215512345678')->sole()->contact_id)->toBe($chosen->id)
        ->and(ContactAttribution::query()->sole()->contact_id)->toBe($chosen->id)
        ->and($chosen->tags()->pluck('tags.id')->all())->toBe([$tag->id])
        ->and($chosen->notes()->sole()->body)->toBe('Pidió catálogo.')
        ->and(DB::table('connection_contact')->pluck('contact_id')->all())->toBe([$chosen->id])
        ->and($chosen->name)->toBe($chosenName)
        ->and($chosen->profile_name)->toBe('Laura')
        ->and($chosen->last_interaction_at->toDateTimeString())->toBe('2026-10-07 10:00:00')
        ->and($automatic->status)->toBe(ContactStatus::Archived)
        ->and($case->refresh()->resolved_contact_id)->toBe($chosen->id)
        ->and(AuditLog::query()->where('action', AuditAction::ContactIdentityLinked)->sole()->auditable_id)->toBe($chosen->id);

    $next = app(RegisterInboundContact::class)->handle(Connection::factory()->create(), new InboundSender(
        channel: ConnectionChannel::Whatsapp,
        provider: ConnectionProvider::MetaCloudApi,
        externalId: '5215512345678',
        profileName: 'Laura',
        messageId: 'wamid.after-review',
        occurredAt: CarbonImmutable::parse('2026-10-08 10:00'),
    ));

    expect($next->outcome)->toBe(InboundContactOutcome::Matched)
        ->and($next->contact->id)->toBe($chosen->id);
});

test('a decision that does not fit the case is refused', function (Closure $setup, string $field) {
    [$case, $input] = $setup();
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->patch(route('contacts.reviews.update', $case), $input)
        ->assertSessionHasErrors($field);

    expect($case->refresh()->status)->toBe(ContactReviewStatus::Open)
        ->and(Contact::query()->where('status', ContactStatus::Archived)->count())->toBe(0)
        ->and(ContactIdentity::query()->pluck('contact_id')->all())->each->not->toBe($input['target_contact_id'] ?? 0);
})->with([
    'target outside the candidates' => [fn () => [ambiguousWhatsappSender()[0], ['resolution' => 'identity_linked', 'target_contact_id' => Contact::factory()->create()->id]], 'target_contact_id'],
    'missing target' => [fn () => [ambiguousWhatsappSender()[0], ['resolution' => 'identity_linked']], 'target_contact_id'],
    'linking a manual duplicate' => [function () {
        $case = ContactReviewCase::factory()->create();

        return [$case, ['resolution' => 'identity_linked', 'target_contact_id' => $case->candidate_contact_ids[0]]];
    }, 'resolution'],
    'archiving a WhatsApp sender' => [function () {
        [$case, , [$candidate]] = ambiguousWhatsappSender();

        return [$case, ['resolution' => 'duplicate_archived', 'target_contact_id' => $candidate->id]];
    }, 'resolution'],
    'unknown resolution' => [fn () => [ambiguousWhatsappSender()[0], ['resolution' => 'merge_everything']], 'resolution'],
]);

test('a sender that already has conversations cannot be linked yet', function () {
    [$case, $automatic, [$chosen]] = ambiguousWhatsappSender();
    Conversation::factory()->for($automatic)->create();
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->patch(route('contacts.reviews.update', $case), ['resolution' => 'identity_linked', 'target_contact_id' => $chosen->id])
        ->assertSessionHasErrors('resolution');

    expect(ContactIdentity::query()->sole()->contact_id)->toBe($automatic->id)
        ->and($case->refresh()->status)->toBe(ContactReviewStatus::Open);
});

test('a manual duplicate can be archived, keeping its history', function () {
    $hidden = Contact::factory()->create();
    $duplicate = Contact::factory()->create();
    $case = ContactReviewCase::factory()->create(['contact_id' => $duplicate->id, 'candidate_contact_ids' => [$hidden->id]]);
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->patch(route('contacts.reviews.update', $case), ['resolution' => 'duplicate_archived', 'target_contact_id' => $hidden->id])
        ->assertSessionHasNoErrors();

    expect($duplicate->refresh()->status)->toBe(ContactStatus::Archived)
        ->and($hidden->refresh()->status)->toBe(ContactStatus::Active)
        ->and(Contact::query()->count())->toBe(2)
        ->and($case->refresh()->resolution)->toBe(ContactReviewResolution::DuplicateArchived);
});
