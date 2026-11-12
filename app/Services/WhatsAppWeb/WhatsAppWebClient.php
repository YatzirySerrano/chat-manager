<?php

namespace App\Services\WhatsAppWeb;

use App\Services\Meta\MediaRejected;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client of the private WhatsApp Web service (services/whatsapp-web), the
 * control API of docs/whatsapp-web-qr-contrato.md §4. Every request except
 * GET /health is signed (InternalSignature). It never reports a state it
 * did not receive: a call that cannot be made or answered throws a
 * WhatsAppWebException whose kind says what is known about the outcome.
 *
 * @phpstan-type SessionPayload array<string, mixed>
 */
class WhatsAppWebClient
{
    public function __construct(private InternalSignature $signature) {}

    /**
     * Whether the modality can be used at all (a shared secret is set).
     */
    public function isConfigured(): bool
    {
        return $this->signature->isConfigured();
    }

    /**
     * Whether the service process answers. It says nothing about sessions.
     */
    public function isAlive(): bool
    {
        try {
            return Http::baseUrl($this->baseUrl())
                ->connectTimeout((int) config('whatsapp_web.connect_timeout', 3))
                ->timeout(4)
                ->acceptJson()
                ->get('/health')
                ->json('status') === 'ok';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed> Service, version, library, uptime and every session.
     */
    public function status(): array
    {
        return $this->json('GET', '/v1/status');
    }

    /**
     * @return SessionPayload
     */
    public function session(int $connectionId): array
    {
        return $this->json('GET', "/v1/sessions/{$connectionId}");
    }

    /**
     * @param  'link'|'restore'  $mode
     * @return SessionPayload
     */
    public function start(int $connectionId, string $mode): array
    {
        return $this->json('POST', "/v1/sessions/{$connectionId}/start", ['mode' => $mode]);
    }

    /**
     * @return SessionPayload
     */
    public function stop(int $connectionId): array
    {
        return $this->json('POST', "/v1/sessions/{$connectionId}/stop");
    }

    /**
     * @return SessionPayload
     */
    public function logout(int $connectionId): array
    {
        return $this->json('POST', "/v1/sessions/{$connectionId}/logout");
    }

    public function delete(int $connectionId): void
    {
        $this->json('DELETE', "/v1/sessions/{$connectionId}");
    }

    /**
     * @param  array<string, mixed>  $message  OutboundMessage of the contract.
     * @return array{provider_message_id: string, timestamp: int|null}
     */
    public function sendMessage(int $connectionId, array $message): array
    {
        $answer = $this->json('POST', "/v1/sessions/{$connectionId}/messages", $message, ambiguous: true);
        $id = $answer['provider_message_id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new WhatsAppWebException('El servicio de WhatsApp Web respondió sin el identificador del mensaje; no es posible confirmar el envío.', WhatsAppWebException::AMBIGUOUS);
        }

        return ['provider_message_id' => $id, 'timestamp' => is_int($answer['timestamp'] ?? null) ? $answer['timestamp'] : null];
    }

    /**
     * Download and decrypt an inbound file into `$targetPath`, never
     * keeping it in memory and never past `$maxBytes`.
     *
     * @param  array<array-key, mixed>  $descriptor  Opaque descriptor received with the message.
     * @return array{mime: string|null, size: int}
     *
     * @throws MediaRejected When the file is larger than allowed.
     */
    public function downloadMedia(int $connectionId, array $descriptor, string $targetPath, int $maxBytes): array
    {
        $path = "/v1/sessions/{$connectionId}/media";
        $body = (string) json_encode(['descriptor' => $descriptor], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->request('POST', $path, $body, (int) config('whatsapp_web.media.download_timeout', 110))
                ->withOptions(['stream' => true])
                ->send('POST', $path);
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception, ambiguous: false);
        }

        if (! $response->successful()) {
            throw $this->failure($response, ambiguous: false);
        }

        $declared = $response->header('Content-Length');

        if (ctype_digit($declared) && (int) $declared > $maxBytes) {
            throw new MediaRejected('too_large', 'El archivo supera el tamaño permitido.');
        }

        $target = fopen($targetPath, 'wb');

        if ($target === false) {
            throw new WhatsAppWebException('No se pudo crear el archivo temporal de la descarga.', WhatsAppWebException::FAILED);
        }

        $stream = $response->toPsrResponse()->getBody();
        $size = 0;

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                $size += strlen($chunk);

                if ($size > $maxBytes) {
                    throw new MediaRejected('too_large', 'El archivo supera el tamaño permitido.');
                }

                fwrite($target, $chunk);
            }
        } catch (MediaRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WhatsAppWebException('La descarga del archivo desde el servicio de WhatsApp Web se interrumpió.', WhatsAppWebException::UNREACHABLE, previous: $exception);
        } finally {
            fclose($target);
            $stream->close();
        }

        if ($size === 0) {
            throw new WhatsAppWebException('El servicio de WhatsApp Web respondió un archivo vacío.', WhatsAppWebException::REJECTED, 'media_unavailable');
        }

