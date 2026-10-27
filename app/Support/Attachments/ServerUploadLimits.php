<?php

namespace App\Support\Attachments;

use Illuminate\Http\UploadedFile;

/**
 * The upload limits of the PHP process serving this request
 * (upload_max_filesize and post_max_size), which may be lower than what a
 * channel accepts: PHP refuses a larger file before the application can
 * read it (UPLOAD_ERR_INI_SIZE) and empties the whole request when the body
 * exceeds post_max_size (PostTooLargeException). Neither is the user's
 * fault nor a rule of the channel, so they are explained as a server
 * configuration problem and published to the composers, which refuse such
 * a file before uploading it.
 *
 * The values are read from the running process (ini_get): the web server's
 * PHP (PHP-FPM, `php artisan serve`) may load another php.ini than the
 * command line. Tests may pass explicit values.
 */
final class ServerUploadLimits
{
    /** Room left in post_max_size for the multipart framing and the other fields. */
    public const MULTIPART_MARGIN_BYTES = 256 * 1024;

    public const SERVER_LIMIT = 'server_upload_limit';

    public const INTERRUPTED = 'upload_interrupted';

    public const SERVER_FAILURE = 'server_upload_failure';

    public const INVALID = 'upload_invalid';

    /** Causes that may go away by sending the same file again. */
    public const RETRYABLE = [self::INTERRUPTED, self::SERVER_FAILURE, self::INVALID];

    public function __construct(
        private ?string $uploadMaxFilesize = null,
        private ?string $postMaxSize = null,
    ) {}

    public function uploadMaxFilesize(): string
    {
        return trim($this->uploadMaxFilesize ?? (string) ini_get('upload_max_filesize'));
    }

    public function postMaxSize(): string
    {
        return trim($this->postMaxSize ?? (string) ini_get('post_max_size'));
    }

    /**
     * The largest single file PHP accepts (null: no limit): the smaller of
     * upload_max_filesize and what fits in post_max_size with the margin.
     */
    public function maxFileBytes(): ?int
    {
        $file = self::bytes($this->uploadMaxFilesize());
        $request = $this->maxRequestBytes();

        return match (true) {
            $file === null => $request,
            $request === null => $file,
            default => min($file, $request),
        };
    }

    /**
     * The largest total of files one request may carry (null: no limit).
     */
    public function maxRequestBytes(): ?int
    {
        $post = self::bytes($this->postMaxSize());

        return $post === null ? null : max(0, $post - self::MULTIPART_MARGIN_BYTES);
    }

    /**
     * For the composers: the effective limits and the configured values.
     *
     * @return array{max_file_bytes: int|null, max_request_bytes: int|null, upload_max_filesize: string, post_max_size: string, message: string}
     */
    public function describe(): array
    {
        return [
            'max_file_bytes' => $this->maxFileBytes(),
            'max_request_bytes' => $this->maxRequestBytes(),
            'upload_max_filesize' => $this->uploadMaxFilesize(),
            'post_max_size' => $this->postMaxSize(),
            'message' => $this->limitMessage(),
        ];
    }

    /**
     * Why PHP refused an uploaded file ({code, message}), or null when it
     * arrived complete. The codes are stable: server_upload_limit (the
     * server's configuration is lower than the file), upload_interrupted
     * (partial upload: the connection dropped), server_upload_failure
     * (no temporary directory, the file could not be written, an extension
     * stopped it: a temporary server problem) and upload_invalid
     * (UPLOAD_ERR_FORM_SIZE: the MAX_FILE_SIZE field is set by the client,
     * so it says nothing about this server's limits).
     *
     * @return array{code: string, message: string}|null
     */
    public function failure(UploadedFile $file, ?string $channelLabel = null): ?array
    {
        return match ($file->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE => ['code' => self::SERVER_LIMIT, 'message' => $this->limitMessage($channelLabel)],
            UPLOAD_ERR_FORM_SIZE => ['code' => self::INVALID, 'message' => 'El archivo no se recibió correctamente. Vuelve a enviarlo.'],
            UPLOAD_ERR_PARTIAL => ['code' => self::INTERRUPTED, 'message' => 'La subida del archivo se interrumpió, probablemente por la conexión. Revisa tu internet y vuelve a enviarlo.'],
            UPLOAD_ERR_NO_FILE => null,
            default => ['code' => self::SERVER_FAILURE, 'message' => 'El servidor no pudo guardar temporalmente el archivo (un problema temporal del servidor). Vuelve a enviarlo en unos minutos; si se repite, avisa a un administrador.'],
        };
    }

    /**
     * After the fact: PHP refused a file larger than what this server's
     * configuration accepts. Never presented as a rule of the channel. The
     * size is in the same decimal units as every size the team sees
     * (ChannelAttachmentRules::humanSize). Before uploading, the composers
     * say it in the present tense with the file's own size.
     */
    public function limitMessage(?string $channelLabel = null): string
    {
        $limit = $this->maxFileBytes();
        $accepts = $limit === null ? '' : ': acepta hasta '.ChannelAttachmentRules::humanSize($limit);
        $channel = $channelLabel !== null && $channelLabel !== '' ? ", menos de lo que permite {$channelLabel}" : '';

        return "El servidor de Chat Manager no pudo recibir el archivo{$accepts}{$channel}. Un administrador puede ampliar el límite.";
    }

    /**
     * A php.ini size ("2M", "100M", "1G", "0") in bytes; null when it means
     * "no limit" (zero, negative or empty).
     */
    public static function bytes(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $bytes = @ini_parse_quantity($value);

        return $bytes > 0 ? $bytes : null;
    }
}
