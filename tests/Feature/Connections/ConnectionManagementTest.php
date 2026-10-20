<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

const CONNECTION_TEST_TOKEN = 'EAAGplainAccessToken1234567890abcdef';
const CONNECTION_TEST_APP_SECRET = 'plain-app-secret-0123456789';
const CONNECTION_TEST_VERIFY_TOKEN = 'plain-verify-token-0123456789';

/**
 * @return array<string, mixed>
 */
function connectionPayload(array $overrides = []): array
{
    return [
        'name' => 'Ventas CDMX',
        'display_phone_number' => '+52 55 1234 5678',
        'phone_number_id' => '106540352242922',
        'business_account_id' => '102290129340398',
        'meta_app_id' => '1234567890123456',
        'access_token' => '',
        'app_secret' => '',
        'webhook_verify_token' => '',
        'department_ids' => [],
        'default_department_id' => null,
        'welcome_enabled' => false,
        'welcome_message' => '',
        'away_enabled' => false,
        'away_message' => '',
        'farewell_enabled' => false,
        'farewell_message' => '',
        'reopen_window_minutes' => null,
        ...$overrides,
    ];
}

test('the index explains the empty state without inventing indicators', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('connections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('connections/Index')
            ->has('connections', 0)
            ->where('stats', ['total' => 0, 'active' => 0, 'verified' => 0, 'attention' => 0])
            ->where('can.create', true));
});

test('administrators create a connection that starts inactive with encrypted secrets', function () {
    $admin = User::factory()->administrator()->create();
    $sales = Department::factory()->create(['name' => 'Ventas']);
    $support = Department::factory()->create(['name' => 'Soporte']);

    $response = $this->actingAs($admin)->post(route('connections.store'), connectionPayload([
        'access_token' => CONNECTION_TEST_TOKEN,
        'app_secret' => CONNECTION_TEST_APP_SECRET,
        'webhook_verify_token' => CONNECTION_TEST_VERIFY_TOKEN,
        'department_ids' => [$sales->id, $support->id],
        'default_department_id' => $support->id,
        'welcome_enabled' => true,
        'welcome_message' => '¡Hola! Gracias por escribir a *Mi Empresa*.',
        'reopen_window_minutes' => 120,
    ]));

    $connection = Connection::query()->sole();

    $response->assertSessionHasNoErrors()->assertRedirect(route('connections.show', $connection));

    expect($connection)
        ->is_active->toBeFalse()
        ->access_token->toBe(CONNECTION_TEST_TOKEN)
        ->app_secret->toBe(CONNECTION_TEST_APP_SECRET)
        ->webhook_verify_token->toBe(CONNECTION_TEST_VERIFY_TOKEN)
        ->default_department_id->toBe($support->id)
        ->welcome_enabled->toBeTrue()
        ->reopen_window_minutes->toBe(120)
        ->created_by_user_id->toBe($admin->id)
        ->and($connection->departments()->pluck('departments.id')->sort()->values()->all())->toBe([$sales->id, $support->id])
        ->and(DB::table('connections')->value('access_token'))->not->toContain(CONNECTION_TEST_TOKEN);

    $audit = AuditLog::query()->where('action', AuditAction::ConnectionCreated)->sole();

    expect($audit->auditable_id)->toBe($connection->id)
        ->and($audit->properties['departments'])->toBe(['Soporte', 'Ventas'])
        ->and(json_encode($audit->properties))->not->toContain(CONNECTION_TEST_TOKEN, CONNECTION_TEST_APP_SECRET, CONNECTION_TEST_VERIFY_TOKEN);
});

test('a connection can be saved before the Meta credentials are known', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('connections.store'), connectionPayload([
            'phone_number_id' => '',
            'business_account_id' => '',
            'meta_app_id' => '',
            'display_phone_number' => '',
        ]))
        ->assertSessionHasNoErrors();

    $connection = Connection::query()->sole();

    expect($connection->phone_number_id)->toBeNull()
        ->and($connection->health()->value)->toBe('inactive')
        ->and($connection->configurationHealth()->value)->toBe('not_configured');
});

