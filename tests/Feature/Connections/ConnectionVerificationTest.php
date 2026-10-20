<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const VERIFICATION_TEST_TOKEN = 'EAAGverificationSecretToken1234567890';
const VERIFICATION_PHONE_ID = '106540352242922';
const VERIFICATION_ENDPOINT = 'graph.facebook.com/v26.0/106540352242922*';

function connectionToVerify(string $state = 'withInvalidCredentials'): Connection
{
    return Connection::factory()->{$state}()->create([
        'phone_number_id' => VERIFICATION_PHONE_ID,
        'access_token' => VERIFICATION_TEST_TOKEN,
        'display_phone_number' => null,
    ]);
}

/**
 * Everything persisted or returned that could leak the token.
 */
function verificationTraces(Connection $connection, string $responseContent): string
{
    return json_encode([
        AuditLog::query()->get()->toArray(),
        $connection->refresh()->toArray(),
        session()->all(),
        $responseContent,
    ]);
}

test('verified credentials store the profile reported by Meta', function () {
    Http::preventStrayRequests();
    Http::fake([VERIFICATION_ENDPOINT => Http::response([
        'id' => VERIFICATION_PHONE_ID,
        'display_phone_number' => '+52 55 1234 5678',
        'verified_name' => 'Mi Empresa',
        'quality_rating' => 'GREEN',
        'code_verification_status' => 'VERIFIED',
        'status' => 'CONNECTED',
        'platform_type' => 'CLOUD_API',
    ])]);
    $this->freezeSecond();
    $admin = User::factory()->administrator()->create();
    $connection = connectionToVerify();

    $response = $this->actingAs($admin)
        ->post(route('connections.verify', $connection))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Meta aceptó las credenciales.']);

    expect($connection->refresh())
        ->credentials_verified_at->toEqual(now())
        ->credentials_error->toBeNull()
        ->verified_name->toBe('Mi Empresa')
        ->display_phone_number->toBe('+52 55 1234 5678')
        ->quality_rating->toBe('GREEN')
        ->phone_status->toBe('CONNECTED')
        ->code_verification_status->toBe('VERIFIED')
        ->platform_type->toBe('CLOUD_API')
        ->and($connection->configurationHealth()->value)->toBe('credentials_verified');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/'.VERIFICATION_PHONE_ID.'?')
        && $request['fields'] === 'display_phone_number,verified_name,quality_rating,code_verification_status,status,platform_type'
        && $request->header('Authorization') === ['Bearer '.VERIFICATION_TEST_TOKEN]
        && ! str_contains($request->url(), VERIFICATION_TEST_TOKEN));

    expect(AuditLog::query()->where('action', AuditAction::ConnectionCredentialsVerified)->sole()->properties)
        ->toMatchArray(['verification_result' => 'verified', 'verified_name' => 'Mi Empresa', 'phone_status' => 'CONNECTED'])
        ->and(verificationTraces($connection, $response->getContent()))->not->toContain(VERIFICATION_TEST_TOKEN);
});

test('the graph version is configurable', function () {
    Http::preventStrayRequests();
    config(['services.meta.graph_version' => 'v25.0']);
    Http::fake(['graph.facebook.com/v25.0/*' => Http::response(['id' => VERIFICATION_PHONE_ID, 'status' => 'CONNECTED'])]);
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->post(route('connections.verify', connectionToVerify()))->assertRedirect();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/'));
});

test('a number that is not connected is reported even when the credentials are valid', function () {
    Http::preventStrayRequests();
    Http::fake([VERIFICATION_ENDPOINT => Http::response(['id' => VERIFICATION_PHONE_ID, 'status' => 'PENDING'])]);
    $admin = User::factory()->administrator()->create();
    $connection = connectionToVerify();

    $this->actingAs($admin)
        ->post(route('connections.verify', $connection))
        ->assertInertiaFlash('toast.message', 'Meta aceptó las credenciales, pero reporta el número con estado PENDING. Debe estar CONNECTED para enviar y recibir mensajes.');

    expect($connection->refresh()->phone_status)->toBe('PENDING')
        ->and($connection->health()->value)->not->toBe('operational');
});

