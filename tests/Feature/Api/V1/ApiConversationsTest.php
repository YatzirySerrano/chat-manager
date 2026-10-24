<?php

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\WebChatFixtures;

/*
| Integration API reads: the token only reaches the conversations of its
| connections (and departments, when it lists them); anything else is the
| same 404 as a conversation that does not exist. Lists use cursors.
*/

beforeEach(function () {
    $this->connection = Connection::factory()->verified()->create();
    $this->otherConnection = Connection::factory()->verified()->create();
    ['token' => $this->token, 'plain' => $this->plain] = WebChatFixtures::apiToken([
        'connection_ids' => [$this->connection->id],
    ]);
    $this->api = fn () => $this->withToken($this->plain);
});

test('the list only includes conversations of the token connections and departments', function () {
    $sales = Department::factory()->create();
    $support = Department::factory()->create();
    $inSales = Conversation::factory()->inDepartment($sales)->create(['connection_id' => $this->connection->id]);
    $inSupport = Conversation::factory()->inDepartment($support)->create(['connection_id' => $this->connection->id]);
    Conversation::factory()->inDepartment($sales)->create(['connection_id' => $this->otherConnection->id]);

    expect(($this->api)()->getJson(route('api.v1.conversations.index'))->assertOk()->json('data.*.id'))
        ->toEqualCanonicalizing([$inSales->id, $inSupport->id]);

    $this->token->forceFill(['department_ids' => [$sales->id]])->save();

    expect(($this->api)()->getJson(route('api.v1.conversations.index'))->assertOk()->json('data.*.id'))
        ->toBe([$inSales->id]);
});

test('a conversation outside the token reach is the same 404 as a nonexistent one', function (string $route) {
    $department = Department::factory()->create();
    $foreign = Conversation::factory()->create(['connection_id' => $this->otherConnection->id]);
    $otherDepartment = Conversation::factory()->inDepartment(Department::factory()->create())->create(['connection_id' => $this->connection->id]);
    $this->token->forceFill(['department_ids' => [$department->id]])->save();

    $outside = ($this->api)()->getJson(route($route, ['conversation' => $foreign->id]));
    $otherScope = ($this->api)()->getJson(route($route, ['conversation' => $otherDepartment->id]));
    $missing = ($this->api)()->getJson(route($route, ['conversation' => $foreign->id + 1000]));

    $outside->assertNotFound();
    $otherScope->assertNotFound();
    $missing->assertNotFound();
    expect($outside->getContent())->toBe($missing->getContent())
        ->and($otherScope->getContent())->toBe($missing->getContent())
        ->and($outside->json())->toBe(['message' => 'No encontrado.', 'code' => 'not_found']);
})->with(['api.v1.conversations.show', 'api.v1.conversations.messages.index']);

test('sending to a conversation outside the token reach is a 404 and stores nothing', function () {
    $foreign = Conversation::factory()->create(['connection_id' => $this->otherConnection->id]);

    ($this->api)()->postJson(route('api.v1.conversations.messages.store', ['conversation' => $foreign->id]), ['text' => 'Hola'], ['Idempotency-Key' => 'pedido-0001'])
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');

    expect(Message::query()->count())->toBe(0);
});

test('deleting a connection cuts the token off from its conversations, like /health', function () {
    $conversation = Conversation::factory()->create(['connection_id' => $this->connection->id]);
    $this->connection->forceFill(['deleted_at' => now()])->save();

    ($this->api)()->getJson(route('api.v1.conversations.index'))->assertOk()->assertJsonCount(0, 'data');
    ($this->api)()->getJson(route('api.v1.conversations.show', ['conversation' => $conversation->id]))->assertNotFound();
    ($this->api)()->getJson(route('api.v1.conversations.messages.index', ['conversation' => $conversation->id]))->assertNotFound();
    ($this->api)()->getJson(route('api.v1.health'))->assertOk()->assertJsonCount(0, 'connections');
});