test('connection input is validated', function (Closure $payload, array $errors) {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('connections.store'), connectionPayload($payload()))
        ->assertSessionHasErrors($errors);

    expect(Connection::query()->where('name', 'Ventas CDMX')->exists())->toBeFalse();
})->with([
    'duplicate phone number id' => [function () {
        Connection::factory()->create(['phone_number_id' => '999000111222333']);

        return ['phone_number_id' => '999000111222333'];
    }, ['phone_number_id' => 'Este Phone number ID ya está registrado en otra conexión.']],
    'non numeric identifiers' => [fn () => ['phone_number_id' => '10654-abc', 'business_account_id' => 'waba'], ['phone_number_id', 'business_account_id']],
    'default department outside the authorized ones' => [function () {
        $authorized = Department::factory()->create();
        $other = Department::factory()->create();

        return ['department_ids' => [$authorized->id], 'default_department_id' => $other->id];
    }, ['default_department_id' => 'El departamento predeterminado debe ser uno de los departamentos autorizados.']],
    'default department without authorized departments' => [fn () => [
        'default_department_id' => Department::factory()->create()->id,
    ], ['default_department_id']],
    'inactive department' => [fn () => ['department_ids' => [Department::factory()->inactive()->create()->id]], ['department_ids']],
    'unknown department' => [fn () => ['department_ids' => [999999]], ['department_ids.0']],
    'message longer than 1024 characters' => [fn () => ['welcome_message' => str_repeat('a', 1025)], ['welcome_message']],
    'enabled message without text' => [fn () => ['away_enabled' => true, 'away_message' => ''], ['away_message' => 'Escribe el mensaje o desactívalo.']],
    'reopen window out of range' => [fn () => ['reopen_window_minutes' => 0], ['reopen_window_minutes']],
    'short verify token' => [fn () => ['webhook_verify_token' => 'corto'], ['webhook_verify_token']],
    'token with spaces' => [fn () => ['access_token' => 'EAAG token with spaces 1234567890'], ['access_token']],
]);

test('messages up to 1024 characters are accepted', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->post(route('connections.store'), connectionPayload([
            'farewell_enabled' => true,
            'farewell_message' => str_repeat('a', 1024),
        ]))
        ->assertSessionHasNoErrors();

    expect(Connection::query()->sole()->farewell_message)->toHaveLength(1024);
});

test('blank secret fields keep the stored values', function () {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->verified()->create([
        'name' => 'Ventas Sur',
        'access_token' => CONNECTION_TEST_TOKEN,
        'app_secret' => CONNECTION_TEST_APP_SECRET,
        'webhook_verify_token' => CONNECTION_TEST_VERIFY_TOKEN,
    ]);

    $this->actingAs($admin)
        ->put(route('connections.update', $connection), connectionPayload([
            'name' => 'Ventas Norte',
            'phone_number_id' => $connection->phone_number_id,
            'business_account_id' => $connection->business_account_id,
        ]))
        ->assertSessionHasNoErrors();

    expect($connection->refresh())
        ->name->toBe('Ventas Norte')
        ->access_token->toBe(CONNECTION_TEST_TOKEN)
        ->app_secret->toBe(CONNECTION_TEST_APP_SECRET)
        ->webhook_verify_token->toBe(CONNECTION_TEST_VERIFY_TOKEN)
        ->credentials_verified_at->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionCredentialsUpdated)->exists())->toBeFalse();

    expect(AuditLog::query()->where('action', AuditAction::ConnectionUpdated)->sole()->properties['changes']['name'])
        ->toBe(['old' => 'Ventas Sur', 'new' => 'Ventas Norte']);
});