        $mime = trim(explode(';', $response->header('Content-Type'))[0]);

        return ['mime' => $mime === '' ? null : $mime, 'size' => $size];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  bool  $ambiguous  Whether a timeout leaves the outcome unknown (a send) instead of "nothing happened".
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, ?array $payload = null, bool $ambiguous = false): array
    {
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->request($method, $path, $body, (int) config('whatsapp_web.timeout', 15))->send($method, $path);
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception, $ambiguous);
        }

        if (! $response->successful()) {
            throw $this->failure($response, $ambiguous);
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function request(string $method, string $path, string $body, int $timeout): PendingRequest
    {
        $headers = $this->signature->headers($method, $path, $body);

        if ($headers === null) {
            throw new WhatsAppWebException('WhatsApp Web (QR) no está configurado en este servidor: falta el secreto interno (WHATSAPP_WEB_SECRET).', WhatsAppWebException::DISABLED);
        }

        $request = Http::baseUrl($this->baseUrl())
            ->connectTimeout((int) config('whatsapp_web.connect_timeout', 3))
            ->timeout($timeout)
            ->acceptJson()
            ->withHeaders($headers);

        // The exact bytes that were signed are the ones sent.
        return $body === '' ? $request : $request->withBody($body, 'application/json');
    }

    private function baseUrl(): string
    {
        return (string) config('whatsapp_web.url');
    }

    private function failure(Response $response, bool $ambiguous): WhatsAppWebException
    {
        $status = $response->status();
        $error = $response->json('error');
        $error = is_string($error) ? $error : null;

        return match (true) {
            $status === 401 => new WhatsAppWebException('El servicio de WhatsApp Web rechazó la firma interna: WHATSAPP_WEB_SECRET (Laravel) y WAWEB_SECRET (servicio) deben ser iguales y los relojes deben coincidir.', WhatsAppWebException::FAILED, $error, $status),
            $status === 404 => new WhatsAppWebException('El servicio de WhatsApp Web no tiene una sesión de esta conexión: vincula el teléfono de nuevo.', WhatsAppWebException::UNKNOWN_SESSION, $error ?? 'unknown_session', $status),
            $status === 409 && $error === 'not_linked' => new WhatsAppWebException('Ningún teléfono está vinculado a esta conexión: genera un código QR y escanéalo.', WhatsAppWebException::NOT_LINKED, $error, $status),
            $status === 409 => new WhatsAppWebException('La sesión de WhatsApp Web no está conectada en este momento.', WhatsAppWebException::NOT_CONNECTED, $error ?? 'not_connected', $status),
            $status === 422 => new WhatsAppWebException(self::rejection($error), WhatsAppWebException::REJECTED, $error, $status),
            $status === 504 && $ambiguous => new WhatsAppWebException('WhatsApp no confirmó a tiempo si recibió el mensaje.', WhatsAppWebException::AMBIGUOUS, $error ?? 'send_timeout', $status),
            default => new WhatsAppWebException("El servicio de WhatsApp Web respondió un error interno (HTTP {$status}).", WhatsAppWebException::FAILED, $error, $status),
        };
    }

    private static function rejection(?string $error): string
    {
        return match ($error) {
            'invalid_recipient' => 'WhatsApp no reconoce el número del destinatario.',
            'invalid_request' => 'El servicio de WhatsApp Web no aceptó el mensaje tal como se envió.',
            'unsupported_type' => 'Este tipo de mensaje no se puede enviar por WhatsApp Web.',
            'media_too_large' => 'El archivo es demasiado grande para enviarlo por WhatsApp Web.',
            'media_unavailable' => 'El archivo ya no está disponible.',
            default => 'El servicio de WhatsApp Web rechazó la solicitud.',
        };
    }

    /**
     * A request that got no answer. A refused or unresolved connection
     * means nothing left this server; a timeout after connecting leaves a
     * send in doubt.
     */
    private function connectionFailure(ConnectionException $exception, bool $ambiguous): WhatsAppWebException
    {
        $previous = $exception->getPrevious();
        $context = $previous instanceof ConnectException ? $previous->getHandlerContext() : [];
        $errno = is_int($context['errno'] ?? null) ? $context['errno'] : null;
        $connected = is_numeric($context['connect_time'] ?? null) && (float) $context['connect_time'] > 0;
        $neverConnected = in_array($errno, [6, 7], true) || ($errno === 28 && ! $connected);

        if ($ambiguous && ! $neverConnected) {
            return new WhatsAppWebException('El servicio de WhatsApp Web no respondió a tiempo; no se sabe si el mensaje salió.', WhatsAppWebException::AMBIGUOUS, previous: $exception);
        }

        return new WhatsAppWebException('El servicio de WhatsApp Web no responde en este servidor. Un administrador debe revisar que esté en ejecución.', WhatsAppWebException::UNREACHABLE, previous: $exception);
    }
}
