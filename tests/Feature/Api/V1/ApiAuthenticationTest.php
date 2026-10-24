<?php

use App\Enums\ApiAbility;
use App\Models\Connection;
use Tests\Support\WebChatFixtures;

/*
| Integration API authentication: Bearer cm_live_… tokens that expire, can
| be revoked and may be limited to some IPs. Every failure is the same 401,
| and a missing ability is a 403.
*/

beforeEach(function () {
    $this->connection = Connection::factory()->verified()->create(['name' => 'Ventas']);
    ['token' => $this->token, 'plain' => $this->plain] = WebChatFixtures::apiToken([
        'name' => 'CRM',
        'connection_ids' => [$this->connection->id],
    ]);
});

test('requests without a usable token are a uniform 401 without reason', function (Closure $authorization) {
    $header = $authorization($this);

    $response = $this->getJson(route('api.v1.health'), $header === null ? [] : ['Authorization' => $header]);

    $response->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertExactJson(['message' => 'No autenticado.', 'code' => 'unauthenticated']);
})->with([
    'no header' => [fn () => null],
    'not bearer' => [fn ($test) => 'Basic '.base64_encode('a:b')],
    'malformed token' => [fn () => 'Bearer cm_live_nope'],
    'visitor session token' => [fn ($test) => 'Bearer '.WebChatFixtures::session(WebChatFixtures::connection())['token']],
    'wrong secret' => [fn ($test) => 'Bearer '.substr($test->plain, 0, -4).'abcd'],
    'expired' => [function ($test) {
        $test->token->forceFill(['expires_at' => now()->subSecond()])->save();

        return 'Bearer '.$test->plain;
    }],
    'revoked' => [function ($test) {
        $test->token->forceFill(['revoked_at' => now()])->save();

        return 'Bearer '.$test->plain;
    }],
    'ip outside the allowlist' => [function ($test) {
        $test->token->forceFill(['allowed_ips' => ['203.0.113.0/24']])->save();

        return 'Bearer '.$test->plain;
    }],
]);

test('a token restricted by IP works from an allowed address', function () {
    $this->token->forceFill(['allowed_ips' => ['203.0.113.0/24', '2001:db8::/32']])->save();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
        ->withToken($this->plain)
        ->getJson(route('api.v1.health'))
        ->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::5'])
        ->withToken($this->plain)
        ->getJson(route('api.v1.health'))
        ->assertOk();
});

test('a token without the ability of the route is forbidden', function () {
    $this->token->forceFill(['abilities' => [ApiAbility::MessagesRead->value]])->save();

    $this->withToken($this->plain)
        ->getJson(route('api.v1.conversations.index'))
        ->assertForbidden()
        ->assertExactJson(['message' => 'No tienes permiso para realizar esta acción.', 'code' => 'forbidden']);
});

test('health only exposes the token and its connections status', function () {
    $other = Connection::factory()->create();
    $webChat = WebChatFixtures::connection(connection: ['name' => 'Tienda']);
    $deleted = Connection::factory()->create(['deleted_at' => now()]);
    $this->token->forceFill([
        'connection_ids' => [$this->connection->id, $webChat->id, $deleted->id],
        'abilities' => [ApiAbility::ConversationsRead->value],
    ])->save();

    $response = $this->withToken($this->plain)->getJson(route('api.v1.health'));

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('api_version', 'v1')
        ->assertJsonPath('token', [
            'id' => $this->token->public_id,
            'name' => 'CRM',
            'abilities' => ['conversations:read'],
            'expires_at' => $this->token->expires_at->toIso8601String(),
        ])
        ->assertJsonPath('connections', [
            ['id' => $webChat->id, 'name' => 'Tienda', 'channel' => 'web_chat', 'operational' => true],
            ['id' => $this->connection->id, 'name' => 'Ventas', 'channel' => 'whatsapp', 'operational' => true],
        ]);

    expect(array_keys($response->json()))->toBe(['status', 'api_version', 'server_time', 'token', 'connections']);

    $body = $response->getContent();

    foreach ([$this->connection->phone_number_id, $this->connection->business_account_id, $this->connection->access_token, $this->connection->display_phone_number, $other->name, $this->token->token_hash] as $secret) {
        expect($body)->not->toContain((string) $secret);
    }
});

test('last use is recorded at most once per minute', function () {
    $this->travelTo(now()->startOfMinute());

    $this->withToken($this->plain)->getJson(route('api.v1.health'))->assertOk();
    $firstUse = $this->token->refresh()->last_used_at;

    $this->travel(30)->seconds();
    $this->withToken($this->plain)->getJson(route('api.v1.health'))->assertOk();

    expect($firstUse)->not->toBeNull()
        ->and($this->token->refresh()->last_used_at->equalTo($firstUse))->toBeTrue();

    $this->travel(2)->minutes();
    $this->withToken($this->plain)->getJson(route('api.v1.health'))->assertOk();

    expect($this->token->refresh()->last_used_at->greaterThan($firstUse))->toBeTrue();
});
