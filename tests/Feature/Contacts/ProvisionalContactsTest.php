<?php

use App\Actions\Contacts\FindDuplicateContacts;
use App\Actions\Tags\TagCatalogQuery;
use App\Enums\AuditAction;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Events\Conversations\ConversationChanged;
use App\Jobs\DeliverOutboundMessage;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Reports\Definitions\ContactsReport;
use App\Reports\ReportContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;
use Tests\Support\WhatsAppFixtures;

/*
| Provisional contacts: an unknown sender is registered automatically as a
| technical record that keeps identities, conversations and replies working,
| but stays out of the commercial directory (list, reports, tag counts) until
| someone with contacts.create saves it. Contacts saved before keep being
| saved. No real Meta traffic: every Graph call is faked.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->verified()->create(['default_department_id' => $this->department->id]);
    $this->saver = conversationUser(
        [Permission::ViewConversations, Permission::ReplyConversations, Permission::ViewContacts, Permission::CreateContacts, Permission::ManageContactNotes],
        DataScope::Departments,
        [$this->department],
    );
});

/**
 * A provisional contact with an open conversation in the test department.
 */
function provisionalConversation(Connection $connection, Department $department): Conversation
{
    return Conversation::factory()->inDepartment($department)->create([
        'connection_id' => $connection->id,
        'contact_id' => Contact::factory()->provisional()->fromWhatsapp('5215511112222')->create(['name' => 'Laura Pérez'])->id,
        'status' => ConversationStatus::Open,
    ]);
}

test('an unknown WhatsApp sender becomes a provisional contact whose identity, conversation and replies keep working', function () {
    config(['whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0']);
    $this->connection->forceFill(['phone_number_id' => '140000000000077', 'access_token' => 'EAAGprovisionalToken0000000000000000000000000000000'])->save();
    $messagesUrl = 'https://graph.facebook.com/v26.0/140000000000077/messages';
    Http::fake([$messagesUrl => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['input' => '527771234567', 'wa_id' => '5217771234567']], 'messages' => [['id' => 'wamid.prov.1']]])]);

    $first = receiveWhatsapp($this->connection, '5217771234567', 'Hola', id: 'wamid.in.1');
    $second = receiveWhatsapp($this->connection, '5217771234567', '¿Siguen ahí?', id: 'wamid.in.2');

    $contact = $first->registration->contact->refresh();
    expect($contact->isSaved())->toBeFalse()
        ->and($contact->saved_by_user_id)->toBeNull()
        ->and($second->registration->contact->id)->toBe($contact->id)
        ->and($second->conversation->id)->toBe($first->conversation->id)
        ->and(Contact::query()->count())->toBe(1)
        ->and(ContactIdentity::query()->sole()->external_id)->toBe('5217771234567');

    $conversation = $first->conversation->refresh();
    $conversation->forceFill(['status' => ConversationStatus::Open, 'department_id' => $this->department->id, 'assigned_user_id' => $this->saver->id, 'assigned_at' => now()])->save();
    $message = WhatsAppFixtures::outbound($conversation, ['body' => 'Con gusto', 'sender_user_id' => $this->saver->id]);
    app()->call([new DeliverOutboundMessage($message->id), 'handle']);

    Http::assertSent(fn (Request $request): bool => $request->url() === $messagesUrl && $request['to'] === '527771234567');
    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('an anonymous web chat visitor becomes a provisional contact and the agent reply still reaches it', function () {
    $connection = WebChatFixtures::connection(connection: ['default_department_id' => $this->department->id]);
    $token = WebChatVisitor::token($this, $connection);

    WebChatVisitor::send($this, $token, 'Hola')->assertCreated();
    WebChatVisitor::send($this, $token, 'Otra pregunta')->assertCreated();

    $conversation = Conversation::query()->sole();
    expect(Contact::query()->sole()->isSaved())->toBeFalse()
        ->and(ContactIdentity::query()->sole()->external_id)->toStartWith('wv:');

    $conversation->forceFill(['status' => ConversationStatus::Open, 'assigned_user_id' => $this->saver->id, 'assigned_at' => now()])->save();
    $this->actingAs($this->saver)
        ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Con gusto le ayudo.', 'client_reference' => (string) str()->uuid()])
        ->assertStatus(202);

    expect(Message::query()->where('sender_user_id', $this->saver->id)->sole()->whatsapp_message_id)->toStartWith('web.out.');
});

