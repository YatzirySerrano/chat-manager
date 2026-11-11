<?php

use App\Actions\Connections\VerifyConnectionCredentials;
use App\Actions\Messaging\ProcessWebhookPayload;
use App\Enums\AuditAction;
use App\Enums\ConnectionHealth;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Role;
use App\Models\User;
use App\Notifications\NotificationCenter;
use App\Notifications\NotificationKind;
use App\Notifications\NotificationPresenter;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\ConnectionMonitoring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WhatsAppFixtures;

/*
| Monitoring of several connections: the scheduled check against Meta
| (whatsapp:maintenance check-connections), what the connections screens say
| about each one (recent evidence vs. history, never secrets) and the life
| of the problem alert. Every call to Meta is faked.
*/

const MONITORED_TOKEN = 'EAAGmonitoredSecretToken0987654321';
const MONITORED_APP_SECRET = 'monitored-app-secret-0123456789abcdef';
const MONITORED_VERIFY_TOKEN = 'monitored-verify-token-0123456789';

/**
 * An active, operational WhatsApp connection the scheduler checks.
 *
 * @param  array<string, mixed>  $attributes
 */
function monitoredConnection(array $attributes = [], string $state = 'operational'): Connection
{
    return Connection::factory()->{$state}()->create([
        'access_token' => MONITORED_TOKEN,
        'app_secret' => MONITORED_APP_SECRET,
        'webhook_verify_token' => MONITORED_VERIFY_TOKEN,
        ...$attributes,
    ]);
}

/**
 * Meta's answers for the connection: the phone number profile (or the given
 * response) and the apps subscribed to its business account.
 */
function fakeMonitoredMeta(Connection $connection, mixed $profile = null, mixed $subscription = null): void
{
    Http::fake([
        "graph.facebook.com/v26.0/{$connection->business_account_id}/subscribed_apps*" => $subscription ?? Http::response(['data' => [['whatsapp_business_api_data' => ['id' => '555', 'name' => 'Mi app']]]]),
        "graph.facebook.com/v26.0/{$connection->phone_number_id}*" => $profile ?? Http::response([
            'id' => $connection->phone_number_id, 'display_phone_number' => '+52 55 1234 5678', 'verified_name' => 'Mi Empresa',
            'quality_rating' => 'GREEN', 'code_verification_status' => 'VERIFIED', 'status' => 'CONNECTED', 'platform_type' => 'CLOUD_API',
        ]),
    ]);
}

function runConnectionCheck(mixed $test): void
{
    $test->artisan('whatsapp:maintenance', ['task' => 'check-connections'])->assertSuccessful();
}

/**
 * @return Collection<int, DatabaseNotification>
 */
function connectionAlertsOf(User $user)
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->id)
        ->where('kind', NotificationKind::ConnectionProblem->value)
        ->get();
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta.graph_version' => 'v26.0']);
    $this->admin = User::factory()->administrator()->create();
});

test('the scheduled check confirms credentials and subscription without noise in the audit log', function () {
    $connection = monitoredConnection(['credentials_verified_at' => now()->subDays(3)]);
    fakeMonitoredMeta($connection);
    $this->freezeSecond();

    runConnectionCheck($this);
    runConnectionCheck($this);

    expect($connection->refresh())
        ->credentials_verified_at->toEqual(now())
        ->subscription_checked_at->toEqual(now())
        ->app_subscribed->toBeTrue()
        ->and($connection->health())->toBe(ConnectionHealth::Operational)
        ->and($connection->provider_signals['platform.check']['value'])->toBe(['outcome' => 'verified'])
        // Confirming what was known is not an administrative action; the first answer about the subscription is.
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionCredentialsVerified)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionSubscriptionChecked)->sole())
        ->actor_name->toBe('Sistema')
        ->and(connectionAlertsOf($this->admin))->toBeEmpty();
});

