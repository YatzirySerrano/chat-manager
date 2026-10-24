<?php

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use Illuminate\Support\Str;
use Tests\Support\WebChatFixtures;

/*
| Integration API tokens: the plain token is shown once and only its hash is
| kept; the token itself is the principal and carries its abilities, its
| connections and departments, and an optional IP allowlist.
*/

test('a token is issued as cm_live_{public_id}_{secret} and only its hash is stored', function () {
    ['token' => $token, 'plain' => $plain] = WebChatFixtures::apiToken();

    expect($plain)->toMatch('/^cm_live_[0-9a-z]{26}_[A-Za-z0-9]{40}$/')
        ->and($plain)->toStartWith('cm_live_'.$token->public_id.'_')
        ->and($token->refresh()->token_hash)->toBe(hash('sha256', substr($plain, -40)))
        ->and($token->toArray())->not->toHaveKey('token_hash')
        ->and(ApiToken::findByToken($plain)?->is($token))->toBeTrue();
});

test('a malformed, foreign or altered token is not found', function (Closure $mutate) {
    ['plain' => $plain] = WebChatFixtures::apiToken();

    expect(ApiToken::findByToken($mutate($plain)))->toBeNull();
})->with([
    'altered secret' => [fn (string $plain): string => substr($plain, 0, -1).(str_ends_with($plain, 'a') ? 'b' : 'a')],
    'visitor token prefix' => [fn (string $plain): string => 'wvs_'.substr($plain, 8)],
    'unknown public id' => [fn (string $plain): string => 'cm_live_'.strtolower((string) Str::ulid()).substr($plain, 34)],
    'truncated' => [fn (string $plain): string => substr($plain, 0, -5)],
    'empty' => [fn (): string => ''],
]);

test('only an unexpired, unrevoked token is usable', function (Closure $token, bool $usable) {
    expect($token()->isUsable())->toBe($usable);
})->with([
    'active' => [fn () => ApiToken::factory()->create(), true],
    'expired' => [fn () => ApiToken::factory()->expired()->create(), false],
    'revoked' => [fn () => ApiToken::factory()->revoked('Filtrado')->create(), false],
]);

test('a token only holds the abilities it was granted', function () {
    $token = ApiToken::factory()->abilities([ApiAbility::ConversationsRead])->create();

    expect($token->allows(ApiAbility::ConversationsRead))->toBeTrue()
        ->and($token->allows(ApiAbility::MessagesRead))->toBeFalse()
        ->and($token->allows(ApiAbility::MessagesSend))->toBeFalse();
});

test('the IP allowlist accepts addresses and CIDR ranges of IPv4 and IPv6', function (?array $allowed, string $ip, bool $expected) {
    $token = ApiToken::factory()->make(['allowed_ips' => $allowed]);

    expect($token->allowsIp($ip))->toBe($expected);
})->with([
    'no allowlist' => [null, '203.0.113.7', true],
    'exact IPv4' => [['203.0.113.7'], '203.0.113.7', true],
    'other IPv4' => [['203.0.113.7'], '203.0.113.8', false],
    'IPv4 range' => [['10.20.0.0/16'], '10.20.200.1', true],
    'outside IPv4 range' => [['10.20.0.0/16'], '10.21.0.1', false],
    'IPv6 range' => [['2001:db8::/32'], '2001:db8:abcd::1', true],
    'outside IPv6 range' => [['2001:db8::/32'], '2001:db9::1', false],
]);

test('the token reaches only the conversations of its connections and departments', function () {
    $allowedConnection = Connection::factory()->create();
    $otherConnection = Connection::factory()->create();
    $sales = Department::factory()->create();
    $support = Department::factory()->create();
    $inSales = Conversation::factory()->inDepartment($sales)->create(['connection_id' => $allowedConnection->id]);
    $inSupport = Conversation::factory()->inDepartment($support)->create(['connection_id' => $allowedConnection->id]);
    Conversation::factory()->inDepartment($sales)->create(['connection_id' => $otherConnection->id]);

    $byConnection = ApiToken::factory()->create(['connection_ids' => [$allowedConnection->id]]);
    $byDepartment = ApiToken::factory()->create(['connection_ids' => [$allowedConnection->id], 'department_ids' => [$sales->id]]);
    $withoutConnections = ApiToken::factory()->create(['connection_ids' => []]);

    expect($byConnection->scopeConversations(Conversation::query())->orderBy('id')->pluck('id')->all())->toBe([$inSales->id, $inSupport->id])
        ->and($byDepartment->scopeConversations(Conversation::query())->pluck('id')->all())->toBe([$inSales->id])
        ->and($withoutConnections->scopeConversations(Conversation::query())->exists())->toBeFalse();
});