test('contacts created by people and existing contacts are saved', function () {
    $existing = Contact::factory()->create();

    $this->actingAs($this->saver)
        ->post(route('contacts.store'), ['name' => 'Cliente nuevo', 'phone' => '5533334444'])
        ->assertSessionHasNoErrors();

    $created = Contact::query()->where('name', 'Cliente nuevo')->sole();
    expect($existing->isSaved())->toBeTrue()
        ->and($created->isSaved())->toBeTrue()
        ->and($created->saved_by_user_id)->toBe($this->saver->id);
});

test('the directory lists saved contacts and includes provisional ones only when asked', function () {
    $admin = User::factory()->superAdministrator()->create();
    $saved = Contact::factory()->create(['name' => 'Guardado']);
    $provisional = Contact::factory()->provisional()->create(['name' => 'Sin guardar']);

    $this->actingAs($admin)->get(route('contacts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contacts.data', 1)
            ->where('contacts.data.0.id', $saved->id)
            ->where('filters.include_provisional', false));

    $this->actingAs($admin)->get(route('contacts.index', ['include_provisional' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.include_provisional', true)
            ->has('contacts.data', 2)
            ->where('contacts.data', fn ($rows): bool => collect($rows)->firstWhere('id', $provisional->id)['is_saved'] === false));
});

test('the contacts report and the tag contact counts only count saved contacts', function () {
    $admin = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create();
    Contact::factory()->count(2)->create()->each(fn (Contact $contact) => $contact->tags()->attach($tag));
    Contact::factory()->provisional()->create()->tags()->attach($tag);
    $context = fn (array $filters): ReportContext => new ReportContext($admin, $filters, CarbonImmutable::now(), 'Chat Manager', 'America/Mexico_City');

    expect(app(ContactsReport::class)->count($context([])))->toBe(2)
        ->and(app(ContactsReport::class)->count($context(['include_provisional' => true])))->toBe(3)
        ->and((int) app(TagCatalogQuery::class)->handle($admin)->whereKey($tag->id)->sole()->getAttribute('contacts_count'))->toBe(2);
});

test('duplicate detection still finds provisional contacts and tells them apart', function () {
    $provisional = Contact::factory()->provisional()->create(['phone_e164' => '+525512340000']);

    $global = User::factory()->superAdministrator()->create();
    $matches = app(FindDuplicateContacts::class)->handle('+525512340000', null, $global);

    expect($matches)->toHaveCount(1)
        ->and($matches[0])->toMatchArray(['id' => $provisional->id, 'is_saved' => false, 'matches' => ['phone']]);
});

test('saving from the conversation promotes the contact, updates its official name and notifies the open conversations', function () {
    Event::fake([ConversationChanged::class]);
    $conversation = provisionalConversation($this->connection, $this->department);
    $contact = $conversation->contact;

    $this->actingAs($this->saver)
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.contact.is_saved', false)
            ->where('details.contact.is_saved', false)
            ->where('details.contact.can_save', true));

    $this->actingAs($this->saver)
        ->post(route('conversations.contact.save', $conversation), [
            'name' => '  Laura Pérez  (mayoreo) ',
            'email' => 'Laura@Ejemplo.com',
            'note' => 'Prefiere llamadas por la tarde.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $contact->refresh();
    expect($contact->isSaved())->toBeTrue()
        ->and($contact->saved_by_user_id)->toBe($this->saver->id)
        ->and($contact->name)->toBe('Laura Pérez (mayoreo)')
        ->and($contact->email)->toBe('laura@ejemplo.com')
        ->and($contact->phone_e164)->toBe('+5215511112222')
        ->and($contact->identities()->sole()->external_id)->toBe('5215511112222')
        ->and($contact->notes()->sole()->body)->toBe('Prefiere llamadas por la tarde.');

    $audit = AuditLog::query()->where('action', AuditAction::ContactSaved)->sole();
    expect($audit->user_id)->toBe($this->saver->id)
        ->and($audit->properties)->toMatchArray(['via' => 'conversation', 'conversation_id' => $conversation->id]);
    Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $event): bool => $event->conversationId === $conversation->id && $event->reason === 'contact');

    $this->actingAs($this->saver)
        ->post(route('conversations.contact.save', $conversation), ['name' => 'Otra vez'])
        ->assertSessionHasErrors('contact');
    expect(AuditLog::query()->where('action', AuditAction::ContactSaved)->count())->toBe(1);
});

test('saving a contact requires contacts.create and a conversation within reach', function () {
    $conversation = provisionalConversation($this->connection, $this->department);
    $withoutCreate = conversationUser([Permission::ViewConversations, Permission::ReplyConversations, Permission::ViewContacts], DataScope::Departments, [$this->department]);
    $otherDepartment = conversationUser(
        [Permission::ViewConversations, Permission::ViewContacts, Permission::CreateContacts],
        DataScope::Departments,
        [Department::factory()->create()],
    );

    $this->actingAs($withoutCreate)->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('details.contact.can_save', false));
    $this->actingAs($withoutCreate)->post(route('conversations.contact.save', $conversation), ['name' => 'Laura'])->assertForbidden();
    $this->actingAs($otherDepartment)->post(route('conversations.contact.save', $conversation), ['name' => 'Laura'])->assertNotFound();
    $this->actingAs($otherDepartment)->post(route('contacts.save', $conversation->contact), ['name' => 'Laura'])->assertNotFound();

    expect($conversation->contact->refresh()->isSaved())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::ContactSaved)->exists())->toBeFalse();
});

