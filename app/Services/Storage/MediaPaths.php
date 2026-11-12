<?php

namespace App\Services\Storage;

use App\Enums\ConnectionChannel;
use App\Enums\MessageDirection;
use App\Models\Contact;
use App\Models\Message;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Where a file lives on the media disk, in words a person understands when
 * browsing the NAS:
 *
 *   WhatsApp/Juan Perez (+52 55 1234 5678)/2026-11 Noviembre/2026-11-12 14.30.15 Foto recibida.jpg
 *   Messenger/Maria Lopez (Messenger)/2026-11 Noviembre/2026-11-12 14.31.02 Documento enviado - contrato.pdf
 *   Chat interno/Ana Lopez y Beto Ruiz/2026-11 Noviembre/2026-11-12 09.05.40 Video de Ana Lopez.mp4
 *
 * Rules:
 * - No internal ids, UUIDs, hashes or random suffixes anywhere. Two files of
 *   the same second get a readable counter, " (2)" (MediaDisk::putNamedFile).
 * - The folder of a contact (and of an internal chat) is assigned once and
 *   stored in its `media_folder` column: renaming the contact later does not
 *   move or split its files. The column is unique, so two people never share
 *   a folder; a clash gets a readable order number ("Juan Perez 2 (Messenger)").
 * - Every component is reduced to plain ASCII letters, digits, spaces and a
 *   few safe signs, so it is valid on CIFS/SMB and Windows, cannot leave the
 *   media root ("..", slashes) and is never empty or a reserved device name.
 * - Dates and times are written in the platform's time zone.
 *
 * Files stored before this scheme keep their old path ("media/…",
 * "team-chat/…"): the path is saved per attachment, so they keep working.
 * `php artisan storage:reorganize-media` moves them to the readable layout.
 */
class MediaPaths
{
    /** Previews made by the platform: never next to the originals. */
    public const THUMBNAILS = 'Miniaturas del sistema';

    public const TEAM_CHAT = 'Chat interno';

    /** Directories of the layout used before the readable one. */
    public const LEGACY_PREFIXES = ['media/', 'team-chat/'];

