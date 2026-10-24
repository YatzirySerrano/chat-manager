<?php

use App\Actions\Conversations\QueueOutboundMessage;
use App\Enums\ApiAbility;
use App\Enums\MessageStatus;
use App\Http\Resources\ConversationMessageResource;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\WebChatFixtures;
use Tests\Support\WhatsAppFixtures;

/*
| POST /api/v1/conversations/{conversation}/messages: the message goes
| through the same queue and delivery job as an agent reply, without a human
| author, with an Idempotency-Key namespaced by token and a text fingerprint.
*/

beforeEach(function () {
    $this->transport = WhatsAppFixtures::transport();
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->connection = Connection::factory()->verified()->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create([
        'connection_id' => $this->connection->id,
        'last_inbound_at' => now()->subHour(),
    ]);
    ContactIdentity::factory()->create(['contact_id' => $this->conversation->contact_id, 'external_id' => '5215511112222']);
    ['token' => $this->token, 'plain' => $this->plain] = WebChatFixtures::apiToken([
        'name' => 'CRM de ventas',
        'connection_ids' => [$this->connection->id],
    ]);
    $this->send = fn (Conversation $conversation, string $text = 'Su pedido va en camino', string $key = 'pedido-1001', ?string $plain = null) => $this
        ->withToken($plain ?? $this->plain)
        ->postJson(route('api.v1.conversations.messages.store', ['conversation' => $conversation->id]), ['text' => $text], ['Idempotency-Key' => $key]);
});

test('a WhatsApp message is queued, delivered and marked as sent by the integration', function () {
    $firstResponseAt = $this->conversation->first_response_at;

    $response = ($this->send)($this->conversation);

    $response->assertCreated()
        ->assertJsonPath('data.body', 'Su pedido va en camino')
        ->assertJsonPath('data.direction', 'outbound')
        ->assertJsonPath('data.author', 'integration')
        ->assertJsonPath('data.conversation_id', $this->conversation->id);

    $message = Message::query()->findOrFail($response->json('data.id'));

    expect($this->transport->sent)->toBe([$message->id])
        ->and($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->sender_user_id)->toBeNull()
        ->and($message->metadata['integration'])->toMatchArray(['token_id' => $this->token->id, 'name' => 'CRM de ventas', 'fingerprint' => hash('sha256', 'Su pedido va en camino')])
        ->and($this->conversation->refresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($this->conversation->first_response_at?->toIso8601String())->toBe($firstResponseAt?->toIso8601String());
});

test('the agent chat names the integration that sent the message', function () {
    $id = ($this->send)($this->conversation)->assertCreated()->json('data.id');

    $resource = ConversationMessageResource::make(Message::query()->with('attachments')->findOrFail($id))->resolve(request());

    expect($resource['integration'])->toBe(['name' => 'CRM de ventas']);
});

test('a web chat message is delivered by the web chat transport even after 24 hours', function () {
    $webChat = WebChatFixtures::connection();
    $conversation = Conversation::factory()->create(['connection_id' => $webChat->id, 'last_inbound_at' => now()->subDays(3)]);
    $this->token->forceFill(['connection_ids' => [$this->connection->id, $webChat->id]])->save();

    $response = ($this->send)($conversation, 'Seguimos atentos');

    $response->assertCreated();

    $message = Message::query()->findOrFail($response->json('data.id'));

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->whatsapp_message_id)->toStartWith('web.out.')
        ->and($this->transport->sent)->toBe([]);
});

test('a retry with the same key and text answers the same message', function () {
    $first = ($this->send)($this->conversation)->assertCreated();
    $retry = ($this->send)($this->conversation)->assertOk();

    expect($retry->json('data.id'))->toBe($first->json('data.id'))
        ->and(Message::query()->count())->toBe(1)
        ->and($this->transport->sent)->toHaveCount(1);
});