test('a conversation shows its attention data without contact details', function () {
    $department = Department::factory()->create(['name' => 'Ventas']);
    $agent = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana López']);
    $contact = Contact::factory()->create(['name' => 'Laura Pérez', 'email' => 'laura@example.com', 'phone_e164' => '+5215512345678']);
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
        'connection_id' => $this->connection->id,
        'contact_id' => $contact->id,
    ]);
    ConversationNote::factory()->create(['conversation_id' => $conversation->id, 'body' => 'Nota interna confidencial']);

    $response = ($this->api)()->getJson(route('api.v1.conversations.show', ['conversation' => $conversation->id]));

    $response->assertOk()
        ->assertJsonPath('data.id', $conversation->id)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.status_label', 'En atención')
        ->assertJsonPath('data.connection', ['id' => $this->connection->id, 'name' => $this->connection->name, 'channel' => 'whatsapp'])
        ->assertJsonPath('data.department', ['id' => $department->id, 'name' => 'Ventas'])
        ->assertJsonPath('data.contact', ['id' => $contact->id, 'display_name' => 'Laura Pérez'])
        ->assertJsonPath('data.assigned_agent', ['id' => $agent->id, 'name' => 'Ana López']);

    expect(array_keys($response->json('data')))->toBe(['id', 'status', 'status_label', 'connection', 'department', 'contact', 'assigned_agent', 'last_message_at', 'created_at', 'updated_at', 'closed_at']);

    foreach (['laura@example.com', '5512345678', 'Nota interna', $agent->email] as $private) {
        expect($response->getContent())->not->toContain($private);
    }
});

test('filters narrow the list', function () {
    $department = Department::factory()->create();
    $pending = Conversation::factory()->create(['connection_id' => $this->connection->id, 'updated_at' => now()->subDays(3)]);
    $open = Conversation::factory()->inDepartment($department)->assignedTo(User::factory()->agent()->create())->create(['connection_id' => $this->connection->id]);
    $closed = Conversation::factory()->closed()->create(['connection_id' => $this->connection->id]);

    $ids = fn (array $query): array => ($this->api)()->getJson(route('api.v1.conversations.index', $query))->assertOk()->json('data.*.id');

    expect($ids(['status' => 'pending']))->toBe([$pending->id])
        ->and($ids(['status' => 'closed']))->toBe([$closed->id])
        ->and($ids(['department_id' => $department->id]))->toBe([$open->id])
        ->and($ids(['connection_id' => $this->otherConnection->id]))->toBe([])
        ->and($ids(['updated_since' => now()->subDay()->toIso8601String()]))->toEqualCanonicalizing([$open->id, $closed->id]);
});

test('the cursor walks every conversation once, most recently updated first', function () {
    $conversations = collect(range(1, 7))->map(fn (int $minutes): Conversation => Conversation::factory()->create([
        'connection_id' => $this->connection->id,
        'updated_at' => now()->subMinutes($minutes % 3),
    ]));

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $response = ($this->api)()->getJson(route('api.v1.conversations.index', array_filter(['limit' => 3, 'cursor' => $cursor])))->assertOk();
        $seen = [...$seen, ...$response->json('data.*.id')];
        $cursor = $response->json('meta.next_cursor');
        $pages++;
        expect($response->json('meta.limit'))->toBe(3);
    } while ($cursor !== null && $pages < 10);

    $expected = $conversations->sortBy([['updated_at', 'desc'], ['id', 'desc']])->pluck('id')->all();

    expect($pages)->toBe(3)
        ->and($seen)->toBe($expected);
});

test('invalid filters and cursors are validation errors', function (array $query, string $field) {
    ($this->api)()->getJson(route('api.v1.conversations.index', $query))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors($field);
})->with([
    'status' => [['status' => 'archived'], 'status'],
    'limit too high' => [['limit' => 101], 'limit'],
    'limit zero' => [['limit' => 0], 'limit'],
    'date' => [['updated_since' => 'ayer'], 'updated_since'],
    'cursor' => [['cursor' => base64_encode('{"foo":1}')], 'cursor'],
]);