test('a private note while saving needs the notes permission', function () {
    $conversation = provisionalConversation($this->connection, $this->department);
    $withoutNotes = conversationUser([Permission::ViewConversations, Permission::ViewContacts, Permission::CreateContacts], DataScope::Departments, [$this->department]);

    $this->actingAs($withoutNotes)
        ->post(route('conversations.contact.save', $conversation), ['name' => 'Laura', 'note' => 'Nota'])
        ->assertSessionHasErrors('note');

    expect($conversation->contact->refresh()->isSaved())->toBeFalse();
});

test('the contact page of a provisional contact offers saving it', function () {
    $conversation = provisionalConversation($this->connection, $this->department);
    $contact = $conversation->contact;

    $this->actingAs($this->saver)->get(route('contacts.show', $contact))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('contact.is_saved', false)
            ->where('contact.can.save', true));

    $this->actingAs($this->saver)
        ->post(route('contacts.save', $contact), ['name' => 'Laura Pérez'])
        ->assertSessionHasNoErrors();

    expect($contact->refresh()->isSaved())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::ContactSaved)->sole()->properties)->toMatchArray(['via' => 'contact']);
});

test('the integration api tells whether the conversation contact is saved', function () {
    ['plain' => $plain] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);
    $provisional = provisionalConversation($this->connection, $this->department);
    $saved = Conversation::factory()->create(['connection_id' => $this->connection->id]);

    $this->withToken($plain)->getJson(route('api.v1.conversations.show', ['conversation' => $provisional->id]))
        ->assertOk()
        ->assertJsonPath('data.contact.is_saved', false);
    $this->withToken($plain)->getJson(route('api.v1.conversations.show', ['conversation' => $saved->id]))
        ->assertJsonPath('data.contact.is_saved', true);
});
