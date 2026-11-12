<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\WhatsAppWeb\InternalSignature;
use App\Services\WhatsAppWeb\WhatsAppWebEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Events of the private WhatsApp Web service (contract §5):
 * POST /internal/whatsapp-web/events, one event per request.
 *
 * It has no session, cookies or CSRF token: every request is authenticated
 * by the shared HMAC signature (timestamp, single-use nonce and the hash of
 * the exact body), only from the addresses the service runs on, with a size
 * limit and a rate limit. An event is processed once: repeats (the service
 * retries until it gets a 2xx) are discarded by their event id, and by the
 * unique id of the message they carry.
 *
 * Answers: 200 ok (also for repeats), 401 when the signature fails, 404
 * when the connection does not exist, was deleted or is not a WhatsApp Web
 * one (the service then drops the event and stops that session) and 5xx so
 * the service tries again.
 */
class WhatsAppWebEventController extends Controller
{
    public function __invoke(Request $request, InternalSignature $signature, WhatsAppWebEvents $events): JsonResponse
    {
        $body = $request->getContent();

        if (strlen($body) > (int) config('whatsapp_web.events.max_body_bytes')) {
            return response()->json(['error' => 'too_large'], 413);
        }

        if (! self::fromService($request)) {
            Log::warning('WhatsApp Web event refused: it does not come from the service address.');

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $refusal = $signature->refusal(
            $request->headers->get('X-CM-Timestamp'),
            $request->headers->get('X-CM-Nonce'),
            $request->headers->get('X-CM-Signature'),
            $request->getMethod(),
            $request->getRequestUri(),
            $body,
        );

        if ($refusal !== null) {
            Log::warning('WhatsApp Web event refused.', ['reason' => $refusal]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $event = json_decode($body, true);

        if (! is_array($event) || ! is_string($event['event_id'] ?? null) || preg_match('/^[A-Za-z0-9-]{8,64}$/', $event['event_id']) !== 1
            || ! is_int($event['connection_id'] ?? null) || ! is_string($event['type'] ?? null) || ! is_array($event['data'] ?? null)) {
            // Malformed for good: answering 2xx stops the service from retrying it forever.
            Log::warning('WhatsApp Web event discarded: malformed.');

            return response()->json(['status' => 'ok', 'result' => 'ignored']);
        }

        $connection = Connection::query()->find($event['connection_id']);

        if ($connection === null || $connection->isDeleted() || ! $connection->isWhatsAppWeb()) {
            return response()->json(['error' => 'unknown_connection'], 404);
        }

        $marker = 'whatsapp-web:event:'.$event['event_id'];

        if (Cache::has($marker)) {
            return response()->json(['status' => 'ok', 'result' => 'duplicate']);
        }

        // An exception answers 5xx: the service keeps the event and retries.
        $result = $events->handle($connection, $event['type'], $event['data']);

        Cache::put($marker, 1, now()->addHours(max(1, (int) config('whatsapp_web.events.dedup_hours', 48))));

        return response()->json(['status' => 'ok', 'result' => $result]);
    }

    /**
     * Whether the request comes from an address the service may run on
     * (loopback by default). The real remote address is used, never a
     * forwarded header.
     */
    public static function fromService(Request $request): bool
    {
        $allowed = array_values(array_filter(array_map('trim', (array) config('whatsapp_web.allowed_ips', []))));

        $remote = $request->server('REMOTE_ADDR');

        return $allowed === [] || (is_string($remote) && in_array($remote, $allowed, true));
    }
}
