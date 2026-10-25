<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Connections\DisconnectMetaAccount;
use App\Enums\ConnectionChannel;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Meta\MetaApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Callbacks Meta calls when a person removes the app (Deauthorize Callback
 * URL) or asks for their data to be deleted (Data Deletion Request URL).
 * Both carry a `signed_request` (base64url signature "." base64url JSON,
 * HMAC-SHA256 of the payload part with the app secret; the Facebook or the
 * Instagram app secret is accepted).
 *
 * The only data of that person this application keeps is the access they
 * granted: the Messenger / Instagram connections they authorized are
 * disconnected (tokens forgotten, no call to Meta, audited). Conversations
 * with customers belong to the business and are not deleted. The deletion
 * callback answers {url, confirmation_code} as Meta requires; the status
 * page shows what was done for 30 days.
 */
class MetaDataCallbackController extends Controller
{
    public const STATUS_DAYS = 30;

    public function deauthorize(Request $request, MetaApp $app, DisconnectMetaAccount $disconnect): JsonResponse
    {
        $userId = $this->verifiedUserId($request, $app);

        if ($userId === null) {
            return response()->json([], 400);
        }

        $this->disconnectAuthorizedBy($userId, $disconnect);

        return response()->json(['success' => true]);
    }

    public function deletion(Request $request, MetaApp $app, DisconnectMetaAccount $disconnect): JsonResponse
    {
        $userId = $this->verifiedUserId($request, $app);

        if ($userId === null) {
            return response()->json([], 400);
        }

        $count = $this->disconnectAuthorizedBy($userId, $disconnect);
        $code = Str::lower(Str::random(20));

        Cache::put('meta-data-deletion:'.$code, ['at' => now()->toIso8601String(), 'connections' => $count], now()->addDays(self::STATUS_DAYS));

        return response()->json([
            'url' => route('meta.data-deletion.status', ['code' => $code]),
            'confirmation_code' => $code,
        ]);
    }

    public function status(string $code): Response
    {
        $record = Cache::get('meta-data-deletion:'.$code);

        abort_unless(is_array($record), 404);

        return response(
            'Solicitud '.$code.' atendida: se eliminaron los accesos de Meta que esta persona había concedido ('.(int) ($record['connections'] ?? 0).' conexiones desconectadas). Chat Manager no conserva otros datos de su cuenta.',
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'],
        );
    }

    /**
     * The user id of a signed_request whose signature matches one of the app secrets.
     */
    private function verifiedUserId(Request $request, MetaApp $app): ?string
    {
        $signed = $request->input('signed_request');

        if (! is_string($signed) || strlen($signed) > 4096 || substr_count($signed, '.') !== 1) {
            return null;
        }

        [$signature, $payload] = explode('.', $signed, 2);
        $expected = self::base64UrlDecode($signature);
        $valid = false;

        foreach ($app->webhookSecrets() as $secret) {
            $valid = ($expected !== null && hash_equals(hash_hmac('sha256', $payload, $secret, true), $expected)) || $valid;
        }

        $data = $valid ? json_decode((string) self::base64UrlDecode($payload), true) : null;

        if (! is_array($data) || strtoupper((string) ($data['algorithm'] ?? '')) !== 'HMAC-SHA256' || ! is_scalar($data['user_id'] ?? null)) {
            Log::warning('Meta data callback refused: invalid signed request.');

            return null;
        }

        return (string) $data['user_id'];
    }

    private function disconnectAuthorizedBy(string $userId, DisconnectMetaAccount $disconnect): int
    {
        $connections = Connection::query()
            ->whereIn('channel', [ConnectionChannel::Messenger, ConnectionChannel::Instagram])
            ->whereNotNull('access_token')
            ->where('provider_signals->meta_authorization->value->user_id', $userId)
            ->get();

        foreach ($connections as $connection) {
            $disconnect->handle($connection, null, 'deauthorized', callMeta: false);
        }

        return $connections->count();
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
