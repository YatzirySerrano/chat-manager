<?php

namespace Tests\Support;

use App\Models\Connection;
use App\Models\WebChatWidget;
use App\Services\WebChat\FrameTicket;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The widget iframe's side of the visitor API in tests: frame tickets,
 * host-signed identities and the requests the widget makes.
 */
final class WebChatVisitor
{
    public const ORIGIN = 'https://tienda.test';

    public static function ticket(WebChatWidget $widget, string $origin = self::ORIGIN): string
    {
        return app(FrameTicket::class)->issue($widget, $origin);
    }

    /**
     * An identity signed like the host's backend does (see VisitorIdentity).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function identity(WebChatWidget $widget, string $externalId = 'cliente-42', array $overrides = [], ?string $secret = null): array
    {
        $identity = [
            'external_id' => $externalId,
            'name' => 'Ana Torres',
            'email' => 'ana@tienda.test',
            'iat' => now()->getTimestamp(),
            'nonce' => Str::random(24),
            ...$overrides,
        ];

        $identity['signature'] ??= hash_hmac('sha256', implode("\n", [
            'v1', $widget->public_key, $identity['external_id'], $identity['email'] ?? '', $identity['name'] ?? '', (string) $identity['iat'], $identity['nonce'],
        ]), $secret ?? (string) $widget->identity_secret);

        return $identity;
    }

    /**
     * POST sessions with a valid ticket (plus the given body).
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    public static function start(TestCase $test, Connection $connection, array $body = []): TestResponse
    {
        /** @var WebChatWidget $widget */
        $widget = $connection->webChatWidget()->firstOrFail();

        return $test->postJson(route('api.v1.widget.sessions.store', $widget->public_key), [
            'ticket' => self::ticket($widget),
            ...$body,
        ]);
    }

    /**
     * A new session's plain token.
     *
     * @param  array<string, mixed>  $body
     */
    public static function token(TestCase $test, Connection $connection, array $body = []): string
    {
        return (string) self::start($test, $connection, $body)->assertCreated()->json('token');
    }

    /**
     * @return TestResponse<Response>
     */
    public static function send(TestCase $test, string $token, string $body = 'Hola, necesito ayuda', ?string $clientMessageId = null): TestResponse
    {
        return $test->withToken($token)->postJson(route('api.v1.widget.session.messages.store'), [
            'client_message_id' => $clientMessageId ?? (string) Str::uuid(),
            'body' => $body,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    public static function messages(TestCase $test, string $token, array $query = []): TestResponse
    {
        return $test->withToken($token)->getJson(route('api.v1.widget.session.messages.index', $query));
    }
}
