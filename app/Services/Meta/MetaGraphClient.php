<?php

namespace App\Services\Meta;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Client for the Meta Graph API (WhatsApp Cloud API). The access token
 * travels only in the Authorization header and is never logged, stored in an
 * exception, put in a URL or echoed back in an error message.
 *
 * Every failure becomes a MetaGraphException classified by what it proves:
 * unreachable (the request never left), ambiguous (it may have been
 * processed), throttled or temporary (Meta answered that it did not process
 * it), or a refusal (token, permission, invalid request, message rejected).
 */
class MetaGraphClient
{
    /**
     * Fields requested when checking a WhatsApp business phone number.
     *
     * @var list<string>
     */
    public const PHONE_NUMBER_FIELDS = [
        'display_phone_number',
        'verified_name',
        'quality_rating',
        'code_verification_status',
        'status',
        'platform_type',
    ];

    /**
     * Hosts Meta serves media downloads from (WhatsApp media, Messenger and
     * Instagram attachment CDNs). The token is only ever sent to them.
     *
     * @var list<string>
     */
    public const MEDIA_HOSTS = ['fbsbx.com', 'facebook.com', 'fbcdn.net', 'whatsapp.net', 'cdninstagram.com'];

    /**
     * Read the profile of a WhatsApp business phone number.
     *
     * @return array{id: string|null, display_phone_number: string|null, verified_name: string|null, quality_rating: string|null, code_verification_status: string|null, status: string|null, platform_type: string|null}
     *
     * @throws MetaGraphException
     */
    public function phoneNumber(string $phoneNumberId, string $accessToken): array
    {
        $payload = $this->get($accessToken, rawurlencode($phoneNumberId), [
            'fields' => implode(',', self::PHONE_NUMBER_FIELDS),
        ], 'la verificación');

        return [
            'id' => $this->stringOrNull($payload['id'] ?? null),
            'display_phone_number' => $this->stringOrNull($payload['display_phone_number'] ?? null),
            'verified_name' => $this->stringOrNull($payload['verified_name'] ?? null),
            'quality_rating' => $this->stringOrNull($payload['quality_rating'] ?? null),
            'code_verification_status' => $this->stringOrNull($payload['code_verification_status'] ?? null),
            'status' => $this->stringOrNull($payload['status'] ?? null),
            'platform_type' => $this->stringOrNull($payload['platform_type'] ?? null),
        ];
    }

    /**
     * GET a Graph API path (relative to the configured version).
     *
     * @param  array<string, scalar>  $query
     * @return array<array-key, mixed>
     *
     * @throws MetaGraphException
     */
    public function get(string $accessToken, string $path, array $query = [], string $operation = 'la solicitud'): array
    {
        return $this->decode($this->send($accessToken, fn (PendingRequest $request): Response => $request->get(ltrim($path, '/'), $query), $operation), $accessToken, $operation);
    }

    /**
     * POST a JSON body to a Graph API path.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     *
     * @throws MetaGraphException
     */
    public function post(string $accessToken, string $path, array $data = [], string $operation = 'la solicitud'): array
    {
        return $this->decode($this->send($accessToken, fn (PendingRequest $request): Response => $request->asJson()->post(ltrim($path, '/'), $data), $operation), $accessToken, $operation);
    }

    /**
     * Upload a file to WhatsApp media storage (POST /{phone-number-id}/media)
     * and return the media id to send it by reference.
     *
     * @param  resource|string  $contents
     *
     * @throws MetaGraphException
     */
    public function upload(string $accessToken, string $phoneNumberId, $contents, string $mimeType, string $fileName): string
    {
        $payload = $this->decode($this->send($accessToken, fn (PendingRequest $request): Response => $request
            ->attach('file', $contents, $fileName, ['Content-Type' => $mimeType])
            ->post(rawurlencode($phoneNumberId).'/media', [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]), 'la carga del archivo'), $accessToken, 'la carga del archivo');

        $id = $this->stringOrNull($payload['id'] ?? null);

        if ($id === null) {
            throw new MetaGraphException('Meta no devolvió el identificador del archivo cargado.', rejected: false, kind: MetaGraphException::AMBIGUOUS);
        }

        return $id;
    }

