<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WebChatFixtures;

/*
| Administración > Integraciones: issuing (secret shown once, only its hash
| stored), listing and revoking integration API tokens, all audited without
| the secret.
*/

beforeEach(function () {
    $this->admin = User::factory()->administrator()->create();
    $this->connection = Connection::factory()->verified()->create(['name' => 'Ventas']);
    $this->payload = [
        'name' => 'CRM de ventas',
        'abilities' => ['conversations:read', 'messages:send'],
        'connection_ids' => [$this->connection->id],
        'allowed_ips' => "203.0.113.10\n198.51.100.0/24",
        'expires_at' => now()->addDays(30)->toDateString(),
    ];
});

test('people without the integrations permissions cannot see or manage tokens', function () {
    $agent = User::factory()->agent()->create();
    ['token' => $token] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);

    $this->actingAs($agent)->get(route('integrations.tokens.index'))->assertForbidden();
    $this->actingAs($agent)->post(route('integrations.tokens.store'), $this->payload)->assertForbidden();
    $this->actingAs($agent)->delete(route('integrations.tokens.destroy', $token))->assertForbidden();

    expect(ApiToken::query()->count())->toBe(1)
        ->and($token->refresh()->revoked_at)->toBeNull();
});

test('viewing tokens does not allow issuing or revoking them', function () {
    $viewer = conversationUser([Permission::ViewIntegrations], DataScope::Global);
    ['token' => $token] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);

    $this->actingAs($viewer)
        ->get(route('integrations.tokens.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/integrations/Index')
            ->where('can.manage', false)
            ->where('tokens.data.0.id', $token->id));

    $this->actingAs($viewer)->post(route('integrations.tokens.store'), $this->payload)->assertForbidden();
    $this->actingAs($viewer)->delete(route('integrations.tokens.destroy', $token))->assertForbidden();
});

test('issuing a token shows its secret once and stores only its hash', function () {
    $response = $this->actingAs($this->admin)->post(route('integrations.tokens.store'), $this->payload);

    $response->assertRedirect(route('integrations.tokens.index'))
        ->assertInertiaFlash('issued_token.name', 'CRM de ventas');

    $plain = session()->get(SessionKey::FLASH_DATA)['issued_token']['token'];
    $token = ApiToken::query()->sole();

    expect($plain)->toStartWith('cm_live_'.$token->public_id.'_')
        ->and($token->token_hash)->toBe(hash('sha256', substr($plain, strlen('cm_live_'.$token->public_id.'_'))))
        ->and($token->abilities)->toBe(['conversations:read', 'messages:send'])
        ->and($token->connection_ids)->toBe([$this->connection->id])
        ->and($token->department_ids)->toBeNull()
        ->and($token->allowed_ips)->toBe(['203.0.113.10', '198.51.100.0/24'])
        ->and($token->expires_at->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and($token->created_by)->toBe($this->admin->id);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->withToken($plain)->getJson(route('api.v1.health'))->assertOk();

    $this->actingAs($this->admin)
        ->get(route('integrations.tokens.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tokens.data.0.prefix', 'cm_live_'.$token->public_id)
            ->where('tokens.data.0.status', 'active')
            ->where('tokens.data.0.connections', [['id' => $this->connection->id, 'name' => 'Ventas']]));

    $listing = $this->actingAs($this->admin)->get(route('integrations.tokens.index'));
    expect($listing->getContent())->not->toContain($plain)
        ->and($listing->getContent())->not->toContain($token->token_hash);
});

test('a token without expiry date lasts the default 90 days', function () {
    $this->actingAs($this->admin)
        ->post(route('integrations.tokens.store'), [...$this->payload, 'expires_at' => null])
        ->assertRedirect();

    expect(ApiToken::query()->sole()->expires_at->toDateString())->toBe(now()->addDays(90)->toDateString());
});

test('issuing validates abilities, connections, departments, IPs and expiry', function (Closure $override, string $field) {
    $deleted = Connection::factory()->create(['deleted_at' => now()]);
    $inactiveDepartment = Department::factory()->inactive()->create();

    $this->actingAs($this->admin)
        ->post(route('integrations.tokens.store'), [...$this->payload, ...$override($deleted, $inactiveDepartment)])
        ->assertSessionHasErrors($field);

    expect(ApiToken::query()->count())->toBe(0);
})->with([
    'no name' => [fn () => ['name' => ''], 'name'],
    'no abilities' => [fn () => ['abilities' => []], 'abilities'],
    'unknown ability' => [fn () => ['abilities' => ['contacts:delete']], 'abilities.0'],
    'no connections' => [fn () => ['connection_ids' => []], 'connection_ids'],
    'deleted connection' => [fn ($deleted) => ['connection_ids' => [$deleted->id]], 'connection_ids.0'],
    'inactive department' => [fn ($deleted, $department) => ['department_ids' => [$department->id]], 'department_ids.0'],
    'bad ip' => [fn () => ['allowed_ips' => '300.1.1.1'], 'allowed_ips.0'],
    'bad cidr' => [fn () => ['allowed_ips' => '10.0.0.0/33'], 'allowed_ips.0'],
    'past expiry' => [fn () => ['expires_at' => now()->toDateString()], 'expires_at'],
    'expiry beyond a year' => [fn () => ['expires_at' => now()->addDays(366)->toDateString()], 'expires_at'],
]);

test('revoking is immediate: the next API call is rejected', function () {
    ['token' => $token, 'plain' => $plain] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);
    $this->withToken($plain)->getJson(route('api.v1.health'))->assertOk();

    $this->actingAs($this->admin)
        ->delete(route('integrations.tokens.destroy', $token), ['reason' => 'El proveedor terminó el contrato'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    expect($token->refresh())
        ->revoked_at->not->toBeNull()
        ->revoked_by->toBe($this->admin->id)
        ->revoke_reason->toBe('El proveedor terminó el contrato');

    $this->withToken($plain)->getJson(route('api.v1.health'))->assertUnauthorized();

    $this->actingAs($this->admin)
        ->delete(route('integrations.tokens.destroy', $token))
        ->assertInertiaFlash('toast.type', 'info');

    expect(AuditLog::query()->where('action', AuditAction::ApiTokenRevoked)->count())->toBe(1);
});

test('issuing and revoking are audited without the secret', function () {
    $this->actingAs($this->admin)->post(route('integrations.tokens.store'), $this->payload);
    $plain = session()->get(SessionKey::FLASH_DATA)['issued_token']['token'];
    $token = ApiToken::query()->sole();

    $this->actingAs($this->admin)->delete(route('integrations.tokens.destroy', $token), ['reason' => 'Rotación']);

    $issued = AuditLog::query()->where('action', AuditAction::ApiTokenIssued)->sole();
    $revoked = AuditLog::query()->where('action', AuditAction::ApiTokenRevoked)->sole();

    expect($issued->user_id)->toBe($this->admin->id)
        ->and($issued->auditable_id)->toBe($token->id)
        ->and($issued->auditable_label)->toBe('Token de API «CRM de ventas»')
        ->and($issued->properties)->toMatchArray([
            'public_id' => $token->public_id,
            'name' => 'CRM de ventas',
            'abilities' => ['conversations:read', 'messages:send'],
            'connection_ids' => [$this->connection->id],
        ])
        ->and($revoked->properties)->toMatchArray(['public_id' => $token->public_id, 'reason' => 'Rotación']);

    foreach ([$issued, $revoked] as $entry) {
        $stored = json_encode($entry->getAttributes());

        expect($stored)->not->toContain(substr($plain, -40))
            ->and($stored)->not->toContain($token->token_hash);
    }
});
