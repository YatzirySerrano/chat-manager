<?php

namespace App\Services\Meta;

use App\Enums\ConnectionChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Client of the Meta APIs used by Facebook Messenger (Graph API,
 * graph.facebook.com) and Instagram with Instagram Login
 * (graph.instagram.com, api.instagram.com): OAuth code exchange, long-lived
 * tokens, the accounts of the person who authorized, webhook subscriptions,
 * the Send API and user profiles.
 *
 * Every call made with a token carries appsecret_proof (HMAC-SHA256 of the
 * token with the app secret). Tokens travel in the Authorization header or,
 * only where Meta requires it (Instagram token exchange and refresh), as a
 * query parameter of a request that is never logged. Failures become a
 * MetaGraphException with a Spanish explanation; Meta's code goes to the
 * exception and its English detail, sanitized, to the application log.
 */
class MetaPlatformClient
{
    public function __construct(private MetaApp $app) {}

    /**
     * Facebook Login: authorization code → short-lived user token.
     *
     * @throws MetaGraphException
     */
    public function exchangeFacebookCode(string $code, string $redirectUri): string
    {
        $payload = $this->call(ConnectionChannel::Messenger, 'get', $this->graphUrl(ConnectionChannel::Messenger, 'oauth/access_token'), [
            'client_id' => (string) $this->app->clientId(ConnectionChannel::Messenger),
            'client_secret' => (string) $this->app->clientSecret(ConnectionChannel::Messenger),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ], null, 'la autorización con Facebook');

        return $this->requiredString($payload, 'access_token', 'la autorización con Facebook');
    }

    /**
     * Short-lived user token → long-lived user token (about 60 days).
     *
     * @throws MetaGraphException
     */
    public function longLivedFacebookToken(string $userToken): string
    {
        $payload = $this->call(ConnectionChannel::Messenger, 'get', $this->graphUrl(ConnectionChannel::Messenger, 'oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => (string) $this->app->clientId(ConnectionChannel::Messenger),
            'client_secret' => (string) $this->app->clientSecret(ConnectionChannel::Messenger),
            'fb_exchange_token' => $userToken,
        ], null, 'la autorización con Facebook');

        return $this->requiredString($payload, 'access_token', 'la autorización con Facebook');
    }

