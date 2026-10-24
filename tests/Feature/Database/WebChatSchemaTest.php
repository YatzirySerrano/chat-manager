<?php

use App\Models\ApiToken;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WebChatFixtures;

/*
| Tables of the web chat channel and the integration API: additive and
| reversible, with the unique keys the bearer tokens and the 1:1 widget rely on.
*/

/**
 * @return list<string>
 */
function webChatMigrations(): array
{
    return array_map(fn (string $path): string => 'database/migrations/'.basename($path), [
        ...glob(database_path('migrations/*_create_web_chat_widgets_table.php')) ?: [],
        ...glob(database_path('migrations/*_create_web_chat_sessions_table.php')) ?: [],
        ...glob(database_path('migrations/*_create_api_tokens_table.php')) ?: [],
    ]);
}

/*
 * Schema changes commit the surrounding test transaction, so the database is
 * rebuilt before the next test.
 */
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

test('the three tables carry the documented columns', function () {
    expect(Schema::hasColumns('web_chat_widgets', [
        'connection_id', 'public_key', 'allowed_origins', 'title', 'subtitle', 'welcome_text', 'offline_text', 'position',
        'accent_color', 'launcher_enabled', 'launcher_label', 'avatar_path', 'identity_mode', 'identity_secret',
        'identity_secret_rotated_at', 'prechat', 'availability_mode', 'session_idle_days', 'key_rotated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('web_chat_sessions', [
            'public_id', 'connection_id', 'visitor_id', 'identified', 'token_hash', 'contact_id', 'profile', 'origin',
            'landing_url', 'ip_hash', 'user_agent', 'last_seen_at', 'expires_at', 'revoked_at', 'revoke_reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('api_tokens', [
            'public_id', 'name', 'token_hash', 'abilities', 'connection_ids', 'department_ids', 'allowed_ips', 'expires_at',
            'last_used_at', 'created_by', 'revoked_at', 'revoked_by', 'revoke_reason',
        ]))->toBeTrue();
});

test('a connection has at most one widget and keys and token hashes are unique', function (Closure $duplicate) {
    expect($duplicate)->toThrow(UniqueConstraintViolationException::class);
})->with([
    'second widget of a connection' => [fn () => WebChatWidget::factory()->create(['connection_id' => WebChatFixtures::connection()->id])],
    'repeated public key' => [fn () => WebChatWidget::factory()->create(['public_key' => WebChatFixtures::connection()->webChatWidget->public_key])],
    'repeated session token hash' => [fn () => WebChatSession::factory()->create(['token_hash' => WebChatSession::factory()->create()->token_hash])],
    'repeated session public id' => [fn () => WebChatSession::factory()->create(['public_id' => WebChatSession::factory()->create()->public_id])],
    'repeated api token hash' => [fn () => ApiToken::factory()->create(['token_hash' => ApiToken::factory()->create()->token_hash])],
]);

test('the migrations roll back and apply again', function () {
    expect(webChatMigrations())->toHaveCount(3);

    $this->artisan('migrate:rollback', ['--path' => webChatMigrations(), '--force' => true])->assertSuccessful();

    expect(Schema::hasTable('web_chat_widgets'))->toBeFalse()
        ->and(Schema::hasTable('web_chat_sessions'))->toBeFalse()
        ->and(Schema::hasTable('api_tokens'))->toBeFalse()
        ->and(Schema::hasTable('connections'))->toBeTrue();

    $this->artisan('migrate', ['--path' => webChatMigrations(), '--force' => true])->assertSuccessful();

    expect(Schema::hasTable('web_chat_widgets'))->toBeTrue()
        ->and(Schema::hasTable('web_chat_sessions'))->toBeTrue()
        ->and(Schema::hasTable('api_tokens'))->toBeTrue();
});