test('messages exclude internal notes, metadata and provider error details', function () {
    $conversation = Conversation::factory()->create(['connection_id' => $this->connection->id]);
    $inbound = Message::factory()->create(['conversation_id' => $conversation->id, 'body' => 'Hola, ¿tienen envío?']);
    $failed = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'status' => MessageStatus::Failed,
        'sender_user_id' => User::factory()->agent()->create()->id,
        'body' => 'Sí, enviamos a todo el país',
        'error_code' => 'delivery_unknown',
        'error_message' => 'Graph API detalle interno #131000',
        'metadata' => ['delivery' => ['history' => [['secret' => 'x']]]],
    ]);
    (new MessageAttachment(['disk' => 'local', 'path' => 'media/2026/10/foto', 'file_name' => 'foto.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 2048, 'sha256' => str_repeat('a', 64)]))
        ->forceFill(['message_id' => $inbound->id])
        ->save();
    ConversationNote::factory()->create(['conversation_id' => $conversation->id, 'body' => 'Nota interna del equipo']);

    $response = ($this->api)()->getJson(route('api.v1.conversations.messages.index', ['conversation' => $conversation->id]));

    $response->assertOk()
        ->assertJsonPath('data.0.id', $inbound->id)
        ->assertJsonPath('data.0.author', 'contact')
        ->assertJsonPath('data.0.attachments.0.file_name', 'foto.jpg')
        ->assertJsonPath('data.1.id', $failed->id)
        ->assertJsonPath('data.1.author', 'agent')
        ->assertJsonPath('data.1.error_code', 'delivery_unknown')
        ->assertJsonPath('data.1.status_label', 'Entrega no confirmada')
        ->assertJsonCount(2, 'data');

    expect(array_keys($response->json('data.1')))->toBe(['id', 'conversation_id', 'direction', 'type', 'body', 'status', 'status_label', 'author', 'error_code', 'attachments', 'created_at'])
        ->and(array_keys($response->json('data.0.attachments.0')))->toBe(['id', 'file_name', 'mime_type', 'size_bytes'])
        ->and($response->getContent())->not->toContain('Nota interna')
        ->and($response->getContent())->not->toContain('131000')
        ->and($response->getContent())->not->toContain('history');
});

test('messages page forward with after and backward with before', function () {
    $conversation = Conversation::factory()->create(['connection_id' => $this->connection->id]);
    $ids = Message::factory()->count(5)->create(['conversation_id' => $conversation->id])->pluck('id')->all();
    $url = fn (array $query) => route('api.v1.conversations.messages.index', ['conversation' => $conversation->id, ...$query]);

    $first = ($this->api)()->getJson($url(['limit' => 2]))->assertOk();
    expect($first->json('data.*.id'))->toBe([$ids[0], $ids[1]])
        ->and($first->json('meta.next_cursor'))->toBe((string) $ids[1])
        ->and($first->json('meta.prev_cursor'))->toBeNull();

    $second = ($this->api)()->getJson($url(['limit' => 2, 'after' => $first->json('meta.next_cursor')]))->assertOk();
    expect($second->json('data.*.id'))->toBe([$ids[2], $ids[3]])
        ->and($second->json('meta.prev_cursor'))->toBe((string) $ids[2]);

    $last = ($this->api)()->getJson($url(['limit' => 2, 'after' => $second->json('meta.next_cursor')]))->assertOk();
    expect($last->json('data.*.id'))->toBe([$ids[4]])
        ->and($last->json('meta.next_cursor'))->toBeNull();

    $back = ($this->api)()->getJson($url(['limit' => 2, 'before' => $ids[4]]))->assertOk();
    expect($back->json('data.*.id'))->toBe([$ids[2], $ids[3]])
        ->and($back->json('meta.prev_cursor'))->toBe((string) $ids[2])
        ->and($back->json('meta.next_cursor'))->toBe((string) $ids[3]);
});

test('reads run a constant number of queries', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();

    $queries = function (int $conversations) use ($department, $agent): array {
        $created = Conversation::factory()->count($conversations)->inDepartment($department)->assignedTo($agent)->create(['connection_id' => $this->connection->id]);
        $created->each(fn (Conversation $conversation) => Message::factory()->count($conversations)->create(['conversation_id' => $conversation->id]));

        ($this->api)()->getJson(route('api.v1.health'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->api)()->getJson(route('api.v1.conversations.index'))->assertOk();
        $list = count(DB::getQueryLog());

        DB::flushQueryLog();
        ($this->api)()->getJson(route('api.v1.conversations.messages.index', ['conversation' => $created->last()->id]))->assertOk();
        $messages = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$list, $messages];
    };

    expect($queries(2))->toBe($queries(6));
});