test('credentials rejected by Meta turn the connection invalid and alert once, however many runs repeat it', function () {
    $connection = monitoredConnection();
    fakeMonitoredMeta($connection, Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token: '.MONITORED_TOKEN]], 401));

    runConnectionCheck($this);
    $this->travel(31)->minutes();
    runConnectionCheck($this);
    $this->travel(31)->minutes();
    runConnectionCheck($this);

    $alert = connectionAlertsOf($this->admin)->sole();
    $audit = AuditLog::query()->where('action', AuditAction::ConnectionCredentialsFailed)->sole();

    expect($connection->refresh()->health())->toBe(ConnectionHealth::CredentialsInvalid)
        ->and($connection->credentials_verified_at)->toBeNull()
        ->and($alert->occurrences)->toBe(1)
        ->and($alert->level)->toBe('urgent')
        ->and($alert->data)->toBe(['connection_id' => $connection->id, 'health' => 'credentials_invalid'])
        ->and($audit->properties)->toMatchArray(['verification_result' => 'rejected', 'automatic' => true])
        ->and(json_encode([$audit->properties, $connection->credentials_error, $connection->provider_signals]))->not->toContain(MONITORED_TOKEN);
});

test('an outage of Meta is "could not be checked": no credential failure, no error, no alert', function (Closure $answer, string $outcome) {
    $connection = monitoredConnection(['credentials_verified_at' => now()->subHours(5)]);
    $verifiedAt = $connection->credentials_verified_at;
    fakeMonitoredMeta($connection, $answer());

    runConnectionCheck($this);

    expect($connection->refresh())
        ->credentials_error->toBeNull()
        ->last_error_at->toBeNull()
        ->and($connection->credentials_verified_at->getTimestamp())->toBe($verifiedAt->getTimestamp())
        ->and($connection->health())->toBe(ConnectionHealth::Operational)
        ->and($connection->provider_signals['platform.check']['value'])->toBe(['outcome' => $outcome])
        ->and(connectionAlertsOf($this->admin))->toBeEmpty()
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'server error' => [fn () => fn () => Http::response('Bad Gateway', 502), 'unreachable'],
    'explicit temporary failure' => [fn () => fn () => Http::response(['error' => ['code' => 2, 'message' => 'Temporary issue']], 500), 'unreachable'],
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 'unreachable'],
    'throttled' => [fn () => fn () => Http::response(['error' => ['code' => 4, 'message' => 'Application request limit reached']], 429), 'rate_limited'],
]);

test('a successful check after a problem resolves the alert: one recovery update, no stale problem in the bell', function () {
    $connection = monitoredConnection([], 'withInvalidCredentials');
    fakeMonitoredMeta($connection, Http::sequence()
        ->push(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token.']], 401)
        ->push(['id' => $connection->phone_number_id, 'status' => 'CONNECTED', 'verified_name' => 'Mi Empresa'])
        ->push(['id' => $connection->phone_number_id, 'status' => 'CONNECTED', 'verified_name' => 'Mi Empresa']));
    $connection->forceFill(['credentials_error' => null, 'credentials_verified_at' => now()->subDay()])->save();

    runConnectionCheck($this);

    expect(app(NotificationPresenter::class)->present(connectionAlertsOf($this->admin)->sole(), $this->admin))
        ->title->toBe("Problema en la conexión {$connection->name}")
        ->level->toBe('urgent');

    $this->travel(31)->minutes();
    runConnectionCheck($this);
    $this->travel(31)->minutes();
    runConnectionCheck($this);

    $alert = connectionAlertsOf($this->admin)->sole();

    expect($connection->refresh()->health())->toBe(ConnectionHealth::CredentialsVerified)
        ->and($alert->data)->toBe(['connection_id' => $connection->id, 'health' => 'credentials_verified', 'recovered' => true])
        ->and($alert->occurrences)->toBe(2)
        ->and(app(NotificationCenter::class)->unreadSummary($this->admin))->toBe(['urgent' => 0, 'total' => 1])
        ->and(app(NotificationPresenter::class)->present($alert, $this->admin))
        ->title->toBe("La conexión {$connection->name} se recuperó")
        ->level->toBe('informative')
        ->url->toBe(route('connections.show', $connection).'#diagnostico')
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionCredentialsVerified)->sole()->properties)->toMatchArray(['automatic' => true]);
});

test('a problem alert whose connection works again never reads as current, even without a recovery update', function () {
    $connection = monitoredConnection();
    $connection->forceFill(['last_error_at' => now(), 'last_error_message' => 'Meta reportó un error.'])->save();
    DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => $this->admin->getMorphClass(), 'notifiable_id' => $this->admin->id,
        'kind' => NotificationKind::ConnectionProblem->value, 'level' => 'urgent', 'data' => ['connection_id' => $connection->id, 'health' => 'error'],
    ]);
    $alert = connectionAlertsOf($this->admin)->sole();

    expect(app(NotificationPresenter::class)->present($alert, $this->admin)['title'])->toBe("Problema en la conexión {$connection->name}");

    $connection->forceFill(['last_outbound_at' => now()->addMinute()])->save();

    expect(app(NotificationPresenter::class)->present($alert, $this->admin))
        ->title->toBe("Aviso anterior de la conexión {$connection->name}")
        ->body->toContain('Ya no está en ese estado');
});

