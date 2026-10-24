<?php

namespace Tests\Support;

use App\Models\ApiToken;
use App\Models\Connection;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;

/**
 * Builders of web chat connections, visitor sessions and integration API
 * tokens for the tests of the web chat channel and the /api/v1 surfaces.
 * Plain tokens are only returned here: the database keeps their hash.
 */
final class WebChatFixtures
{
    /**
     * An active web chat connection with its widget (https://tienda.test is
     * authorized unless the widget overrides say otherwise).
     *
     * @param  array<string, mixed>  $widget
     * @param  array<string, mixed>  $connection
     */
    public static function connection(array $widget = [], array $connection = []): Connection
    {
        $model = Connection::factory()->webChat()->create($connection);

        if ($widget !== []) {
            /** @var WebChatWidget $settings */
            $settings = $model->webChatWidget()->firstOrFail();
            $settings->forceFill($widget)->save();
        }

        return $model->load('webChatWidget');
    }

    /**
     * A usable visitor session of the connection and its plain bearer token.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{session: WebChatSession, token: string}
     */
    public static function session(Connection $connection, array $overrides = []): array
    {
        $token = '';

        $session = WebChatSession::factory()
            ->withToken(function (string $plain) use (&$token): void {
                $token = $plain;
            })
            ->create(['connection_id' => $connection->id, ...$overrides]);

        return ['session' => $session, 'token' => $token];
    }

    /**
     * A usable integration API token (every ability) and its plain value.
     * Pass connection_ids to allowlist connections.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{token: ApiToken, plain: string}
     */
    public static function apiToken(array $overrides = []): array
    {
        $token = ApiToken::factory()->make($overrides);
        $plain = $token->generateToken();
        $token->save();

        return ['token' => $token, 'plain' => $plain];
    }
}
