<?php

namespace App\Jobs;

use App\Enums\ConnectionChannel;
use App\Enums\WhatsAppWebState;
use App\Events\Conversations\ConversationChanged;
use App\Exceptions\StorageUnavailable;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaPaths;
use App\Services\WhatsAppWeb\WhatsAppWebClient;
use App\Services\WhatsAppWeb\WhatsAppWebContactProfiles;
use App\Services\WhatsAppWeb\WhatsAppWebException;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads what WhatsApp shares about a contact through a WhatsApp Web (QR)
 * session (docs/whatsapp-web-qr-contrato.md §4, POST …/profile) and keeps
 * it on the contact's WhatsApp identity:
 *
 * - the profile picture is downloaded here, on the server, and stored on
 *   the private media disk (MediaDisk, MediaPaths): the browser never loads
 *   WhatsApp's CDN, whose URLs expire and would reveal the viewer's IP. Only
 *   an HTTPS URL of WhatsApp's own CDN hosts is fetched, without following
 *   redirects, and only a JPEG, PNG or WebP image under MAX_BYTES is kept;
 *   an unchanged picture (same SHA-256) is not stored again and the previous
 *   file is deleted once the new one is recorded;
 * - a picture the contact made private (or removed) removes the stored copy;
 * - the "about" with its date and the business profile are stored as given.
 *
 * Nothing is retried in a loop: a session that is not connected waits for
 * the next message; a service that does not answer is retried twice.
 */