test('reusing a key with another text or conversation is a conflict', function () {
    $other = Conversation::factory()->create(['connection_id' => $this->connection->id, 'last_inbound_at' => now()->subHour()]);
    ($this->send)($this->conversation)->assertCreated();

    ($this->send)($this->conversation, 'Otro texto')
        ->assertStatus(409)
        ->assertExactJson(['message' => 'La clave de idempotencia ya se usó con otra solicitud.', 'code' => 'idempotency_key_reused']);

    ($this->send)($other)
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_reused');

    expect(Message::query()->count())->toBe(1);
});

test('a conversation transferred out of the token reach while the request waited is a 404', function () {
    $this->token->forceFill(['department_ids' => [$this->department->id]])->save();
    $stale = Conversation::query()->findOrFail($this->conversation->id);
    Conversation::query()->whereKey($stale->id)->update(['department_id' => Department::factory()->create()->id]);

    expect(fn () => app(QueueOutboundMessage::class)->handleForIntegration($stale, $this->token->refresh(), 'Hola', 'pedido-2002'))
        ->toThrow(ModelNotFoundException::class);
    expect(Message::query()->count())->toBe(0);
});

test('the same key used by another token creates another message', function () {
    ['plain' => $otherPlain] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);

    $first = ($this->send)($this->conversation)->assertCreated();
    $second = ($this->send)($this->conversation, plain: $otherPlain)->assertCreated();

    expect($second->json('data.id'))->not->toBe($first->json('data.id'));
});

test('a closed conversation, a closed WhatsApp window or an unavailable connection is a conflict', function (Closure $arrange, string $code) {
    $arrange($this);

    ($this->send)($this->conversation->refresh())
        ->assertStatus(409)
        ->assertJsonPath('code', $code);

    expect(Message::query()->count())->toBe(0);
})->with([
    'closed' => [fn ($test) => $test->conversation->forceFill(['status' => 'closed', 'closed_at' => now(), 'assigned_user_id' => null])->save(), 'conversation_closed'],
    'window closed' => [fn ($test) => $test->conversation->forceFill(['last_inbound_at' => now()->subHours(25)])->save(), 'customer_window_closed'],
    'connection inactive' => [fn ($test) => $test->connection->forceFill(['is_active' => false])->save(), 'connection_unavailable'],
]);

test('sending requires the messages:send ability', function () {
    $this->token->forceFill(['abilities' => [ApiAbility::ConversationsRead->value, ApiAbility::MessagesRead->value]])->save();

    ($this->send)($this->conversation)->assertForbidden()->assertJsonPath('code', 'forbidden');

    expect(Message::query()->count())->toBe(0);
});

test('invalid requests are validation errors', function (array $body, array $headers, string $field) {
    $this->withToken($this->plain)
        ->postJson(route('api.v1.conversations.messages.store', ['conversation' => $this->conversation->id]), $body, $headers)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors($field);

    expect(Message::query()->count())->toBe(0);
})->with([
    'missing key' => [['text' => 'Hola'], [], 'idempotency_key'],
    'short key' => [['text' => 'Hola'], ['Idempotency-Key' => 'abc'], 'idempotency_key'],
    'key with symbols' => [['text' => 'Hola'], ['Idempotency-Key' => 'pedido 1001!'], 'idempotency_key'],
    'missing text' => [[], ['Idempotency-Key' => 'pedido-1001'], 'text'],
    'blank text' => [['text' => '   '], ['Idempotency-Key' => 'pedido-1001'], 'text'],
    'text too long' => [['text' => str_repeat('a', 4097)], ['Idempotency-Key' => 'pedido-1001'], 'text'],
]);

test('errors never echo the token or provider details', function () {
    $this->connection->forceFill(['is_active' => false])->save();

    $response = ($this->send)($this->conversation->refresh());

    expect($response->getContent())->not->toContain($this->plain)
        ->and($response->getContent())->not->toContain((string) $this->connection->access_token)
        ->and($response->getContent())->not->toContain($this->connection->name);
});
