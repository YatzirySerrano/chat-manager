<?php

namespace Tests\Support;

use App\Models\Connection;
use App\Services\WhatsAppWeb\InternalSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Doubles of the WhatsApp Web (QR) contract (docs/whatsapp-web-qr-contrato.md)
 * for the automated tests: the payloads the Node service sends and answers.
 * Nothing here talks to WhatsApp or to a real service.
 */
class WhatsAppWebFixtures
{
    public const SECRET = 'test-secret-of-the-whatsapp-web-service-0123456789';

    public const SERVICE = 'http://127.0.0.1:3410';

    /**
     * Enable the modality with a shared secret, as a configured server.
     */
    public static function configure(): void
    {
        config([
            'whatsapp_web.enabled' => true,
            'whatsapp_web.secret' => self::SECRET,
            'whatsapp_web.url' => self::SERVICE,
            'whatsapp_web.allowed_ips' => [],
        ]);
    }

    /**
     * A Session payload (§4) of the service.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function session(Connection|int $connection, string $state, array $overrides = []): array
    {
        return [
            'connection_id' => $connection instanceof Connection ? $connection->id : $connection,
            'state' => $state,
            'phone' => null,
            'push_name' => null,
            'qr' => null,
            'connected_since' => null,
            'last_event_at' => now()->getTimestamp(),
            'last_inbound_at' => null,
            'last_outbound_at' => null,
            'reconnects' => 0,
            'last_error' => null,
            ...$overrides,
        ];
    }

    /**
     * An InboundMessage payload (§5).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function inbound(array $overrides = []): array
    {
        return [
            'provider_message_id' => '3EB0'.strtoupper(Str::random(16)),
            'remote_jid' => '5215511112222@s.whatsapp.net',
            'phone' => '5215511112222',
            'push_name' => 'Gestor Uno',
            'from_me' => false,
            'timestamp' => now()->getTimestamp(),
            'type' => 'text',
            'text' => 'Buenos días, reporto la visita',
            'quoted' => null,
            ...$overrides,
        ];
    }

    /**
     * Headers of a correctly signed request of the service.
     *
     * @return array<string, string>
     */
    public static function headers(string $method, string $path, string $body, ?string $secret = null, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = (string) ($timestamp ?? now()->getTimestamp());
        $nonce ??= (string) Str::uuid();

        return [
            'X-CM-Timestamp' => $timestamp,
            'X-CM-Nonce' => $nonce,
            'X-CM-Signature' => InternalSignature::compute($secret ?? self::SECRET, $timestamp, $nonce, $method, $path, $body),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Deliver one signed event to POST /internal/whatsapp-web/events.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>|null  $headers  Replace the signature headers (to test refusals).
     */
    public static function event(mixed $test, Connection|int $connection, string $type, array $data, ?string $eventId = null, ?array $headers = null): TestResponse
    {
        $body = (string) json_encode([
            'event_id' => $eventId ?? (string) Str::uuid(),
            'connection_id' => $connection instanceof Connection ? $connection->id : $connection,
            'type' => $type,
            'occurred_at' => now()->getTimestamp(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $path = '/internal/whatsapp-web/events';
        $headers ??= self::headers('POST', $path, $body);
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $server['CONTENT_TYPE'] = 'application/json';

        return $test->call('POST', $path, [], [], [], $server, $body);
    }

    /**
     * Whether a request captured by Http::fake carries a valid signature
     * of the contract for its exact method, path and body.
     */
    public static function isSigned(Request $request): bool
    {
        $timestamp = $request->header('X-CM-Timestamp')[0] ?? '';
        $nonce = $request->header('X-CM-Nonce')[0] ?? '';
        $signature = $request->header('X-CM-Signature')[0] ?? '';
        $url = parse_url($request->url());
        $path = ($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');

        return $timestamp !== '' && $nonce !== ''
            && hash_equals(InternalSignature::compute(self::SECRET, $timestamp, $nonce, $request->method(), $path, $request->body()), $signature);
    }
}