test('a failure on one connection does not stop the check of the others', function () {
    $broken = monitoredConnection(['name' => 'A']);
    $healthy = monitoredConnection(['name' => 'B', 'credentials_verified_at' => now()->subDays(2)]);
    fakeMonitoredMeta($healthy);
    Http::fake(["graph.facebook.com/v26.0/{$broken->phone_number_id}*" => fn () => throw new RuntimeException('unexpected')]);
    $this->freezeSecond();

    runConnectionCheck($this);

    expect($healthy->refresh()->credentials_verified_at)->toEqual(now())
        ->and($broken->refresh()->health())->toBe(ConnectionHealth::Operational);
});

test('only active, configured, live connections are checked, a bounded number per run and never two runs at once', function () {
    config(['whatsapp.monitoring.max_per_run' => 1]);
    $checkedLongAgo = monitoredConnection();
    $checkedLongAgo->forceFill(['provider_signals' => ['platform.check' => ['at' => now()->subDay()->toIso8601String(), 'value' => ['outcome' => 'verified']]]])->save();
    $neverChecked = monitoredConnection();
    monitoredConnection(['is_active' => false]);
    monitoredConnection()->forceFill(['deleted_at' => now(), 'is_active' => false])->save();
    Connection::factory()->unconfigured()->create();
    Connection::factory()->webChat()->create();
    fakeMonitoredMeta($neverChecked);

    $lock = Cache::lock('whatsapp:maintenance:check-connections', 60);
    $lock->get();
    runConnectionCheck($this);
    Http::assertNothingSent();
    $lock->release();

    runConnectionCheck($this);

    // Never checked goes first; the limit leaves the other for the next run.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), (string) $neverChecked->phone_number_id));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), (string) $checkedLongAgo->phone_number_id));
    Http::assertSentCount(2);
});

test('when Meta throttles, the run stops instead of insisting with the remaining connections', function () {
    $first = monitoredConnection();
    monitoredConnection();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 4, 'message' => 'Application request limit reached']], 429)]);

    runConnectionCheck($this);

    Http::assertSentCount(1);
    expect($first->refresh()->health())->toBe(ConnectionHealth::Operational);
});

test('a Messenger account is checked with the subset that applies to it', function () {
    config(['services.meta.app_id' => '1234567890', 'services.meta.app_secret' => 'facebook-app-secret-value', 'services.meta.webhook_verify_token' => 'verify-token-1234567890']);
    $connection = Connection::factory()->messenger()->create(['access_token' => MONITORED_TOKEN]);
    $connection->forceFill(['credentials_verified_at' => now()->subDays(4), 'subscription_checked_at' => null])->save();
    Http::fake(["graph.facebook.com/v26.0/{$connection->external_account_id}/subscribed_apps*" => Http::sequence()
        ->push(['data' => [['id' => '1234567890', 'subscribed_fields' => ['messages']]]])
        ->push(['error' => ['code' => 190, 'message' => 'The session has been invalidated']], 401)]);
    $this->freezeSecond();

    runConnectionCheck($this);

    expect($connection->refresh())
        ->credentials_verified_at->toEqual(now())
        ->subscription_checked_at->toEqual(now())
        ->app_subscribed->toBeTrue();

    $this->travel(31)->minutes();
    runConnectionCheck($this);

    expect($connection->refresh()->health())->toBe(ConnectionHealth::CredentialsInvalid)
        ->and(connectionAlertsOf($this->admin)->sole()->data)->toMatchArray(['health' => 'credentials_invalid']);
});