test('credentials rejected by Meta are recorded without leaking the token', function () {
    Http::preventStrayRequests();
    Http::fake([VERIFICATION_ENDPOINT => Http::response([
        'error' => [
            'message' => 'Invalid OAuth access token - Cannot parse access token '.VERIFICATION_TEST_TOKEN,
            'type' => 'OAuthException',
            'code' => 190,
            'fbtrace_id' => 'AbCdEf',
        ],
    ], 401)]);
    $admin = User::factory()->administrator()->create();
    $connection = connectionToVerify('verified');

    $response = $this->actingAs($admin)
        ->post(route('connections.verify', $connection))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect($connection->refresh())
        ->credentials_verified_at->toBeNull()
        ->credentials_error->toStartWith('El token de acceso es inválido o expiró.')
        ->credentials_error->toContain('(código 190)')
        ->and($connection->configurationHealth()->value)->toBe('credentials_invalid');

    expect(AuditLog::query()->where('action', AuditAction::ConnectionCredentialsFailed)->sole()->properties)
        ->toMatchArray(['verification_result' => 'rejected', 'meta_code' => 190, 'http_status' => 401])
        ->and(verificationTraces($connection, $response->getContent()))->not->toContain(VERIFICATION_TEST_TOKEN);
});

test('an unknown phone number id is reported as rejected', function () {
    Http::preventStrayRequests();
    Http::fake([VERIFICATION_ENDPOINT => Http::response([
        'error' => ['message' => 'Unsupported get request.', 'type' => 'GraphMethodException', 'code' => 100],
    ], 400)]);
    $admin = User::factory()->administrator()->create();
    $connection = connectionToVerify('verified');

    $this->actingAs($admin)->post(route('connections.verify', $connection));

    expect($connection->refresh()->credentials_error)->toStartWith('El Phone number ID no existe o el token no tiene acceso a él.');
});

test('an unreachable Meta does not change the recorded credential state', function (Closure $fake) {
    Http::preventStrayRequests();
    Http::fake([VERIFICATION_ENDPOINT => $fake()]);
    $admin = User::factory()->administrator()->create();
    $connection = connectionToVerify('verified');
    $verifiedAt = $connection->credentials_verified_at;

    $response = $this->actingAs($admin)
        ->post(route('connections.verify', $connection))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'warning');

    expect($connection->refresh())
        ->credentials_verified_at->toEqual($verifiedAt)
        ->credentials_error->toBeNull();

    expect(AuditLog::query()->where('action', AuditAction::ConnectionCredentialsFailed)->sole()->properties['verification_result'])
        ->toBe('unreachable')
        ->and(verificationTraces($connection, $response->getContent()))->not->toContain(VERIFICATION_TEST_TOKEN);
})->with([
    'timeout' => [fn () => Http::failedConnection()],
    'server error' => [fn () => Http::response(['error' => ['message' => 'An unexpected error has occurred.', 'code' => 2]], 500)],
    'rate limited' => [fn () => Http::response(['error' => ['message' => 'Application request limit reached', 'code' => 4]], 400)],
    'not json' => [fn () => Http::response('<html>bad gateway</html>', 200)],
]);

test('verification requires the phone number id and the access token', function () {
    Http::preventStrayRequests();
    Http::fake();
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->unconfigured()->create();

    $this->actingAs($admin)
        ->post(route('connections.verify', $connection))
        ->assertSessionHasErrors(['connection']);

    Http::assertNothingSent();
});

test('a verification answer is discarded when the credentials changed meanwhile', function () {
    Http::preventStrayRequests();
    $admin = User::factory()->administrator()->create();
    $connection = connectionToVerify();

    Http::fake([VERIFICATION_ENDPOINT => function () use ($connection) {
        Connection::query()->whereKey($connection->id)->first()->forceFill(['access_token' => 'EAAGanotherTokenSavedMeanwhile12345'])->save();

        return Http::response(['id' => VERIFICATION_PHONE_ID, 'status' => 'CONNECTED']);
    }]);

    $this->actingAs($admin)
        ->post(route('connections.verify', $connection))
        ->assertSessionHasErrors(['connection' => 'Las credenciales cambiaron mientras se verificaban. Vuelve a intentarlo.']);

    expect($connection->refresh()->credentials_verified_at)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionCredentialsVerified)->exists())->toBeFalse();
});

test('verification attempts are rate limited per user and connection', function () {
    Http::preventStrayRequests();
    Http::fake([VERIFICATION_ENDPOINT => Http::response(['id' => VERIFICATION_PHONE_ID, 'status' => 'CONNECTED'])]);
    $admin = User::factory()->administrator()->create();
    $connection = connectionToVerify();

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($admin)->post(route('connections.verify', $connection))->assertInertiaFlash('toast.type', 'success');
    }

    $this->actingAs($admin)
        ->post(route('connections.verify', $connection))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'warning');

    Http::assertSentCount(5);
});
