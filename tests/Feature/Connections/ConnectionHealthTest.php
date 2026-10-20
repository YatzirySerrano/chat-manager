<?php

use App\Enums\ConnectionHealth;
use App\Models\Connection;

test('the health of a connection is derived from recorded evidence', function (Closure $connection, ConnectionHealth $expected) {
    expect($connection()->health())->toBe($expected);
})->with([
    'missing identifiers and token' => [fn () => Connection::factory()->unconfigured()->make(), ConnectionHealth::NotConfigured],
    'missing only the token' => [fn () => Connection::factory()->make(['access_token' => null]), ConnectionHealth::NotConfigured],
    'saved but never verified' => [fn () => Connection::factory()->make(), ConnectionHealth::Configured],
    'credentials rejected' => [fn () => Connection::factory()->withInvalidCredentials()->make(), ConnectionHealth::CredentialsInvalid],
    'credentials accepted' => [fn () => Connection::factory()->verified()->make(), ConnectionHealth::CredentialsVerified],
    'webhook verified' => [fn () => Connection::factory()->verified()->make(['webhook_verified_at' => now()]), ConnectionHealth::Receiving],
    'inbound message received' => [fn () => Connection::factory()->verified()->make(['last_inbound_at' => now()]), ConnectionHealth::Receiving],
    'inbound and outbound confirmed' => [fn () => Connection::factory()->operational()->make(), ConnectionHealth::Operational],
    'error after the last success' => [fn () => Connection::factory()->operational()->make(['last_error_at' => now()]), ConnectionHealth::Error],
    'error before the last success' => [fn () => Connection::factory()->operational()->make(['last_error_at' => now()->subWeek()]), ConnectionHealth::Operational],
    'error without any success' => [fn () => Connection::factory()->make(['last_error_at' => now()]), ConnectionHealth::Error],
    'deactivated' => [fn () => Connection::factory()->operational()->inactive()->make(), ConnectionHealth::Inactive],
]);

test('saved configuration alone never reports the connection as operational', function () {
    $connection = Connection::factory()->verified()->create([
        'webhook_verified_at' => now(),
        'phone_status' => 'CONNECTED',
    ]);

    expect($connection->refresh()->health())->not->toBe(ConnectionHealth::Operational)
        ->and($connection->health())->toBe(ConnectionHealth::Receiving);
});

test('the configuration health ignores the inactive overlay', function () {
    $connection = Connection::factory()->withInvalidCredentials()->inactive()->make();

    expect($connection->health())->toBe(ConnectionHealth::Inactive)
        ->and($connection->configurationHealth())->toBe(ConnectionHealth::CredentialsInvalid);
});