test('the connections screens tell recent evidence from history and never carry a secret', function () {
    $recent = monitoredConnection(['name' => 'Reciente', 'credentials_verified_at' => now()->subMinutes(12)]);
    $historical = monitoredConnection([
        'name' => 'Histórica', 'credentials_verified_at' => now()->subDays(3), 'last_inbound_at' => now()->subDays(3),
        'last_outbound_at' => now()->subDays(4), 'last_activity_at' => now()->subDays(3),
    ]);
    $historical->forceFill(['last_error_at' => now()->subDays(10), 'last_error_message' => 'Meta reportó un error temporal.', 'app_subscribed' => false, 'subscription_checked_at' => now()->subDays(3)])->save();
    $messenger = Connection::factory()->messenger()->create(['name' => 'Página', 'access_token' => MONITORED_TOKEN]);
    $webChat = Connection::factory()->webChat()->create(['name' => 'Sitio']);

    $index = $this->actingAs($this->admin)->get(route('connections.index'))->assertOk();
    $monitoring = collect($index->viewData('page')['props']['connections'])->keyBy('id')->map(fn (array $row): array => $row['monitoring']);

    expect($monitoring[$recent->id]['evidence'])->toMatchArray(['level' => 'recent', 'source' => 'check'])
        ->and($monitoring[$recent->id]['credentials'])->toMatchArray(['state' => 'verified', 'fresh' => true, 'automatic' => true])
        ->and($monitoring[$recent->id]['warnings'])->toBe([])
        // Three days without traffic is history, not a warning and not "connected now".
        ->and($monitoring[$historical->id]['evidence'])->toMatchArray(['level' => 'historical'])
        ->and($monitoring[$historical->id]['credentials']['fresh'])->toBeFalse()
        ->and($monitoring[$historical->id]['last_error'])->toMatchArray(['message' => 'Meta reportó un error temporal.', 'current' => false])
        ->and(array_column($monitoring[$historical->id]['warnings'], 'code'))->toBe(['not_subscribed'])
        // Each channel shows the subset that applies.
        ->and($monitoring[$messenger->id]['webhook'])->toBeNull()
        ->and($monitoring[$messenger->id]['subscription']['state'])->toBe('subscribed')
        ->and($monitoring[$webChat->id])->toMatchArray(['credentials' => null, 'webhook' => null, 'subscription' => null])
        ->and($monitoring[$webChat->id]['evidence']['level'])->toBe('none');

    $show = $this->actingAs($this->admin)->get(route('connections.show', $recent))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.monitoring.evidence.level', 'recent')
            ->where('connection.secrets.access_token', true)
            ->has('diagnostics.warnings'));

    foreach ([$index->getContent(), $show->getContent(), json_encode($show->viewData('page'))] as $content) {
        expect($content)->not->toContain(MONITORED_TOKEN)
            ->not->toContain(MONITORED_APP_SECRET)
            ->not->toContain(MONITORED_VERIFY_TOKEN);
    }
});

test('monitoring is only shown to people who may see connections', function (Closure $user, int $status) {
    $connection = monitoredConnection();

    $this->actingAs($user())->get(route('connections.index'))->assertStatus($status);
    $this->actingAs($user())->get(route('connections.show', $connection))->assertStatus($status);
})->with([
    'agent' => [fn () => User::factory()->agent()->create(), 403],
    'view without global scope' => [fn () => User::factory()->withRole(Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewConnections])->create())->create(), 403],
    'viewer' => [fn () => User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConnections])->create())->create(), 200],
    'manager' => [fn () => User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ManageConnections])->create())->create(), 200],
]);

test('a viewer sees the monitoring but cannot verify or check the subscription', function () {
    $connection = monitoredConnection();
    $viewer = User::factory()->withRole(Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewConnections])->create())->create();

    $this->actingAs($viewer)->post(route('connections.verify', $connection))->assertForbidden();
    $this->actingAs($viewer)->post(route('connections.subscription.check', $connection))->assertForbidden();

    Http::assertNothingSent();
});