    /**
     * Metadata of a media object (GET /{media-id}): a short-lived download URL,
     * its MIME type, size and SHA-256.
     *
     * @return array{url: string, mime_type: string|null, file_size: int|null, sha256: string|null}
     *
     * @throws MetaGraphException
     */
    public function media(string $accessToken, string $mediaId): array
    {
        $payload = $this->get($accessToken, rawurlencode($mediaId), [], 'la consulta del archivo');
        $url = $payload['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new MetaGraphException('Meta no devolvió la dirección de descarga del archivo.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        return [
            'url' => $url,
            'mime_type' => $this->stringOrNull($payload['mime_type'] ?? null),
            'file_size' => is_numeric($payload['file_size'] ?? null) ? (int) $payload['file_size'] : null,
            'sha256' => $this->stringOrNull($payload['sha256'] ?? null),
        ];
    }

    /**
     * Download a media URL returned by media() into a local file, with the
     * token in the Authorization header (null for the pre-signed CDN URLs of
     * Messenger and Instagram, which need none). Only Meta's media hosts are
     * allowed, so the token can never be sent elsewhere.
     *
     * With a size limit, the bytes are counted while they stream to disk: the
     * transfer is aborted as soon as the file exceeds it (whatever Meta
     * reported or the Content-Length said), so a large file never fills the
     * disk.
     *
     * @throws MetaGraphException
     * @throws MediaRejected When the file exceeds $maxBytes.
     */
    public function download(?string $accessToken, string $url, string $destination, ?int $maxBytes = null): void
    {
        $accessToken = (string) $accessToken;

        if (! self::isMediaUrl($url)) {
            throw new MetaGraphException('La dirección de descarga no pertenece a Meta; se descartó por seguridad.', rejected: true, kind: MetaGraphException::INVALID_REQUEST);
        }

        $exceeded = false;
        $sink = $maxBytes === null ? $destination : $this->cappedSink($destination, $maxBytes, $exceeded);

        try {
            $response = $this->send(
                $accessToken,
                fn (PendingRequest $request): Response => $request->withOptions(['sink' => $sink])->get($url),
                'la descarga del archivo',
                absolute: true,
            );
        } catch (Throwable $exception) {
            // Refusing to write aborts the transfer with a transport error.
            throw $exceeded ? self::tooLarge($maxBytes) : $exception;
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        if ($exceeded) {
            throw self::tooLarge($maxBytes);
        }

        if (! $response->successful()) {
            throw $this->failure($response, $accessToken, 'la descarga del archivo');
        }
    }

    /**
     * A writable stream on the destination file that refuses (writes 0
     * bytes, which makes cURL abort) any chunk taking the file over the limit.
     *
     * @return resource
     */
    private function cappedSink(string $destination, int $maxBytes, bool &$exceeded)
    {
        $file = new LazyOpenStream($destination, 'w+');
        $written = 0;

        return StreamWrapper::getResource(FnStream::decorate($file, [
            'write' => function (string $data) use ($file, $maxBytes, &$written, &$exceeded): int {
                if ($exceeded || $written + strlen($data) > $maxBytes) {
                    $exceeded = true;

                    return 0;
                }

                $written += strlen($data);

                return $file->write($data);
            },
        ]));
    }

    private static function tooLarge(?int $maxBytes): MediaRejected
    {
        $megabytes = rtrim(rtrim(number_format((int) $maxBytes / 1048576, 1), '0'), '.');

        return new MediaRejected('too_large', "El archivo supera el tamaño permitido ({$megabytes} MB).");
    }

    public static function isMediaUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || $host === '') {
            return false;
        }

        foreach (self::MEDIA_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws MetaGraphException
     */
    private function send(string $accessToken, callable $call, string $operation, bool $absolute = false): Response
    {
        try {
            $response = $call($this->request($accessToken, $absolute));
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception, $operation);
        }

        if (! $absolute && ! $response->successful()) {
            throw $this->failure($response, $accessToken, $operation);
        }

        return $response;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws MetaGraphException
     */
    private function decode(Response $response, string $accessToken, string $operation): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new MetaGraphException("Meta devolvió una respuesta inesperada durante {$operation}. Inténtalo de nuevo más tarde.", rejected: false, status: $response->status(), kind: MetaGraphException::AMBIGUOUS);
        }

        return $payload;
    }

    private function request(string $accessToken, bool $absolute = false): PendingRequest
    {
        $timeout = max(1, (int) config('services.meta.timeout'));
        $request = ($accessToken === '' ? Http::withHeaders([]) : Http::withToken($accessToken))
            ->acceptJson()
            ->connectTimeout(min(5, $timeout))
            ->timeout($absolute ? max(30, $timeout) : $timeout);

        return $absolute ? $request : $request->baseUrl(self::baseUrl());
    }

    public static function baseUrl(): string
    {
        return rtrim((string) config('services.meta.base_url'), '/').'/'.trim((string) config('services.meta.graph_version'), '/');
    }

