<?php

use App\Models\Connection;
use Illuminate\Support\Facades\DB;

test('connection secrets are encrypted at rest', function () {
    $connection = Connection::factory()->create([
        'access_token' => 'EAAG-plain-access-token',
        'app_secret' => 'plain-app-secret',
        'webhook_verify_token' => 'plain-verify-token',
    ]);

    $stored = DB::table('connections')->find($connection->id);

    expect($stored->access_token)->not->toContain('plain-access-token')
        ->and($stored->app_secret)->not->toContain('plain-app-secret')
        ->and($stored->webhook_verify_token)->not->toContain('plain-verify-token')
        ->and($connection->refresh()->access_token)->toBe('EAAG-plain-access-token');
});

test('connection secrets are never serialized', function () {
    $connection = Connection::factory()->create([
        'access_token' => 'EAAG-plain-access-token',
        'app_secret' => 'plain-app-secret',
        'webhook_verify_token' => 'plain-verify-token',
    ])->refresh();

    expect($connection->toArray())->not->toHaveKeys(['access_token', 'app_secret', 'webhook_verify_token'])
        ->and($connection->toJson())->not->toContain('plain-access-token', 'plain-app-secret', 'plain-verify-token');
});