class RefreshWhatsAppWebContactProfile implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Largest picture kept (WhatsApp serves them at 640 px, well under this). */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @var list<string> */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $identityId, public int $connectionId)
    {
        $this->onQueue(Queues::name(Queues::MEDIA));
    }

    public function uniqueId(): string
    {
        return (string) $this->identityId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Whether a URL may be fetched: HTTPS on WhatsApp's own CDN hosts only.
     */
    public static function allowedPictureUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return preg_match('/^([a-z0-9-]+\.)+(whatsapp\.net|fbcdn\.net)$/', $host) === 1;
    }

    public function handle(WhatsAppWebClient $client, MediaDisk $mediaDisk, MediaPaths $paths): void
    {
        $identity = ContactIdentity::query()->with('contact')->find($this->identityId);
        $connection = Connection::query()->find($this->connectionId);

        if ($identity === null || $identity->channel !== ConnectionChannel::Whatsapp || $identity->contact === null
            || $connection === null || ! $connection->isWhatsAppWeb() || $connection->isDeleted()
            || $connection->webSessionState() !== WhatsAppWebState::Connected) {
            return;
        }

        $recipient = WhatsAppWebContactProfiles::recipient($identity);

        if ($recipient === null) {
            return;
        }

        try {
            $profile = $client->contactProfile($connection->id, $recipient);
        } catch (WhatsAppWebException $exception) {
            if ($exception->kind === WhatsAppWebException::REJECTED) {
                // WhatsApp does not know this number: not asked again for a day.
                ContactIdentity::query()->whereKey($identity->id)->update(['profile_fetched_at' => now()]);

                return;
            }

            if (in_array($exception->kind, [WhatsAppWebException::UNREACHABLE, WhatsAppWebException::FAILED], true) && $this->attempts() < $this->tries && $this->job !== null) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 300);

                return;
            }

            // Not connected, unknown session, disabled: the next message tries again.
            throw_if($this->job === null, $exception);

            return;
        }

        $fetchedAt = CarbonImmutable::now();
        // "keep": the stored copy stays; "remove": the contact has no public picture; or the new file.
        $picture = 'keep';

        if ($profile['picture_url'] === null) {
            $picture = 'remove';
        } elseif ($mediaDisk->ready()) {
            $picture = $this->storePicture($profile['picture_url'], $identity, $mediaDisk, $paths, $fetchedAt);
        }

        $previous = null;

        try {
            $changed = DB::transaction(function () use ($identity, $profile, $picture, $fetchedAt, &$previous): bool {
                $current = ContactIdentity::query()->lockForUpdate()->find($identity->id);

                if ($current === null) {
                    return false;
                }

                $before = [$current->avatar_hash, $current->profile_about, $current->profile_about_set_at?->getTimestamp(), $current->business_profile];

                $current->forceFill([
                    'profile_about' => $profile['about'],
                    'profile_about_set_at' => $profile['about_set_at'] === null ? null : CarbonImmutable::createFromTimestamp($profile['about_set_at']),
                    'business_profile' => $profile['business'],
                    'profile_fetched_at' => $fetchedAt,
                ]);

                if ($picture === 'remove' || is_array($picture)) {
                    if ($current->hasAvatar()) {
                        $previous = ['disk' => $current->avatar_disk, 'path' => $current->avatar_path];
                    }

                    $current->forceFill(is_array($picture)
                        ? ['avatar_disk' => $picture['disk'], 'avatar_path' => $picture['path'], 'avatar_hash' => $picture['hash']]
                        : ['avatar_disk' => null, 'avatar_path' => null, 'avatar_hash' => null]);
                }

                // Not an edit of the identity by a person: its timestamps stay.
                $current->timestamps = false;
                $current->save();

                return $before !== [$current->avatar_hash, $current->profile_about, $current->profile_about_set_at?->getTimestamp(), $current->business_profile];
            });
        } catch (Throwable $exception) {
            if (is_array($picture)) {
                $mediaDisk->discard($picture['disk'], $picture['path']);
            }

            throw $exception;
        }

        if ($previous !== null && (! is_array($picture) || $previous['path'] !== $picture['path'])) {
            $mediaDisk->discard($previous['disk'], $previous['path']);
        }

        if ($changed) {
            // The panels of its open conversations show the new picture and info at once.
            Conversation::query()
                ->where('contact_id', $identity->contact_id)
                ->notClosed()
                ->pluck('id')
                ->each(fn (mixed $id) => ConversationChanged::dispatch((int) $id, 'contact'));
        }
    }

    /**
     * Downloads and validates the picture, then stores it. "keep" when the
     * picture is the one already stored or could not be fetched.
     *
     * @return 'keep'|array{disk: string, path: string, hash: string}
     */
    private function storePicture(string $url, ContactIdentity $identity, MediaDisk $mediaDisk, MediaPaths $paths, CarbonImmutable $at): string|array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'wweb-avatar-');

        if ($temporary === false) {
            return 'keep';
        }

        try {
            $mime = $this->download($url, $temporary);

            if ($mime === null) {
                return 'keep';
            }

            $hash = (string) hash_file('sha256', $temporary);

            if ($identity->hasAvatar() && hash_equals((string) $identity->avatar_hash, $hash)) {
                return 'keep';
            }

            $file = $paths->contactAvatarFile($identity->contact, $mime, $at);
            $stored = $mediaDisk->putNamedFile($temporary, $file['directory'], $file['stem'], $file['extension']);

            return [...$stored, 'hash' => $hash];
        } catch (StorageUnavailable $exception) {
            $exception->report();

            return 'keep';
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Streams the picture into `$target`, never past MAX_BYTES. The type of
     * the content itself is checked (never the one the server claims).
     *
     * @return string|null The detected type, or null when it is not an acceptable image.
     */
    private function download(string $url, string $target): ?string
    {
        if (! self::allowedPictureUrl($url)) {
            Log::warning('WhatsApp Web profile picture refused: not a WhatsApp CDN URL.', ['identity_id' => $this->identityId]);

            return null;
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(15)
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->withHeaders(['Accept' => 'image/jpeg, image/png, image/webp'])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if ($response->status() !== 200) {
            return null;
        }

        $declared = $response->header('Content-Length');

        if (ctype_digit($declared) && (int) $declared > self::MAX_BYTES) {
            return null;
        }

        $output = fopen($target, 'wb');

        if ($output === false) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();
        $size = 0;

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                $size += strlen($chunk);

                if ($size > self::MAX_BYTES) {
                    return null;
                }

                fwrite($output, $chunk);
            }
        } catch (Throwable) {
            return null;
        } finally {
            fclose($output);
            $stream->close();
        }

        if ($size === 0) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($target);
        $dimensions = @getimagesize($target);

        if (! is_string($mime) || ! in_array($mime, self::IMAGE_TYPES, true) || $dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096) {
            return null;
        }

        return $mime;
    }
}