test('a manual verification that fails also alerts the other people who watch connections', function () {
    $connection = monitoredConnection();
    fakeMonitoredMeta($connection, Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token.']], 401));
    $other = User::factory()->administrator()->create();

    $this->actingAs($this->admin)->post(route('connections.verify', $connection))->assertRedirect();

    expect(connectionAlertsOf($other)->sole()->data)->toMatchArray(['health' => 'credentials_invalid']);
});

/**
 * A notice of Meta about the connection, through the webhook pipeline.
 *
 * @param  array<string, mixed>  $value
 */
function monitoredNotice(Connection $connection, string $field, array $value): void
{
    app(ProcessWebhookPayload::class)->handle($connection, WhatsAppFixtures::envelope($connection, $field, $value));
}

test('a successful automatic check does not resolve an error that was not about the credentials', function (Closure $problem) {
    $connection = monitoredConnection();
    fakeMonitoredMeta($connection);
    $problem($connection);

    expect($connection->refresh()->health())->toBe(ConnectionHealth::Error)
        ->and(connectionAlertsOf($this->admin)->sole()->data)->toMatchArray(['health' => 'error']);

    $this->travel(31)->minutes();
    runConnectionCheck($this);
    $this->travel(31)->minutes();
    runConnectionCheck($this);

    // Meta accepted the token twice: that says nothing about the restriction.
    expect($connection->refresh()->health())->toBe(ConnectionHealth::Error)
        ->and($connection->credentials_verified_at->greaterThan($connection->last_error_at))->toBeTrue()
        ->and($connection->provider_signals[ConnectionMonitor::CHECK_SIGNAL]['value']['outcome'])->toBe('verified')
        ->and(ConnectionMonitoring::for($connection)['last_error']['current'])->toBeTrue()
        ->and(connectionAlertsOf($this->admin)->sole()->data)->toBe(['connection_id' => $connection->id, 'health' => 'error'])
        ->and(app(NotificationPresenter::class)->present(connectionAlertsOf($this->admin)->sole(), $this->admin)['title'])->toBe("Problema en la conexión {$connection->name}");

    // Real evidence: Meta accepts a send again.
    $this->travel(2)->minutes();
    app(ConnectionMonitor::class)->outboundAccepted($connection);

    expect($connection->refresh()->health())->toBe(ConnectionHealth::Operational)
        ->and(connectionAlertsOf($this->admin)->sole()->data)->toMatchArray(['health' => 'operational', 'recovered' => true]);
})->with([
    'a quality notice (FLAGGED)' => [fn (Connection $connection) => monitoredNotice($connection, 'phone_number_quality_update', ['event' => 'FLAGGED', 'current_limit' => 'TIER_1K'])],
    'an account restriction' => [fn (Connection $connection) => monitoredNotice($connection, 'account_update', ['event' => 'ACCOUNT_RESTRICTION'])],
    'a send rejected for the whole connection' => [fn (Connection $connection) => app(ConnectionMonitor::class)->failure($connection, 'Meta rechazó el envío: falta el permiso de mensajería.', ConnectionMonitor::ERROR_SEND)],
]);

test('a manual verification follows the same rule: it attests the credentials, not a restriction', function () {
    $connection = monitoredConnection();
    fakeMonitoredMeta($connection);
    monitoredNotice($connection, 'phone_number_quality_update', ['event' => 'FLAGGED']);

    $this->travel(5)->minutes();

    expect(app(VerifyConnectionCredentials::class)->handle($connection->refresh(), $this->admin)->verified())->toBeTrue()
        ->and($connection->refresh()->health())->toBe(ConnectionHealth::Error)
        ->and($connection->credentials_error)->toBeNull();
});

test('Meta saying that the restriction was lifted resolves that error, and only that one', function () {
    $flagged = monitoredConnection();
    $restricted = monitoredConnection();
    monitoredNotice($flagged, 'phone_number_quality_update', ['event' => 'FLAGGED']);
    monitoredNotice($restricted, 'account_update', ['event' => 'ACCOUNT_RESTRICTION']);

    $this->travel(10)->minutes();
    monitoredNotice($flagged, 'phone_number_quality_update', ['event' => 'UNFLAGGED']);
    // The quality of the number is fine again, but the account is still restricted.
    monitoredNotice($restricted, 'phone_number_quality_update', ['event' => 'UNFLAGGED']);

    expect($flagged->refresh()->health())->toBe(ConnectionHealth::Operational)
        ->and(connectionAlertsOf($this->admin)->firstWhere('data.connection_id', $flagged->id)->data)->toMatchArray(['recovered' => true])
        ->and($restricted->refresh()->health())->toBe(ConnectionHealth::Error);

    monitoredNotice($restricted, 'account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'REINSTATE']]);

    expect($restricted->refresh()->health())->toBe(ConnectionHealth::Operational);
});

test('the automatic check still resolves the errors it can attest', function (Closure $problem) {
    $connection = monitoredConnection();
    fakeMonitoredMeta($connection);
    $problem($connection);

    expect($connection->refresh()->health())->toBe(ConnectionHealth::Error);

    $this->travel(31)->minutes();
    runConnectionCheck($this);

    expect($connection->refresh()->health())->toBe(ConnectionHealth::Operational);
})->with([
    'a token that could not be renewed' => [fn (Connection $connection) => app(ConnectionMonitor::class)->failure($connection, 'No fue posible renovar el token.', ConnectionMonitor::ERROR_CREDENTIALS)],
    'an error recorded without its class' => [fn (Connection $connection) => $connection->forceFill(['last_error_at' => now(), 'last_error_message' => 'No fue posible suscribir la cuenta al webhook.'])->save()],
    'an unclassified error written after a classified one' => [function (Connection $connection) {
        app(ConnectionMonitor::class)->failure($connection, 'Meta marcó la calidad del número como baja.', ConnectionMonitor::ERROR_QUALITY);
        test()->travel(1)->minutes();
        $connection->refresh()->forceFill(['last_error_at' => now(), 'last_error_message' => 'No fue posible suscribir la cuenta al webhook.'])->save();
    }],
]);