    /**
     * Permissions the person granted (status "granted" only).
     *
     * @return list<string>
     *
     * @throws MetaGraphException
     */
    public function facebookPermissions(string $userToken): array
    {
        $payload = $this->call(ConnectionChannel::Messenger, 'get', $this->graphUrl(ConnectionChannel::Messenger, 'me/permissions'), [], $userToken, 'la consulta de permisos');
        $granted = [];

        foreach ((array) ($payload['data'] ?? []) as $row) {
            if (is_array($row) && ($row['status'] ?? null) === 'granted' && is_string($row['permission'] ?? null)) {
                $granted[] = $row['permission'];
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * App-scoped Facebook user id of the person who authorized (it identifies
     * them in Meta's deauthorize and data deletion callbacks).
     *
     * @throws MetaGraphException
     */
    public function facebookUserId(string $userToken): ?string
    {
        $payload = $this->call(ConnectionChannel::Messenger, 'get', $this->graphUrl(ConnectionChannel::Messenger, 'me'), ['fields' => 'id'], $userToken, 'la consulta de la cuenta de Facebook');

        return is_scalar($payload['id'] ?? null) && (string) $payload['id'] !== '' ? Str::limit((string) $payload['id'], 64, '') : null;
    }

    /**
     * Facebook pages the person can manage, with their page tokens (page
     * tokens obtained from a long-lived user token do not expire).
     *
     * @return list<array{id: string, name: string, picture: string|null, access_token: string, tasks: list<string>}>
     *
     * @throws MetaGraphException
     */
    public function pages(string $userToken): array
    {
        $payload = $this->call(ConnectionChannel::Messenger, 'get', $this->graphUrl(ConnectionChannel::Messenger, 'me/accounts'), [
            'fields' => 'id,name,picture{url},access_token,tasks',
            'limit' => 100,
        ], $userToken, 'la consulta de páginas');

        $pages = [];

        foreach ((array) ($payload['data'] ?? []) as $page) {
            if (! is_array($page) || ! is_scalar($page['id'] ?? null) || ! is_string($page['access_token'] ?? null) || $page['access_token'] === '') {
                continue;
            }

            $picture = $page['picture']['data']['url'] ?? $page['picture']['url'] ?? null;

            $pages[] = [
                'id' => Str::limit((string) $page['id'], 64, ''),
                'name' => Str::limit(is_string($page['name'] ?? null) ? $page['name'] : 'Página de Facebook', 190, ''),
                'picture' => is_string($picture) && str_starts_with($picture, 'https://') ? Str::limit($picture, 2000, '') : null,
                'access_token' => $page['access_token'],
                'tasks' => array_values(array_filter((array) ($page['tasks'] ?? []), 'is_string')),
            ];
        }

        return $pages;
    }

    /**
     * Instagram Login: authorization code → short-lived Instagram user token.
     *
     * @return array{access_token: string, user_id: string|null, permissions: list<string>}
     *
     * @throws MetaGraphException
     */
    public function exchangeInstagramCode(string $code, string $redirectUri): array
    {
        $payload = $this->call(ConnectionChannel::Instagram, 'postForm', 'https://api.instagram.com/oauth/access_token', [
            'client_id' => (string) $this->app->clientId(ConnectionChannel::Instagram),
            'client_secret' => (string) $this->app->clientSecret(ConnectionChannel::Instagram),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ], null, 'la autorización con Instagram');

        // Meta documents both a flat answer and one wrapped in "data".
        $row = is_array($payload['data'][0] ?? null) ? $payload['data'][0] : $payload;
        $permissions = $row['permissions'] ?? [];

        return [
            'access_token' => $this->requiredString($row, 'access_token', 'la autorización con Instagram'),
            'user_id' => is_scalar($row['user_id'] ?? null) ? (string) $row['user_id'] : null,
            'permissions' => array_values(array_filter(is_string($permissions) ? explode(',', $permissions) : (array) $permissions, fn (mixed $value): bool => is_string($value) && $value !== '')),
        ];
    }

    /**
     * Short-lived Instagram token → long-lived token (60 days).
     *
     * @return array{access_token: string, expires_in: int|null}
     *
     * @throws MetaGraphException
     */
    public function longLivedInstagramToken(string $shortToken): array
    {
        $payload = $this->call(ConnectionChannel::Instagram, 'get', $this->instagramUrl('access_token', versioned: false), [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => (string) $this->app->clientSecret(ConnectionChannel::Instagram),
            'access_token' => $shortToken,
        ], null, 'la autorización con Instagram', $shortToken);

        return $this->tokenWithExpiry($payload, 'la autorización con Instagram');
    }

    /**
     * Refresh a long-lived Instagram token (it must be at least 24 hours old and not expired).
     *
     * @return array{access_token: string, expires_in: int|null}
     *
     * @throws MetaGraphException
     */
    public function refreshInstagramToken(string $token): array
    {
        $payload = $this->call(ConnectionChannel::Instagram, 'get', $this->instagramUrl('refresh_access_token', versioned: false), [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ], null, 'la renovación del token de Instagram', $token);

        return $this->tokenWithExpiry($payload, 'la renovación del token de Instagram');
    }

    /**
     * The Instagram professional account of the token. `user_id` is the id
     * webhooks use as entry id.
     *
     * @return array{id: string, username: string|null, name: string|null, picture: string|null}
     *
     * @throws MetaGraphException
     */
    public function instagramAccount(string $token): array
    {
        $payload = $this->call(ConnectionChannel::Instagram, 'get', $this->instagramUrl('me'), [
            'fields' => 'user_id,username,name,profile_picture_url',
        ], $token, 'la consulta de la cuenta de Instagram');

        $id = $payload['user_id'] ?? $payload['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw new MetaGraphException('Instagram no devolvió el identificador de la cuenta profesional.', rejected: false, kind: MetaGraphException::AMBIGUOUS);
        }

        $picture = $payload['profile_picture_url'] ?? null;

        return [
            'id' => Str::limit((string) $id, 64, ''),
            'username' => is_string($payload['username'] ?? null) ? Str::limit($payload['username'], 190, '') : null,
            'name' => is_string($payload['name'] ?? null) ? Str::limit($payload['name'], 190, '') : null,
            'picture' => is_string($picture) && str_starts_with($picture, 'https://') ? Str::limit($picture, 2000, '') : null,
        ];
    }

    /**
     * Subscribe the page (Messenger) or the Instagram account to this app's webhook fields.
     *
     * @param  list<string>  $fields
     *
     * @throws MetaGraphException
     */
    public function subscribe(ConnectionChannel $channel, string $accountId, string $token, array $fields): bool
    {
        $payload = $this->call($channel, 'postForm', $this->subscriptionUrl($channel, $accountId), [
            'subscribed_fields' => implode(',', $fields),
        ], $token, 'la suscripción al webhook');

        return ($payload['success'] ?? false) === true;
    }

    /**
     * Whether this app is subscribed to the account's webhook, and with which fields.
     *
     * @return array{subscribed: bool, fields: list<string>}
     *
     * @throws MetaGraphException
     */
    public function subscription(ConnectionChannel $channel, string $accountId, string $token): array
    {
        $payload = $this->call($channel, 'get', $this->subscriptionUrl($channel, $accountId), [], $token, 'la consulta de la suscripción');
        $appId = $this->app->clientId($channel);

        foreach ((array) ($payload['data'] ?? []) as $app) {
            if (! is_array($app)) {
                continue;
            }

            // Instagram answers only for the calling app (no id).
            if ($channel === ConnectionChannel::Instagram || (string) ($app['id'] ?? '') === (string) $appId) {
                return ['subscribed' => true, 'fields' => array_values(array_filter((array) ($app['subscribed_fields'] ?? []), 'is_string'))];
            }
        }

        return ['subscribed' => false, 'fields' => []];
    }

    /**
     * @throws MetaGraphException
     */
    public function unsubscribe(ConnectionChannel $channel, string $accountId, string $token): void
    {
        $this->call($channel, 'delete', $this->subscriptionUrl($channel, $accountId), [], $token, 'la cancelación de la suscripción');
    }

    /**
     * Send API: POST /{page-id}/messages (Messenger) or /me/messages (Instagram).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws MetaGraphException
     */
    public function sendMessage(ConnectionChannel $channel, string $accountId, string $token, array $payload): string
    {
        $url = $channel === ConnectionChannel::Instagram
            ? $this->instagramUrl('me/messages')
            : $this->graphUrl($channel, rawurlencode($accountId).'/messages');

        $response = $this->call($channel, 'postJson', $url, $payload, $token, 'el envío del mensaje');
        $id = $response['message_id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new MetaGraphException('Meta respondió sin el identificador del mensaje; no es posible confirmar el envío.', rejected: false, kind: MetaGraphException::AMBIGUOUS);
        }

        return $id;
    }

    /**
     * Public profile of a customer (PSID or IGSID) when the app may read it.
     *
     * @return array<array-key, mixed>
     *
     * @throws MetaGraphException
     */
    public function profile(ConnectionChannel $channel, string $userId, string $token): array
    {
        $url = $channel === ConnectionChannel::Instagram
            ? $this->instagramUrl(rawurlencode($userId))
            : $this->graphUrl($channel, rawurlencode($userId));

        return $this->call($channel, 'get', $url, [
            'fields' => $channel === ConnectionChannel::Instagram ? 'name,username,profile_pic' : 'first_name,last_name,profile_pic',
        ], $token, 'la consulta del perfil');
    }

    public function graphUrl(ConnectionChannel $channel, string $path): string
    {
        if ($channel === ConnectionChannel::Instagram) {
            return $this->instagramUrl($path);
        }

        return rtrim((string) config('services.meta.base_url'), '/').'/'.MetaApp::graphVersion().'/'.ltrim($path, '/');
    }

    public function instagramUrl(string $path, bool $versioned = true): string
    {
        $base = rtrim((string) config('services.meta.instagram_base_url', 'https://graph.instagram.com'), '/');

        return $base.($versioned ? '/'.MetaApp::graphVersion() : '').'/'.ltrim($path, '/');
    }

    private function subscriptionUrl(ConnectionChannel $channel, string $accountId): string
    {
        return $channel === ConnectionChannel::Instagram
            ? $this->instagramUrl('me/subscribed_apps')
            : $this->graphUrl($channel, rawurlencode($accountId).'/subscribed_apps');
    }

    /**
     * @param  'get'|'postForm'|'postJson'|'delete'  $method
     * @param  array<string, mixed>  $data
     * @param  string|null  $token  Sent in the Authorization header, with its appsecret_proof.
     * @param  string|null  $secretInQuery  A token that travels in the query (only to sanitize errors).
     * @return array<array-key, mixed>
     *
     * @throws MetaGraphException
     */
    private function call(ConnectionChannel $channel, string $method, string $url, array $data, ?string $token, string $operation, ?string $secretInQuery = null): array
    {
        $timeout = max(1, (int) config('services.meta.timeout'));
        $request = Http::acceptJson()->connectTimeout(min(5, $timeout))->timeout($timeout);

        if ($token !== null) {
            $request = $request->withToken($token);
            $proof = $this->app->proof($channel, $token);

            // A GET/DELETE query replaces the URL's own query string, so the
            // proof goes with the parameters there and in the URL of a POST.
            if ($proof !== null && in_array($method, ['get', 'delete'], true)) {
                $data['appsecret_proof'] = $proof;
            } elseif ($proof !== null) {
                $url .= (str_contains($url, '?') ? '&' : '?').'appsecret_proof='.$proof;
            }
        }

        try {
            $response = match ($method) {
                'get' => $request->get($url, $data),
                'delete' => $request->delete($data === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($data)),
                'postForm' => $request->asForm()->post($url, $data),
                'postJson' => $request->asJson()->post($url, $data),
            };
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception, $operation);
        }

        if (! $response->successful()) {
            throw $this->failure($response, $operation, array_values(array_filter([$token, $secretInQuery, $this->app->clientSecret($channel)])));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new MetaGraphException("Meta devolvió una respuesta inesperada durante {$operation}. Inténtalo de nuevo más tarde.", rejected: false, status: $response->status(), kind: MetaGraphException::AMBIGUOUS);
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws MetaGraphException
     */
    private function requiredString(array $payload, string $key, string $operation): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new MetaGraphException("Meta no devolvió el dato esperado durante {$operation}. Inténtalo de nuevo.", rejected: false, kind: MetaGraphException::AMBIGUOUS);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{access_token: string, expires_in: int|null}
     *
     * @throws MetaGraphException
     */
    private function tokenWithExpiry(array $payload, string $operation): array
    {
        return [
            'access_token' => $this->requiredString($payload, 'access_token', $operation),
            'expires_in' => is_numeric($payload['expires_in'] ?? null) ? (int) $payload['expires_in'] : null,
        ];
    }

    private function connectionFailure(ConnectionException $exception, string $operation): MetaGraphException
    {
        $detail = $exception->getMessage();
        $neverSent = preg_match('/cURL error (6|7|35|60):/', $detail) === 1
            || preg_match('/cURL error 28: (Connection|Resolving) timed out/i', $detail) === 1
            || str_contains($detail, 'Could not resolve host')
            || str_contains($detail, 'Connection refused');

        return $neverSent
            ? new MetaGraphException("No fue posible comunicarse con Meta durante {$operation}. Inténtalo de nuevo en unos minutos.", rejected: false, kind: MetaGraphException::UNREACHABLE)
            : new MetaGraphException("Meta no respondió a tiempo durante {$operation}; no es posible saber si la procesó.", rejected: false, kind: MetaGraphException::AMBIGUOUS);
    }

    /**
     * @param  list<string>  $secrets  Values removed from anything logged.
     */
    private function failure(Response $response, string $operation, array $secrets): MetaGraphException
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $code = isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) && is_numeric($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $detail = is_string($error['message'] ?? null) ? $error['message'] : (is_string($response->json('error_message')) ? $response->json('error_message') : '');

        foreach ($secrets as $secret) {
            $detail = str_replace($secret, '[secreto]', $detail);
        }

        Log::warning('Meta platform request failed', [
            'operation' => $operation,
            'meta_code' => $code,
            'meta_subcode' => $subcode,
            'http_status' => $response->status(),
            'detail' => Str::limit(trim(strip_tags($detail)), 300),
        ]);

        [$kind, $message, $rejected] = match (true) {
            $response->status() === 429 || MetaErrorCatalog::isThrottling($code) => [MetaGraphException::RATE_LIMITED, "Meta limitó {$operation} por exceso de solicitudes. Se reintentará más tarde.", false],
            $response->serverError() || MetaErrorCatalog::isTemporary($code) => [$error === [] ? MetaGraphException::AMBIGUOUS : MetaGraphException::TRANSIENT, "Meta no pudo atender {$operation} en este momento. Inténtalo más tarde.", false],
            $code === 10 && $subcode === 2018278, $code === 10 && $subcode === 2534022 => [MetaGraphException::MESSAGE_REJECTED, 'Meta no permite enviar este mensaje: pasaron más de 24 horas desde el último mensaje del cliente.', true],
            $code === 551 || $subcode === 1545041 => [MetaGraphException::MESSAGE_REJECTED, 'La persona no está disponible en este momento (bloqueó la página o desactivó su cuenta).', true],
            in_array($code, [0, 190, 102], true) || ($code === null && $response->status() === 401) => [MetaGraphException::TOKEN_INVALID, 'La autorización de Meta venció o fue revocada. Vuelve a autorizar la cuenta desde Conexiones.', true],
            in_array($code, [3, 10, 200, 230], true) || ($code !== null && $code >= 200 && $code < 300) || ($code === null && $response->status() === 403) => [MetaGraphException::PERMISSION, 'Meta no concedió el permiso necesario para esta operación. Revisa los permisos de la app (App Review / acceso avanzado) y vuelve a autorizar.', true],
            $code === 100 && $operation === 'la consulta del perfil' => [MetaGraphException::PERMISSION, 'Meta no permite consultar el perfil de esta persona.', true],
            $code === 100 || $response->status() === 400 || $response->status() === 404 => [MetaGraphException::INVALID_REQUEST, "Meta rechazó {$operation}: un dato no es válido o el código de autorización ya se usó o venció.", true],
            default => [MetaGraphException::REJECTED, MetaErrorCatalog::explain($code, "Meta rechazó {$operation}."), true],
        };

        return new MetaGraphException(Str::limit($message, 480), rejected: $rejected, metaCode: $code, status: $response->status(), kind: $kind);
    }
}