test('replacing the access token discards the previous verification', function () {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->verified()->create(['access_token' => CONNECTION_TEST_TOKEN]);

    $this->actingAs($admin)
        ->put(route('connections.update', $connection), connectionPayload([
            'name' => $connection->name,
            'display_phone_number' => $connection->display_phone_number,
            'phone_number_id' => $connection->phone_number_id,
            'business_account_id' => $connection->business_account_id,
            'meta_app_id' => null,
            'access_token' => 'EAAGreplacementToken0987654321zyx',
        ]))
        ->assertSessionHasNoErrors();

    expect($connection->refresh())
        ->access_token->toBe('EAAGreplacementToken0987654321zyx')
        ->credentials_verified_at->toBeNull()
        ->credentials_error->toBeNull()
        ->verified_name->toBeNull()
        ->phone_status->toBeNull()
        ->and($connection->configurationHealth()->value)->toBe('configured');

    $audit = AuditLog::query()->where('action', AuditAction::ConnectionCredentialsUpdated)->sole();

    expect($audit->properties)->toBe(['replaced_fields' => ['Token de acceso'], 'verification_reset' => true])
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('EAAGreplacementToken0987654321zyx', CONNECTION_TEST_TOKEN);
});

test('changing the phone number id discards a previous verification or rejection', function (string $state) {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->{$state}()->create();

    $this->actingAs($admin)
        ->put(route('connections.update', $connection), connectionPayload([
            'name' => $connection->name,
            'phone_number_id' => '555000111222333',
            'business_account_id' => $connection->business_account_id,
        ]))
        ->assertSessionHasNoErrors();

    expect($connection->refresh())
        ->credentials_verified_at->toBeNull()
        ->credentials_error->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionUpdated)->sole()->properties['verification_reset'])->toBeTrue();
})->with(['verified', 'withInvalidCredentials']);

test('a department deactivated after being authorized can stay authorized', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $connection = Connection::factory()->create();
    $connection->departments()->attach($department);
    $department->forceFill(['is_active' => false])->save();

    $this->actingAs($admin)
        ->put(route('connections.update', $connection), connectionPayload([
            'name' => 'Renombrada',
            'phone_number_id' => $connection->phone_number_id,
            'department_ids' => [$department->id],
            'default_department_id' => $department->id,
        ]))
        ->assertSessionHasNoErrors();

    expect($connection->refresh()->name)->toBe('Renombrada');
});

test('department authorization changes are audited by name', function () {
    $admin = User::factory()->administrator()->create();
    $sales = Department::factory()->create(['name' => 'Ventas']);
    $support = Department::factory()->create(['name' => 'Soporte']);
    $connection = Connection::factory()->create();
    $connection->departments()->attach($sales);

    $this->actingAs($admin)
        ->put(route('connections.update', $connection), connectionPayload([
            'name' => $connection->name,
            'display_phone_number' => $connection->display_phone_number,
            'phone_number_id' => $connection->phone_number_id,
            'business_account_id' => $connection->business_account_id,
            'meta_app_id' => null,
            'department_ids' => [$support->id],
        ]))
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', AuditAction::ConnectionUpdated)->sole()->properties['changes']['departments'])
        ->toBe(['added' => ['Soporte'], 'removed' => ['Ventas']]);
});

test('secrets are never sent to the browser', function () {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->create([
        'access_token' => CONNECTION_TEST_TOKEN,
        'app_secret' => CONNECTION_TEST_APP_SECRET,
        'webhook_verify_token' => CONNECTION_TEST_VERIFY_TOKEN,
    ]);

    $show = $this->actingAs($admin)->get(route('connections.show', $connection));
    $index = $this->actingAs($admin)->get(route('connections.index'));

    $show->assertInertia(fn (Assert $page) => $page
        ->component('connections/Form')
        ->where('connection.secrets', ['access_token' => true, 'app_secret' => true, 'webhook_verify_token' => true])
        ->missing('connection.access_token')
        ->missing('connection.app_secret')
        ->missing('connection.webhook_verify_token'));

    foreach ([$show, $index] as $response) {
        expect($response->getContent())->not->toContain(CONNECTION_TEST_TOKEN, CONNECTION_TEST_APP_SECRET, CONNECTION_TEST_VERIFY_TOKEN);
    }
});

