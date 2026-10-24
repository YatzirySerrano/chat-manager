<?php

use App\Models\Conversation;
use App\Models\Message;
use Tests\Support\WebChatFixtures;

/*
| The integration API limits are counted per token (authentication runs
| before the named throttle), with the /api/v1 429 body and Retry-After.
*/

beforeEach(function () {
    config(['integrations.limits.writes_per_minute' => 2, 'integrations.limits.reads_per_minute' => 3]);

    $this->connection = WebChatFixtures::connection();
    $this->conversation = Conversation::factory()->create(['connection_id' => $this->connection->id]);
    ['plain' => $this->plain] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);
    ['plain' => $this->otherPlain] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);
    $this->send = fn (string $plain, string $key) => $this->withToken($plain)->postJson(
        route('api.v1.conversations.messages.store', ['conversation' => $this->conversation->id]),
        ['text' => 'Hola '.$key],
        ['Idempotency-Key' => $key],
    );
});

test('sending beyond api-v1-write is a 429 with Retry-After, counted per token', function () {
    ($this->send)($this->plain, 'envio-0001')->assertCreated();
    ($this->send)($this->plain, 'envio-0002')->assertCreated();

    $limited = ($this->send)($this->plain, 'envio-0003');

    $limited->assertTooManyRequests()
        ->assertExactJson(['message' => 'Demasiadas solicitudes. Inténtalo de nuevo más tarde.', 'code' => 'rate_limited']);
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Message::query()->count())->toBe(2);

    ($this->send)($this->otherPlain, 'envio-0003')->assertCreated();
});

test('reads beyond api-v1-read are a 429', function () {
    foreach (range(1, 3) as $attempt) {
        $this->withToken($this->plain)->getJson(route('api.v1.health'))->assertOk();
    }

    $this->withToken($this->plain)->getJson(route('api.v1.health'))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});