    /**
     * cURL reports whether the connection was ever established: DNS errors,
     * refused connections, TLS handshake failures and connect timeouts prove
     * that nothing reached Meta; a timeout while waiting for the answer does not.
     */
    private function connectionFailure(ConnectionException $exception, string $operation): MetaGraphException
    {
        $detail = $exception->getMessage();
        $neverSent = preg_match('/cURL error (6|7|35|60):/', $detail) === 1
            || preg_match('/cURL error 28: (Connection|Resolving) timed out/i', $detail) === 1
            || str_contains($detail, 'Could not resolve host')
            || str_contains($detail, 'Connection refused');

        return $neverSent
            ? new MetaGraphException("No fue posible comunicarse con Meta durante {$operation} (sin conexión o tiempo de espera agotado). Inténtalo de nuevo en unos minutos.", rejected: false, kind: MetaGraphException::UNREACHABLE)
            : new MetaGraphException("Meta no respondió a tiempo durante {$operation}; no es posible saber si la procesó.", rejected: false, kind: MetaGraphException::AMBIGUOUS);
    }

    /**
     * Translate a Graph API error into a safe, actionable message.
     */
    private function failure(Response $response, string $accessToken, string $operation): MetaGraphException
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $code = isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : null;
        $detail = $this->sanitize(is_string($error['error_data']['details'] ?? null) ? $error['error_data']['details'] : (is_string($error['message'] ?? null) ? $error['message'] : ''), $accessToken);

        if ($response->status() === 429 || MetaErrorCatalog::isThrottling($code)) {
            return new MetaGraphException(
                $this->compose(MetaErrorCatalog::messageFor($code) ?? "Meta limitó {$operation} por exceso de solicitudes. Se reintentará más tarde.", $code, $detail, $response->status(), $operation),
                rejected: false,
                metaCode: $code,
                status: $response->status(),
                kind: MetaGraphException::RATE_LIMITED,
            );
        }

        if ($response->serverError() || MetaErrorCatalog::isTemporary($code)) {
            // A gateway error without Meta's JSON error body may hide a request
            // that was processed; an explicit Graph error says it was not.
            $explicit = $error !== [];

            return new MetaGraphException(
                $this->compose("Meta no pudo atender {$operation} en este momento (límite de solicitudes o falla temporal). Inténtalo más tarde.", $code, $detail, $response->status(), $operation),
                rejected: false,
                metaCode: $code,
                status: $response->status(),
                kind: $explicit ? MetaGraphException::TRANSIENT : MetaGraphException::AMBIGUOUS,
            );
        }

        [$kind, $hint] = match (true) {
            in_array($code, [0, 190], true) || ($code === null && $response->status() === 401) => [MetaGraphException::TOKEN_INVALID, 'El token de acceso es inválido o expiró. Genera un nuevo token de usuario del sistema.'],
            in_array($code, [3, 10, 200, 131005], true) || ($code !== null && $code >= 200 && $code < 300) || ($code === null && $response->status() === 403) => [MetaGraphException::PERMISSION, 'El token no tiene los permisos necesarios (whatsapp_business_management y whatsapp_business_messaging) sobre este número. Genera un token nuevo en Meta con esos permisos y pégalo en la conexión.'],
            MetaErrorCatalog::isMessageError($code) => [MetaGraphException::MESSAGE_REJECTED, MetaErrorCatalog::explain($code, 'WhatsApp rechazó el mensaje.')],
            $code === 100 || $code === 803 || $response->status() === 404 => [MetaGraphException::INVALID_REQUEST, $operation === 'la verificación' ? 'El Phone number ID no existe o el token no tiene acceso a él.' : 'Meta rechazó la solicitud: un identificador no existe o un dato no es válido.'],
            default => [MetaGraphException::REJECTED, $operation === 'la verificación' ? 'Meta rechazó las credenciales.' : 'Meta rechazó la solicitud.'],
        };

        return new MetaGraphException($this->compose($hint, $code, $detail, $response->status(), $operation), rejected: true, metaCode: $code, status: $response->status(), kind: $kind);
    }

    /**
     * The message users read is the Spanish explanation only. Meta's code
     * stays on the exception (metaCode, stored as error_code meta_N by the
     * delivery job and in the audit entry) and Meta's raw English detail,
     * already sanitized, goes to the application log for support staff.
     */
    private function compose(string $hint, ?int $code, string $detail, int $status, string $operation): string
    {
        Log::warning('Meta Graph API request failed', [
            'operation' => $operation,
            'meta_code' => $code,
            'http_status' => $status,
            'detail' => $detail === '' ? null : $detail,
        ]);

        return Str::limit($hint, 480);
    }

    /**
     * Remove anything that could leak the token from a provider message.
     */
    public function sanitize(string $message, string $accessToken): string
    {
        if ($accessToken !== '') {
            $message = str_replace($accessToken, '[token]', $message);
        }

        $message = (string) preg_replace('/\bEA[A-Za-z0-9]{20,}\b/', '[token]', $message);
        $message = (string) preg_replace('/access_token=[^&\s]+/i', 'access_token=[token]', $message);

        return Str::limit(trim(strip_tags($message)), 300);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? Str::limit((string) $value, 190, '') : null;
    }
}
