<?php

namespace App\Services\Telegram;

use App\Services\Meta\MediaRejected;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Minimal client of the Telegram Bot API (https://core.telegram.org/bots/api)
 * for one bot token at a time: https://api.telegram.org/bot<token>/<method>.
 *
 * - The token is part of every URL, so it never leaves this class: failures
 *   become a TelegramException with a Spanish, token-free message, and the
 *   transport's own exception (whose text contains the URL) is dropped.
 * - Files are downloaded server-side from /file/bot<token>/<file_path>
 *   (never exposed to the browser) with a hard size cap while streaming.
 * - Uploads are multipart (sendPhoto, sendDocument…) from a local stream.
 */
class TelegramBotClient
{
    /** Largest file the Bot API lets a bot download with getFile. */
    public const DOWNLOAD_MAX_BYTES = 20 * 1024 * 1024;

    /**
     * Bot identity (getMe).
     *
     * @return array{id: string, username: string|null, first_name: string}
     *
     * @throws TelegramException
     */
    public function getMe(string $token): array
    {
        $me = $this->call($token, 'getMe');

        if (! is_array($me) || ! is_numeric($me['id'] ?? null) || ($me['is_bot'] ?? false) !== true) {
            throw new TelegramException('Telegram no devolvió los datos de un bot con este token.', TelegramException::REJECTED);
        }

        return [
            'id' => (string) $me['id'],
            'username' => is_string($me['username'] ?? null) ? $me['username'] : null,
            'first_name' => is_string($me['first_name'] ?? null) ? $me['first_name'] : 'Bot de Telegram',
        ];
    }

    /**
     * @param  list<string>  $allowedUpdates
     *
     * @throws TelegramException
     */
    public function setWebhook(string $token, string $url, string $secret, array $allowedUpdates): void
    {
        $this->call($token, 'setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => $allowedUpdates,
            'max_connections' => 40,
        ]);
    }

    /**
     * @throws TelegramException
     */
    public function deleteWebhook(string $token): void
    {
        $this->call($token, 'deleteWebhook', ['drop_pending_updates' => false]);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws TelegramException
     */
    public function getWebhookInfo(string $token): array
    {
        $info = $this->call($token, 'getWebhookInfo');

        return is_array($info) ? $info : [];
    }

    /**
     * Path and size of a file the bot received (valid for at least an hour).
     *
     * @return array{file_path: string, file_size: int|null}
     *
     * @throws TelegramException
     */
    public function getFile(string $token, string $fileId): array
    {
        $file = $this->call($token, 'getFile', ['file_id' => $fileId]);

        if (! is_array($file) || ! is_string($file['file_path'] ?? null) || $file['file_path'] === '') {
            throw new TelegramException('Telegram ya no ofrece este archivo.', TelegramException::REJECTED);
        }

        return ['file_path' => $file['file_path'], 'file_size' => is_int($file['file_size'] ?? null) ? $file['file_size'] : null];
    }

    /**
     * Downloads a file (getFile path) into the local destination, refusing
     * anything larger than $maxBytes while it streams.
     *
     * @throws TelegramException
     * @throws MediaRejected when the file is over the limit
     */
    public function download(string $token, string $filePath, string $destination, int $maxBytes): void
    {
        if (str_contains($filePath, '..') || preg_match('#^[A-Za-z0-9_./-]+$#', $filePath) !== 1) {
            throw new TelegramException('La ruta del archivo de Telegram no es válida.', TelegramException::REJECTED);
        }

        $exceeded = false;
        $sink = $this->cappedSink($destination, $maxBytes, $exceeded);

        try {
            $response = $this->request()->withOptions(['sink' => $sink])->get($this->base()."/file/bot{$token}/{$filePath}");
        } catch (ConnectionException $exception) {
            if ($exceeded) {
                throw self::tooLarge($maxBytes);
            }

            throw self::connectionFailure($exception);
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        if ($exceeded) {
            throw self::tooLarge($maxBytes);
        }

        if (! $response->successful()) {
            throw self::failure($response);
        }
    }

    /**
     * @param  array<string, mixed>|null  $replyParameters
     * @return array<array-key, mixed> The sent Message.
     *
     * @throws TelegramException
     */
    public function sendMessage(string $token, string $chatId, string $text, ?array $replyParameters = null): array
    {
        $sent = $this->call($token, 'sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_parameters' => $replyParameters,
        ], fn (mixed $value): bool => $value !== null));

        return is_array($sent) ? $sent : [];
    }

    /**
     * Uploads a file with sendPhoto, sendVideo, sendAudio, sendVoice,
     * sendDocument or sendSticker; $field is the method's file field.
     *
     * @param  resource  $stream
     * @param  array<string, mixed>|null  $replyParameters
     * @return array<array-key, mixed> The sent Message.
     *
     * @throws TelegramException
     */
    public function sendFile(string $token, string $method, string $field, string $chatId, $stream, string $fileName, ?string $caption = null, ?array $replyParameters = null): array
    {
        $fields = array_filter([
            'chat_id' => $chatId,
            'caption' => $caption,
            'reply_parameters' => $replyParameters === null ? null : (string) json_encode($replyParameters),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $sent = $this->send(fn (PendingRequest $request): Response => $request
            ->timeout(120)
            ->attach($field, $stream, $fileName)
            ->post($this->base()."/bot{$token}/{$method}", $fields));

        return is_array($sent) ? $sent : [];
    }

    /**
     * POST /bot<token>/<method> with a JSON body; returns its "result".
     *
     * @param  array<string, mixed>  $params
     *
     * @throws TelegramException
     */
    public function call(string $token, string $method, array $params = []): mixed
    {
        return $this->send(fn (PendingRequest $request): Response => $request->asJson()->post($this->base()."/bot{$token}/{$method}", $params));
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws TelegramException
     */
    private function send(callable $request): mixed
    {
        try {
            $response = $request($this->request());
        } catch (ConnectionException $exception) {
            throw self::connectionFailure($exception);
        }

        $json = $response->json();

        if ($response->successful() && is_array($json) && ($json['ok'] ?? false) === true) {
            return $json['result'] ?? null;
        }

        throw self::failure($response);
    }

    private function request(): PendingRequest
    {
        $timeout = max(1, (int) config('services.telegram.timeout', 15));

        return Http::timeout($timeout)->connectTimeout(min(5, $timeout))->acceptJson();
    }

    private function base(): string
    {
        return rtrim((string) (config('services.telegram.base_url') ?: 'https://api.telegram.org'), '/');
    }

    /**
     * cURL tells whether the connection was ever established: DNS errors,
     * refused connections, TLS failures and connect timeouts prove the
     * request never left. Anything else (a response timeout) may have
     * reached Telegram. The exception's text holds the URL with the token:
     * it is never kept.
     */
    private static function connectionFailure(ConnectionException $exception): TelegramException
    {
        $detail = $exception->getMessage();
        $neverSent = preg_match('/cURL error (6|7|35|60):/', $detail) === 1
            || preg_match('/cURL error 28: (Connection|Resolving) timed out/i', $detail) === 1;

        return $neverSent
            ? new TelegramException('No fue posible comunicarse con Telegram.', TelegramException::UNREACHABLE)
            : new TelegramException('Telegram no respondió a tiempo; no se sabe si recibió la solicitud.', TelegramException::AMBIGUOUS);
    }

    /**
     * Telegram's error answer ({ok: false, error_code, description,
     * parameters.retry_after}) in the terms the callers act on.
     */
    private static function failure(Response $response): TelegramException
    {
        $json = $response->json();
        $status = $response->status();
        $description = is_array($json) && is_string($json['description'] ?? null) ? Str::limit(strip_tags($json['description']), 200, '') : null;
        $hasBody = is_array($json) && array_key_exists('ok', $json);
        $lower = Str::lower((string) $description);

        return match (true) {
            $status === 401, $status === 404 => new TelegramException('Telegram rechazó el token del bot (revocado o incorrecto).', TelegramException::UNAUTHORIZED, $status),
            $status === 403 => new TelegramException('El cliente bloqueó al bot o eliminó su cuenta de Telegram.', TelegramException::FORBIDDEN, $status),
            $status === 429 => new TelegramException('Telegram limitó los envíos por un momento.', TelegramException::RATE_LIMITED, $status, self::retryAfter($json)),
            $status === 400 && str_contains($lower, 'chat not found') => new TelegramException('Telegram no encuentra el chat: el cliente debe escribir primero al bot.', TelegramException::CHAT_NOT_FOUND, $status),
            $status === 400 && str_contains($lower, 'file is too big') => new TelegramException('El archivo supera los 20 MB que Telegram permite descargar a un bot.', TelegramException::FILE_TOO_BIG, $status),
            $status >= 500 && $hasBody => new TelegramException('Telegram tuvo una falla temporal.', TelegramException::TRANSIENT, $status),
            $status >= 500 || ! $hasBody => new TelegramException('Telegram no dio una respuesta válida; no se sabe si recibió la solicitud.', TelegramException::AMBIGUOUS, $status),
            default => new TelegramException('Telegram rechazó la solicitud'.($description !== null ? ": {$description}" : '.'), TelegramException::REJECTED, $status),
        };
    }

    private static function retryAfter(mixed $json): ?int
    {
        $value = is_array($json) && is_array($json['parameters'] ?? null) ? ($json['parameters']['retry_after'] ?? null) : null;

        return is_int($value) && $value > 0 ? $value : null;
    }

    private static function tooLarge(int $maxBytes): MediaRejected
    {
        $megabytes = rtrim(rtrim(number_format($maxBytes / 1048576, 1), '0'), '.');

        return new MediaRejected('too_large', "El archivo supera el tamaño permitido ({$megabytes} MB).");
    }

    /**
     * A writable stream on the destination file that refuses (writes 0
     * bytes, which makes cURL abort) any chunk taking it over the limit.
     *
     * @return resource
     */
    private function cappedSink(string $destination, int $maxBytes, bool &$exceeded)
    {
        $file = new LazyOpenStream($destination, 'w+');
        $written = 0;

        try {
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
        } catch (Throwable $exception) {
            throw new \RuntimeException('No temporary stream could be opened for the Telegram download.', 0, $exception);
        }
    }
}