    private const MONTHS = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /** Device names Windows (and therefore an SMB share) refuses as a file or folder. */
    private const RESERVED = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];

    /**
     * Extension of each detected type (never the one a client claims).
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/heic' => 'heic', 'image/heif' => 'heif', 'image/bmp' => 'bmp', 'image/tiff' => 'tif',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/3gpp' => '3gp', 'video/webm' => 'webm',
        'video/ogg' => 'ogv', 'video/x-msvideo' => 'avi', 'video/x-m4v' => 'm4v', 'video/x-matroska' => 'mkv',
        'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
        'audio/aac' => 'aac', 'audio/amr' => 'amr', 'audio/wav' => 'wav', 'audio/webm' => 'weba', 'audio/3gpp' => '3gp',
        'application/pdf' => 'pdf', 'text/csv' => 'csv', 'application/json' => 'json', 'application/rtf' => 'rtf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/x-rar' => 'rar', 'application/vnd.rar' => 'rar', 'application/x-7z-compressed' => '7z',
        'application/gzip' => 'gz',
    ];

    /**
     * Generic detections: the content does not tell the exact format, so the
     * extension of the original name is kept when it is one of these.
     *
     * @var array<string, list<string>>
     */
    private const GENERIC = [
        'application/zip' => ['zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'],
        'application/vnd.ms-office' => ['doc', 'xls', 'ppt', 'msg'],
        'application/octet-stream' => ['docx', 'xlsx', 'pptx', 'doc', 'xls', 'ppt', 'zip', 'rar', '7z', 'csv', 'txt', 'xml'],
        'text/plain' => ['txt', 'csv', 'log', 'md', 'vcf'],
        'text/xml' => ['xml'],
        'application/xml' => ['xml'],
    ];

    private const GENERIC_DEFAULTS = [
        'application/zip' => 'zip', 'application/vnd.ms-office' => 'doc', 'application/octet-stream' => 'bin',
        'text/plain' => 'txt', 'text/xml' => 'xml', 'application/xml' => 'xml',
    ];

    /**
     * Folders proposed while previewing (nothing is stored): record key => folder.
     *
     * @var array<string, string>
     */
    private array $previews = [];

    public function __construct(private PlatformSettings $settings) {}

    /**
     * Whether a stored path belongs to the layout used before this one.
     */
    public static function isLegacy(?string $path): bool
    {
        return $path !== null && Str::startsWith($path, self::LEGACY_PREFIXES);
    }

    /**
     * One path component safe for CIFS/SMB, Windows and Linux: plain ASCII
     * letters, digits, spaces and . - , ' ( ) & +; no separators, control
     * characters, trailing dots or spaces, or reserved device names. May
     * return an empty string: callers supply their own fallback.
     */
    public static function clean(?string $value, int $maxLength = 80): string
    {
        $value = Str::ascii((string) $value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        $value = (string) preg_replace("/[^A-Za-z0-9 .,'()&+-]+/", ' ', $value);
        $value = (string) preg_replace('/\.{2,}/', '.', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value), " .\t");

        if (strlen($value) > $maxLength) {
            $value = trim(substr($value, 0, $maxLength), ' .');
        }

        if (in_array(strtoupper((string) strtok($value, '.')), self::RESERVED, true)) {
            $value = 'Archivo '.$value;
        }

        return $value;
    }

    public function channelFolder(?ConnectionChannel $channel): string
    {
        return match ($channel) {
            ConnectionChannel::WebChat => 'Chat web',
            ConnectionChannel::Messenger => 'Messenger',
            ConnectionChannel::Instagram => 'Instagram',
            default => 'WhatsApp',
        };
    }

    /**
     * "2026-11 Noviembre", in the platform's time zone.
     */
    public function monthFolder(CarbonInterface $at): string
    {
        $local = $this->local($at);

        return $local->format('Y-m').' '.self::MONTHS[(int) $local->format('n')];
    }

    /**
     * Folder of the contact, assigned and stored the first time it is asked
     * for: "Juan Perez (+52 55 1234 5678)"; only the phone when there is no
     * name; "Maria Lopez (Messenger)" when there is no phone.
     */
    public function contactFolder(Contact $contact, ?ConnectionChannel $channel = null, bool $assign = true): string
    {
        $existing = $contact->getAttribute('media_folder');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $phone = self::clean(PhoneNumber::format($contact->phone_e164), 24);
        $name = self::clean($this->personName($contact->name) ?? $this->personName($contact->profile_name), 52);
        $channelLabel = $this->channelFolder($channel);

        // A "name" that is only the phone number adds nothing.
        if ($name !== '' && preg_replace('/\D+/', '', $name) === preg_replace('/\D+/', '', $phone) && $phone !== '') {
            $name = '';
        }

        $candidate = function (int $order) use ($name, $phone, $channelLabel): string {
            $counter = $order === 1 ? '' : ' '.$order;

            return match (true) {
                $name !== '' && $phone !== '' => "{$name}{$counter} ({$phone})",
                $name !== '' => "{$name}{$counter} ({$channelLabel})",
                $phone !== '' => $order === 1 ? $phone : "{$phone} ({$order})",
                default => "Contacto sin nombre{$counter} ({$channelLabel})",
            };
        };

        if (! $assign) {
            return $this->preview('contact:'.$contact->getKey(), $candidate, fn (string $folder): bool => Contact::query()->where('media_folder', $folder)->toBase()->exists());
        }

        return $this->assign(
            $candidate,
            fn (string $folder): int => Contact::query()->whereKey($contact->getKey())->whereNull('media_folder')->toBase()->update(['media_folder' => $folder]),
            fn (): ?string => Contact::query()->whereKey($contact->getKey())->toBase()->value('media_folder'),
            fn (string $folder) => $contact->setAttribute('media_folder', $folder)->syncOriginalAttribute('media_folder'),
        );
    }

    /**
     * Folder of an internal chat: the name of the group, or the two people
     * of a private chat ("Ana Lopez y Beto Ruiz").
     */
    public function threadFolder(TeamThread $thread, bool $assign = true): string
    {
        $existing = $thread->getAttribute('media_folder');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        if ($thread->isGroup()) {
            $base = self::clean($thread->name, 70);
            $base = $base !== '' ? $base : 'Grupo sin nombre';
        } else {
            $names = $thread->participations()->with('user:id,name')->orderBy('user_id')->get()
                ->map(fn ($participation): string => self::clean($participation->user?->name, 34))
                ->filter()->unique()->values()->all();
            $base = $names === [] ? 'Chat privado' : implode(' y ', array_slice($names, 0, 2));
        }

        if (! $assign) {
            return $this->preview('thread:'.$thread->getKey(), fn (int $order): string => $order === 1 ? $base : "{$base} {$order}", fn (string $folder): bool => TeamThread::query()->where('media_folder', $folder)->toBase()->exists());
        }

        return $this->assign(
            fn (int $order): string => $order === 1 ? $base : "{$base} {$order}",
            fn (string $folder): int => TeamThread::query()->whereKey($thread->getKey())->whereNull('media_folder')->toBase()->update(['media_folder' => $folder]),
            fn (): ?string => TeamThread::query()->whereKey($thread->getKey())->toBase()->value('media_folder'),
            fn (string $folder) => $thread->setAttribute('media_folder', $folder)->syncOriginalAttribute('media_folder'),
        );
    }

    /**
     * Directory and file name (without counter) of a conversation file.
     *
     * @return array{directory: string, stem: string, extension: string}
     */
    public function conversationFile(Contact $contact, ?ConnectionChannel $channel, MessageDirection $direction, string $kind, string $mime, CarbonInterface $at, ?string $originalName = null, bool $voice = false, bool $assign = true): array
    {
        [$label, $feminine] = $this->kindLabel($kind, $mime, $voice);
        $received = $direction === MessageDirection::Inbound;
        $action = $received ? ($feminine ? 'recibida' : 'recibido') : ($feminine ? 'enviada' : 'enviado');

        return [
            'directory' => $this->channelFolder($channel).'/'.$this->contactFolder($contact, $channel, $assign).'/'.$this->monthFolder($at),
            'stem' => $this->stamp($at).' '.$label.' '.$action.$this->originalSuffix($kind, $mime, $originalName),
            'extension' => $this->extensionFor($mime, $originalName),
        ];
    }

    /**
     * Placement of the file of a stored message (inbound downloads, the
     * reorganization of older files).
     *
     * @return array{directory: string, stem: string, extension: string}|null Null when the message has no contact to file it under.
     */
    public function forMessage(Message $message, string $mime, ?string $originalName = null, ?string $kind = null, bool $assign = true): ?array
    {
        // Explicit queries: this runs inside jobs and commands where the
        // relations are not loaded (and lazy loading is refused outside production).
        $conversation = $message->conversation()->first();
        $contact = $conversation?->contact()->first();

        if ($conversation === null || $contact === null) {
            return null;
        }

        $voice = data_get($message->metadata, 'voice') === true
            || data_get($message->metadata, 'audio.voice') === true
            || data_get($message->metadata, 'content.voice') === true;

        return $this->conversationFile(
            $contact,
            $conversation->channelConnection()->first()?->channel,
            $message->direction,
            $kind ?? $message->type->value,
            $mime,
            $message->sent_at ?? $message->created_at ?? CarbonImmutable::now(),
            $originalName,
            $voice,
            $assign,
        );
    }

    /**
     * Directory and file name (without counter) of an internal chat file:
     * "2026-11-12 09.05.40 Video de Ana Lopez.mp4".
     *
     * @return array{directory: string, stem: string, extension: string}
     */
    public function teamFile(TeamThread $thread, ?User $author, string $kind, string $mime, CarbonInterface $at, ?string $originalName = null, bool $assign = true): array
    {
        [$label] = $this->kindLabel($kind, $mime, false);
        $authorName = self::clean($author?->name, 34);

        return [
            'directory' => self::TEAM_CHAT.'/'.$this->threadFolder($thread, $assign).'/'.$this->monthFolder($at),
            'stem' => $this->stamp($at).' '.$label.($authorName !== '' ? ' de '.$authorName : '').$this->originalSuffix($kind, $mime, $originalName),
            'extension' => $this->extensionFor($mime, $originalName),
        ];
    }

    /**
     * Extension of the real content. Only when the detection is generic
     * (a ZIP container, plain text…) the extension of the original name is
     * used, and only from a short list consistent with that detection.
     */
    public function extensionFor(string $mime, ?string $originalName = null): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        if (isset(self::EXTENSIONS[$mime])) {
            return self::EXTENSIONS[$mime];
        }

        $claimed = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));

        if (isset(self::GENERIC[$mime])) {
            return in_array($claimed, self::GENERIC[$mime], true) ? $claimed : self::GENERIC_DEFAULTS[$mime];
        }

        $subtype = (string) preg_replace('/[^a-z0-9]+/', '', (string) Str::after($mime, '/'));

        return $subtype !== '' && strlen($subtype) <= 5 ? $subtype : 'bin';
    }

    /**
     * Where the preview of a stored file is kept: a mirror of its path under
     * the system folder, always JPEG.
     */
    public static function thumbnailFor(string $path): string
    {
        return self::THUMBNAILS.'/'.ltrim($path, '/').'.jpg';
    }

    /**
     * Word for the kind of file and whether it is feminine in Spanish.
     *
     * @return array{string, bool}
     */
    private function kindLabel(string $kind, string $mime, bool $voice): array
    {
        $family = strtolower((string) Str::before($mime, '/'));

        return match (true) {
            $kind === 'sticker' => ['Sticker', false],
            $kind === 'audio' && $voice => ['Nota de voz', true],
            $kind === 'image' || ($kind !== 'document' && $family === 'image') => ['Foto', true],
            $kind === 'video' || ($kind !== 'document' && $family === 'video') => ['Video', false],
            $kind === 'audio' || ($kind !== 'document' && $family === 'audio') => ['Audio', false],
            default => ['Documento', false],
        };
    }

    /**
     * " - contrato" for documents that came with a meaningful name; photos,
     * videos and audios carry camera or generated names that say nothing.
     */
    private function originalSuffix(string $kind, string $mime, ?string $originalName): string
    {
        [$label] = $this->kindLabel($kind, $mime, false);

        if ($label !== 'Documento') {
            return '';
        }

        $stem = self::clean(pathinfo(basename(str_replace('\\', '/', (string) $originalName)), PATHINFO_FILENAME), 60);

        return $stem === '' ? '' : ' - '.$stem;
    }

    /**
     * "2026-11-12 14.30.15" (dots: a colon is not valid in a file name).
     */
    private function stamp(CarbonInterface $at): string
    {
        return $this->local($at)->format('Y-m-d H.i.s');
    }

    private function local(CarbonInterface $at): CarbonImmutable
    {
        $timezone = (string) $this->settings->get('general.timezone', config('app.timezone'));

        return CarbonImmutable::instance($at)->setTimezone($timezone !== '' ? $timezone : 'UTC');
    }

    private function personName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }

    /**
     * The folder the record would get, without storing anything (the
     * simulation of storage:reorganize-media). Consistent within the run.
     *
     * @param  callable(int): string  $candidate
     * @param  callable(string): bool  $taken
     */
    private function preview(string $key, callable $candidate, callable $taken): string
    {
        if (isset($this->previews[$key])) {
            return $this->previews[$key];
        }

        $proposed = array_map('mb_strtolower', $this->previews);

        for ($order = 1; $order <= 200; $order++) {
            $folder = $candidate($order);

            if (! in_array(mb_strtolower($folder), $proposed, true) && ! $taken($folder)) {
                return $this->previews[$key] = $folder;
            }
        }

        return $this->previews[$key] = $candidate(1);
    }

    /**
     * Stores the first free candidate in the unique column; a concurrent
     * request that assigned one first wins and its folder is returned.
     *
     * @param  callable(int): string  $candidate
     * @param  callable(string): int  $claim  Rows updated (0 when the record already has a folder).
     * @param  callable(): (string|null)  $current
     * @param  callable(string): mixed  $remember
     */
    private function assign(callable $candidate, callable $claim, callable $current, callable $remember): string
    {
        for ($order = 1; $order <= 200; $order++) {
            $folder = $candidate($order);

            try {
                if ($claim($folder) === 0) {
                    $folder = (string) $current();
                }
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            if ($folder !== '') {
                $remember($folder);

                return $folder;
            }
        }

        throw new \RuntimeException('No free media folder name could be assigned.');
    }
}
