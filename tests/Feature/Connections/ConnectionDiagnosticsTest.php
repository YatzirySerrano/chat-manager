<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WhatsAppFixtures;

/*
| Activation diagnostics of a connection: webhook URL, evidence, and the
| subscription of the Meta app to the business account webhooks.
*/

const DIAGNOSTICS_TOKEN = 'EAAGdiagnosticsTestToken0000000000000000000000000000000';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta.graph_version' => 'v26.0']);
    $this->connection = Connection::factory()->verified()->create([
        'business_account_id' => '102290129340398',
        'meta_app_id' => '1234567890',
        'access_token' => DIAGNOSTICS_TOKEN,
    ]);
    $this->admin = User::factory()->superAdministrator()->create();
});

test('the connection page shows its webhook URL and evidence without any secret', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('connections.show', $this->connection))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('diagnostics.webhook.url', route('webhooks.whatsapp.verify', ['webhookKey' => $this->connection->webhook_key]))
            ->where('diagnostics.webhook.verify_token_saved', true)
            ->where('diagnostics.webhook.app_secret_saved', true)
            ->where('diagnostics.webhook.verified_at', null)
            ->where('diagnostics.subscription.state', 'unknown')
            ->where('diagnostics.transport.mode', 'unavailable'));

    expect($response->getContent())
        ->not->toContain(DIAGNOSTICS_TOKEN)
        ->not->toContain((string) $this->connection->app_secret)
        ->not->toContain((string) $this->connection->webhook_verify_token);
});

test('checking the subscription reads Meta and records the answer', function (array $apps, bool $subscribed) {
    Http::fake(['graph.facebook.com/v26.0/102290129340398/subscribed_apps' => Http::response(['data' => $apps])]);

    $this->actingAs($this->admin)
        ->post(route('connections.subscription.check', $this->connection))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', $subscribed ? 'success' : 'warning');

    expect($this->connection->refresh())
        ->app_subscribed->toBe($subscribed)
        ->subscription_checked_at->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionSubscriptionChecked)->exists())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.DIAGNOSTICS_TOKEN) && ! str_contains($request->url(), DIAGNOSTICS_TOKEN));
})->with([
    'our app' => [[['whatsapp_business_api_data' => ['id' => '1234567890', 'name' => 'Chat Manager', 'link' => 'https://example.com']]], true],
    'another app' => [[['whatsapp_business_api_data' => ['id' => '999', 'name' => 'Otra app']]], false],
    'none' => [[], false],
]);

test('subscribing the app is confirmed only when Meta says so', function () {
    Http::fake(['graph.facebook.com/v26.0/102290129340398/subscribed_apps' => Http::sequence()
        ->push(['success' => false])
        ->push(['success' => true])]);

    $this->actingAs($this->admin)->post(route('connections.subscription.store', $this->connection))->assertInertiaFlash('toast.type', 'warning');
    expect($this->connection->refresh()->app_subscribed)->toBeNull();

    $this->actingAs($this->admin)->post(route('connections.subscription.store', $this->connection))->assertInertiaFlash('toast.type', 'success');
    expect($this->connection->refresh()->app_subscribed)->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionAppSubscribed)->exists())->toBeTrue();
});

test('people who cannot manage the connection cannot check or subscribe', function () {
    Http::fake();
    $viewer = conversationUser([Permission::ViewConnections], DataScope::Global);

    $this->actingAs($viewer)->post(route('connections.subscription.check', $this->connection))->assertForbidden();
    $this->actingAs($viewer)->post(route('connections.subscription.store', $this->connection))->assertForbidden();
    Http::assertNothingSent();
});

test('a deleted connection cannot be subscribed', function () {
    Http::fake();
    $this->connection->forceFill(['deleted_at' => now(), 'is_active' => false])->save();

    $this->actingAs($this->admin)->post(route('connections.subscription.store', $this->connection))->assertForbidden();
    Http::assertNothingSent();
});

test('replies of unknown delivery are counted and their visible conversations listed', function () {
    // Without conversations.history the viewer does not see closed conversations.
    $viewer = User::factory()
        ->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConnections, Permission::ViewConversations])->create())
        ->create();
    $sales = Department::factory()->create();
    $unknown = ['status' => 'failed', 'error_code' => 'delivery_unknown', 'failed_at' => now()->subDay()];
    $visible = Conversation::factory()->inDepartment($sales)->create(['connection_id' => $this->connection->id]);
    $hidden = Conversation::factory()->inDepartment($sales)->create(['connection_id' => $this->connection->id, 'status' => 'closed', 'closed_at' => now()]);
    WhatsAppFixtures::outbound($visible, $unknown);
    WhatsAppFixtures::outbound($visible, $unknown);
    WhatsAppFixtures::outbound($hidden, $unknown);
    WhatsAppFixtures::outbound($visible, [...$unknown, 'failed_at' => now()->subDays(8)]);
    WhatsAppFixtures::outbound($visible, [...$unknown, 'error_code' => 'provider_rejected']);
    WhatsAppFixtures::outbound(Conversation::factory()->inDepartment($sales)->create(), $unknown);

    $this->actingAs($viewer)
        ->get(route('connections.show', $this->connection))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('diagnostics.outbound.unknown', 3)
            ->has('diagnostics.outbound.unknown_conversations', 1)
            ->where('diagnostics.outbound.unknown_conversations.0.id', $visible->id)
            ->where('diagnostics.outbound.unknown_conversations.0.messages', 2));
});

test('the controlled pilot is announced only when the real Meta transport applies it', function (string $transport, int $announced) {
    config(['whatsapp.transport' => $transport, 'whatsapp.pilot.recipients' => ['5215511112222', '5215599990000']]);

    $this->actingAs($this->admin)
        ->get(route('connections.show', $this->connection))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('diagnostics.transport.pilot_recipients', $announced));
})->with([
    'meta' => ['meta', 2],
    'simulated' => ['simulated', 0],
    'unavailable' => ['unavailable', 0],
]);
