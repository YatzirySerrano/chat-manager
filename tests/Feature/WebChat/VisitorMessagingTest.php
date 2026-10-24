<?php

use App\Enums\ConnectionChannel;
use App\Enums\ContactSource;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\MessageStatus;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NotificationKind;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/*
| Web chat messages go through the same pipeline as WhatsApp: contact
| registration, routing, distribution, notifications, acceptance and
| transfers; agents reply through the usual queue and delivery job, and
| the visitor only ever reads their own conversation.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    $this->sales = Department::factory()->create();
    $this->support = Department::factory()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->sales)->create();
    $this->connection = WebChatFixtures::connection(connection: ['default_department_id' => $this->sales->id]);
    $this->connection->departments()->attach([$this->sales->id, $this->support->id]);
    $this->token = WebChatVisitor::token($this, $this->connection, ['profile' => ['name' => 'Ana', 'email' => 'ana@tienda.test']]);
});

test('the first message creates the contact and a pending conversation routed and announced like WhatsApp', function () {
    $response = WebChatVisitor::send($this, $this->token, 'Hola, ¿tienen envíos?')
        ->assertCreated()
        ->assertJsonPath('data.direction', 'inbound')
        ->assertJsonPath('data.author', 'visitor')
        ->assertJsonPath('data.body', 'Hola, ¿tienen envíos?');

    $conversation = Conversation::query()->sole();
    $identity = ContactIdentity::query()->sole();

    expect($conversation)
        ->connection_id->toBe($this->connection->id)
        ->department_id->toBe($this->sales->id)
        ->status->toBe(ConversationStatus::Pending)
        ->and($identity)->channel->toBe(ConnectionChannel::WebChat)->external_id->toStartWith('wv:')
        ->and(Contact::query()->sole())
        ->source->toBe(ContactSource::WebChat)
        ->name->toBe('Ana')
        ->email->toBe('ana@tienda.test')
        ->phone_e164->toBeNull()
        ->and(Message::query()->sole())
        ->whatsapp_message_id->toStartWith('web.in.')
        ->status->toBe(MessageStatus::Received)
        ->and($this->connection->refresh()->last_inbound_at)->not->toBeNull()
        ->and($this->agent->notifications()->pluck('kind')->all())->toContain(NotificationKind::ConversationPending->value)
        ->and($response->json('data'))->not->toHaveKeys(['id', 'conversation_id', 'metadata', 'error_code', 'sender_user_id']);

    $this->withToken($this->token)->getJson(route('api.v1.widget.session.show'))
        ->assertJsonPath('data.conversation', ['status' => 'pending', 'agent_name' => null]);
});

test('an automatic department assigns the conversation on arrival', function () {
    $this->sales->forceFill(['distribution_mode' => DistributionMode::Automatic])->save();

    WebChatVisitor::send($this, $this->token)->assertCreated();

    expect(Conversation::query()->sole())
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($this->agent->id);
});

test('agents accept, reply and transfer as usual and the visitor reads the reply, never the notes', function () {
    WebChatVisitor::send($this, $this->token)->assertCreated();
    $conversation = Conversation::query()->sole();

    $this->actingAs($this->agent)->post(route('conversations.accept', $conversation))->assertSessionHasNoErrors();
    expect($conversation->refresh())->status->toBe(ConversationStatus::Open)->assigned_user_id->toBe($this->agent->id);

    $this->actingAs($this->agent)
        ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Sí, enviamos a todo el país.', 'client_reference' => (string) Str::uuid()])
        ->assertStatus(202);
    ConversationNote::factory()->create(['conversation_id' => $conversation->id, 'body' => 'Nota interna confidencial']);

    $reply = Message::query()->where('sender_user_id', $this->agent->id)->sole();
    expect($reply)->status->toBe(MessageStatus::Accepted)->whatsapp_message_id->toStartWith('web.out.');

    $history = WebChatVisitor::messages($this, $this->token)->assertOk();
    expect($history->json('data.1'))->toMatchArray([
        'direction' => 'outbound',
        'author' => 'agent',
        'agent_name' => $this->agent->name,
        'body' => 'Sí, enviamos a todo el país.',
        'attachments' => [],
    ])->and($history->getContent())->not->toContain('Nota interna');

    $this->withToken($this->token)->getJson(route('api.v1.widget.session.show'))
        ->assertJsonPath('data.conversation', ['status' => 'open', 'agent_name' => $this->agent->name]);

    $this->actingAs($this->agent)
        ->post(route('conversations.transfer', $conversation), ['department_id' => $this->support->id, 'version' => $conversation->refresh()->version])
        ->assertSessionHasNoErrors();
    expect($conversation->refresh()->department_id)->toBe($this->support->id);
});

test('an agent reply still queued is not shown to the visitor', function () {
    WebChatVisitor::send($this, $this->token)->assertCreated();
    $conversation = Conversation::query()->sole();
    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'outbound', 'status' => 'queued', 'body' => 'Borrador en cola']);
    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'outbound', 'status' => 'failed', 'body' => 'Envío fallido']);

    WebChatVisitor::messages($this, $this->token)->assertOk()->assertJsonCount(1, 'data');
});

test('repeating a client message id stores the message once and answers the same message', function () {
    $id = (string) Str::uuid();

    $first = WebChatVisitor::send($this, $this->token, 'Hola', $id)->assertCreated();
    $second = WebChatVisitor::send($this, $this->token, 'Hola', $id)->assertOk();

    expect($second->json('data.cursor'))->toBe($first->json('data.cursor'))
        ->and(Message::query()->count())->toBe(1);
});

test('a session never reads another session nor another connection', function () {
    WebChatVisitor::send($this, $this->token, 'Mensaje de Ana')->assertCreated();
    $other = WebChatVisitor::token($this, $this->connection);
    $otherConnection = WebChatFixtures::connection();
    $foreign = WebChatVisitor::token($this, $otherConnection);
    WebChatVisitor::send($this, $foreign, 'Mensaje en otra conexión')->assertCreated();

    WebChatVisitor::messages($this, $other)->assertOk()->assertJsonCount(0, 'data');
    WebChatVisitor::messages($this, $foreign)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Mensaje en otra conexión');

    expect(Conversation::query()->where('connection_id', $this->connection->id)->count())->toBe(1)
        ->and(Conversation::query()->where('connection_id', $otherConnection->id)->count())->toBe(1);
});

test('a closed conversation is reopened within the connection window, otherwise a new one starts', function (?int $window, int $minutesLater, int $conversations) {
    $this->connection->forceFill(['reopen_window_minutes' => $window])->save();
    WebChatVisitor::send($this, $this->token)->assertCreated();
    Conversation::query()->sole()->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now()])->save();

    $this->travel($minutesLater)->minutes();
    WebChatVisitor::send($this, $this->token, 'Vuelvo a escribir')->assertCreated();

    expect(Conversation::query()->count())->toBe($conversations)
        ->and(Conversation::query()->notClosed()->count())->toBe(1);
})->with([
    'within the window' => [60, 30, 1],
    'after the window' => [60, 90, 2],
]);

test('a deactivated connection keeps the session but refuses new messages', function () {
    $this->connection->forceFill(['is_active' => false])->save();

    WebChatVisitor::send($this, $this->token)->assertStatus(409)->assertJson(['code' => 'widget_unavailable']);
    $this->withToken($this->token)->getJson(route('api.v1.widget.session.show'))
        ->assertOk()
        ->assertJsonPath('data.availability.online', false);

    expect(Message::query()->count())->toBe(0);
});

test('the message is plain text within limits and the honeypot must stay empty', function (array $body, string $field) {
    $this->withToken($this->token)
        ->postJson(route('api.v1.widget.session.messages.store'), ['client_message_id' => (string) Str::uuid(), 'body' => 'Hola', ...$body])
        ->assertUnprocessable()
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonValidationErrors($field);

    expect(Message::query()->count())->toBe(0);
})->with([
    'honeypot filled' => [['website' => 'https://spam.test'], 'website'],
    'too long' => [['body' => str_repeat('a', 2001)], 'body'],
    'only blanks' => [['body' => "  \n\t "], 'body'],
    'not a uuid' => [['client_message_id' => 'abc'], 'client_message_id'],
]);

test('control characters are removed and the text trimmed', function () {
    WebChatVisitor::send($this, $this->token, "  Hola\u{0007}\r\nmundo\u{200B}  ")->assertCreated();

    expect(Message::query()->sole()->body)->toBe("Hola\nmundo");
});

test('receipts move the agent replies of the session forward, never other messages', function () {
    WebChatVisitor::send($this, $this->token)->assertCreated();
    $conversation = Conversation::query()->sole();
    $reply = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'outbound', 'status' => 'accepted', 'whatsapp_message_id' => 'web.out.'.strtolower((string) Str::ulid()), 'body' => 'Hola']);
    $otherToken = WebChatVisitor::token($this, $this->connection);
    WebChatVisitor::send($this, $otherToken)->assertCreated();
    $foreign = Message::factory()->create(['conversation_id' => Conversation::query()->latest('id')->value('id'), 'direction' => 'outbound', 'status' => 'accepted', 'whatsapp_message_id' => 'web.out.'.strtolower((string) Str::ulid()), 'body' => 'Otro']);

    $cursor = collect(WebChatVisitor::messages($this, $this->token)->json('data'))->last()['cursor'];

    $this->withToken($this->token)->postJson(route('api.v1.widget.session.receipts.store'), ['read_up_to' => $cursor])->assertNoContent();

    expect($reply->refresh())->status->toBe(MessageStatus::Read)->delivered_at->not->toBeNull()->read_at->not->toBeNull()
        ->and($foreign->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('one receipt with delivered and read writes a single status change per reply', function () {
    WebChatVisitor::send($this, $this->token)->assertCreated();
    $conversation = Conversation::query()->sole();
    $replies = collect(range(1, 3))->map(fn () => Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'outbound', 'status' => 'accepted', 'whatsapp_message_id' => 'web.out.'.strtolower((string) Str::ulid()), 'body' => 'Hola']));
    $cursor = collect(WebChatVisitor::messages($this, $this->token)->json('data'))->last()['cursor'];
    $changes = [];
    Event::listen(MessageStatusChanged::class, function (MessageStatusChanged $event) use (&$changes): void {
        $changes[] = [$event->messageId, $event->status];
    });

    $this->withToken($this->token)->postJson(route('api.v1.widget.session.receipts.store'), ['delivered_up_to' => $cursor, 'read_up_to' => $cursor])->assertNoContent();

    expect($changes)->toEqualCanonicalizing($replies->map(fn (Message $reply) => [$reply->id, 'read'])->all())
        ->and($replies->every(fn (Message $reply) => $reply->refresh()->delivered_at !== null && $reply->status === MessageStatus::Read))->toBeTrue();
});

test('a web chat conversation stays within the department it was routed to', function () {
    $outsider = User::factory()->agent()->available()->inDepartments($this->support)->create();
    WebChatVisitor::send($this, $this->token)->assertCreated();
    $conversation = Conversation::query()->sole();

    $listed = fn (User $viewer) => collect($this->actingAs($viewer)->getJson(route('conversations.page', ['limit' => 100]))->assertOk()->json('data'))->pluck('id')->all();
    $counts = fn (User $viewer) => $this->actingAs($viewer)->get(route('conversations.index'))->viewData('page')['props']['counts'];

    expect($listed($outsider))->not->toContain($conversation->id)
        ->and($counts($outsider)['pending'])->toBe(0)
        ->and($listed($this->agent))->toContain($conversation->id)
        ->and($counts($this->agent)['pending'])->toBe(1);

    $this->actingAs($outsider)->get(route('conversations.show', $conversation))->assertNotFound();
    $this->actingAs($outsider)->post(route('conversations.accept', $conversation));
    expect($conversation->refresh())->status->toBe(ConversationStatus::Pending)->assigned_user_id->toBeNull();

    $this->actingAs($this->agent)->post(route('conversations.accept', $conversation))->assertSessionHasNoErrors();
    expect($conversation->refresh()->assigned_user_id)->toBe($this->agent->id);
});

test('a forged cursor is refused', function () {
    WebChatVisitor::messages($this, $this->token, ['after' => base64_encode('1|0000000000000000')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('after');
});

test('polling with the last ETag answers 304 until something changes', function () {
    WebChatVisitor::send($this, $this->token)->assertCreated();
    $first = WebChatVisitor::messages($this, $this->token)->assertOk();
    $etag = $first->headers->get('ETag');

    $this->withToken($this->token)->withHeader('If-None-Match', $etag)
        ->getJson(route('api.v1.widget.session.messages.index'))
        ->assertStatus(304);

    Conversation::query()->sole()->forceFill(['assigned_user_id' => $this->agent->id, 'status' => ConversationStatus::Open])->save();

    $this->withToken($this->token)->withHeader('If-None-Match', $etag)
        ->getJson(route('api.v1.widget.session.messages.index'))
        ->assertOk();
});

test('after a cursor only newer messages come, plus replies that became visible late', function () {
    WebChatVisitor::send($this, $this->token, 'Uno')->assertCreated();
    $conversation = Conversation::query()->sole();
    $late = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'outbound', 'status' => 'queued', 'body' => 'Respuesta tardía']);
    $cursor = WebChatVisitor::send($this, $this->token, 'Dos')->assertCreated()->json('data.cursor');

    WebChatVisitor::messages($this, $this->token, ['after' => $cursor])->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.next_cursor', $cursor);

    $this->travel(1)->seconds();
    $late->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
    $three = WebChatVisitor::send($this, $this->token, 'Tres')->assertCreated()->json('data.cursor');

    $page = WebChatVisitor::messages($this, $this->token, ['after' => $cursor])->assertOk();
    expect(collect($page->json('data'))->pluck('body')->all())->toBe(['Respuesta tardía', 'Tres'])
        ->and($page->json('meta.next_cursor'))->toBe($three);
});