test('the index shows the health derived from evidence for each connection', function () {
    $admin = User::factory()->administrator()->create();
    Connection::factory()->unconfigured()->create(['name' => 'A sin configurar']);
    Connection::factory()->create(['name' => 'B guardada']);
    Connection::factory()->withInvalidCredentials()->create(['name' => 'C rechazada']);
    Connection::factory()->verified()->create(['name' => 'D verificada', 'phone_status' => 'CONNECTED']);
    Connection::factory()->operational()->create(['name' => 'E operativa']);
    Connection::factory()->operational()->inactive()->create(['name' => 'F apagada']);

    $this->actingAs($admin)
        ->get(route('connections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('connections', 6)
            ->where('connections.0.name', 'A sin configurar')
            ->where('connections.0.health.value', 'not_configured')
            ->where('connections.0.checks.configuration_saved', false)
            ->where('connections.0.checks.credentials.state', 'missing')
            ->where('connections.1.health.value', 'configured')
            ->where('connections.1.checks.credentials.state', 'unverified')
            ->where('connections.2.health.value', 'credentials_invalid')
            ->where('connections.2.checks.credentials.state', 'invalid')
            ->where('connections.2.checks.credentials.error', 'Invalid OAuth access token.')
            ->where('connections.3.health.value', 'credentials_verified')
            ->where('connections.3.checks.webhook_verified_at', null)
            ->where('connections.3.checks.last_inbound_at', null)
            ->where('connections.3.checks.last_outbound_at', null)
            ->where('connections.4.health.value', 'operational')
            ->where('connections.5.name', 'F apagada')
            ->where('connections.5.health.value', 'inactive')
            ->where('connections.5.configuration_health.value', 'operational')
            ->where('stats', ['total' => 6, 'active' => 5, 'verified' => 3, 'attention' => 2]));
});

test('connections can be searched and filtered', function (array $query, array $expected) {
    $admin = User::factory()->administrator()->create();
    $sales = Department::factory()->create();
    $sales->connections()->attach(Connection::factory()->verified()->create(['name' => 'Ventas', 'display_phone_number' => '+5215511112222'])->id);
    Connection::factory()->inactive()->create(['name' => 'Soporte', 'display_phone_number' => '+5213399998888']);
    Connection::factory()->withInvalidCredentials()->create(['name' => 'Cobranza', 'phone_number_id' => '777000111222333']);

    $query = array_map(fn (mixed $value) => $value === 'SALES_ID' ? $sales->id : $value, $query);

    $this->actingAs($admin)
        ->get(route('connections.index', $query))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections', fn ($connections) => collect($connections)->pluck('name')->sort()->values()->all() === $expected));
})->with([
    'search by name' => [['search' => 'vent'], ['Ventas']],
    'search by phone' => [['search' => '3399'], ['Soporte']],
    'search by phone number id' => [['search' => '777000'], ['Cobranza']],
    'filter by health' => [['health' => 'credentials_invalid'], ['Cobranza']],
    'filter by inactive health' => [['health' => 'inactive'], ['Soporte']],
    'filter by department' => [['department' => 'SALES_ID'], ['Ventas']],
    'filter by status' => [['status' => 'inactive'], ['Soporte']],
    'filter by channel' => [['channel' => 'whatsapp'], ['Cobranza', 'Soporte', 'Ventas']],
]);

test('invalid filters are rejected', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('connections.index', ['health' => 'funcionando']))
        ->assertSessionHasErrors('health');
});

test('activation requires the identifiers and the access token', function () {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->unconfigured()->inactive()->create();

    $this->actingAs($admin)
        ->patch(route('connections.status.update', $connection), ['is_active' => true])
        ->assertSessionHasErrors(['is_active']);

    expect($connection->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionActivated)->exists())->toBeFalse();
});

test('a connection with unverified credentials can be activated with a warning', function () {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->inactive()->create();

    $this->actingAs($admin)
        ->patch(route('connections.status.update', $connection), ['is_active' => true])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'warning');

    expect($connection->refresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionActivated)->sole()->properties)->toBe(['health' => 'configured']);
});

test('deactivation is audited', function () {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->verified()->create();

    $this->actingAs($admin)
        ->patch(route('connections.status.update', $connection), ['is_active' => false])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Conexión desactivada.');

    expect($connection->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionDeactivated)->sole()->auditable_id)->toBe($connection->id);
});
