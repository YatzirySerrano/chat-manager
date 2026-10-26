<?php

namespace App\Jobs;

use App\Enums\AttachmentStatus;
use App\Events\Conversations\ConversationChanged;
use App\Exceptions\StorageUnavailable;
use App\Models\MessageAttachment;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MediaRejected;
use App\Services\Meta\MediaStorage;
use App\Services\Meta\MetaGraphClient;
use App\Services\Meta\MetaGraphException;
use App\Services\Storage\MediaDisk;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Downloads the media a customer sent (image, document, audio, video,
 * sticker) from WhatsApp to the private media disk:
 *
 * Messenger and Instagram announce a pre-signed CDN URL instead of a media
 * id: it is downloaded directly (step 2) without any token, with the same
 * host allowlist and the channel's own inbound limits (MediaStorage).
 *
 * 1. GET /{media-id} returns a short-lived URL (it expires in minutes, so a
 *    fresh one is requested on every attempt), its size and type;
 * 2. the file is downloaded with the token in the Authorization header,
 *    only from Meta's media hosts, into a temporary file that is always
 *    removed;
 * 3. its real type (finfo) and size are checked against the channel's
 *    limits and it is stored under a random path.
 *
 * Temporary failures (Meta unreachable, throttled, 5xx) are retried with
 * backoff; a file Meta no longer serves, too large or of a forbidden type
 * marks the attachment "unavailable" with the reason, never retried.
 *
 * A media disk that is not ready or refuses the write (StorageUnavailable)
 * is retried within the same attempts; then the attachment is marked
 * unavailable with "storage_unavailable" (attachments:recover-stale may
 * try again while Meta still keeps the file). The temporary file is
 * removed only after the store finished, and a stored copy the row no
 * longer needs (another attempt won) is deleted.
 */
class DownloadInboundMedia implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 120;

    public function __construct(public int $attachmentId)
    {
        $this->onQueue(Queues::name(Queues::MEDIA));
        $this->tries = max(1, (int) config('whatsapp.media.download_tries', 4));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('whatsapp.media.download_backoff', [30, 120, 600])));
    }

    public function handle(MetaGraphClient $graph, MediaStorage $storage, ConnectionMonitor $monitor, MediaDisk $mediaDisk): void
    {
        $attachment = MessageAttachment::query()->with('message.conversation.channelConnection')->find($this->attachmentId);

        $sourceUrl = $attachment === null ? null : self::sourceUrl($attachment);

        if ($attachment === null || $attachment->status !== AttachmentStatus::Pending || ($attachment->whatsapp_media_id === null && $sourceUrl === null)) {
            return;
        }

        $connection = $attachment->message->conversation->channelConnection;
        $token = (string) $connection?->access_token;

        if ($connection === null || $token === '') {
            $this->markUnavailable($attachment, 'credentials_missing');

            return;
        }

        if ($attachment->whatsapp_media_id !== null && str_starts_with($attachment->whatsapp_media_id, 'sim.')) {
            // SIMULACIÓN: a fictitious media id has no file in Meta.
            $this->markUnavailable($attachment, 'simulated_media');

            return;
        }

        MessageAttachment::query()->whereKey($attachment->id)->increment('download_attempts');

        // Nothing to download into while the media disk cannot keep it.
        if (! $mediaDisk->ready()) {
            $this->retryLater($attachment, StorageUnavailable::CODE);

            return;
        }

        $kind = $attachment->message->type->value;
        $temporary = tempnam(sys_get_temp_dir(), 'wa-media-');

        if ($temporary === false) {
            throw new \RuntimeException('No temporary file could be created for the media download.');
        }

        $channel = $connection->channel;

        try {
            if ($sourceUrl !== null) {
                // Messenger / Instagram: the pre-signed CDN URL came with the
                // webhook (only Meta hosts are allowed); it needs no token, so
                // none is sent to the CDN.
                $graph->download(null, $sourceUrl, $temporary, $storage->rules($kind, $channel)['max_bytes']);
            } else {
                $media = $graph->media($token, (string) $attachment->whatsapp_media_id);

                if ($media['file_size'] !== null) {
                    $storage->assertSize($kind, $media['file_size'], $channel);
                }

                // The limit is enforced while streaming, even when Meta reports no size.
                $graph->download($token, $media['url'], $temporary, $storage->rules($kind, $channel)['max_bytes']);
            }

            $stored = $storage->store($temporary, $kind, $channel);
        } catch (MediaRejected $exception) {
            $this->markUnavailable($attachment, $exception->reason);

            return;
        } catch (StorageUnavailable $exception) {
            $exception->report();
            $this->retryLater($attachment, StorageUnavailable::CODE);

            return;
        } catch (MetaGraphException $exception) {
            if ($exception->isRetryable() || $exception->isAmbiguous()) {
                $this->retryLater($attachment);

                return;
            }

            // A CDN URL that stopped answering says nothing about the token.
            if ($sourceUrl !== null) {
                $this->markUnavailable($attachment, 'expired');

                return;
            }

            if ($exception->kind === MetaGraphException::TOKEN_INVALID) {
                $monitor->credentialsRejected($connection, $exception->getMessage());
            }

            $this->markUnavailable($attachment, match ($exception->kind) {
                MetaGraphException::TOKEN_INVALID, MetaGraphException::PERMISSION => 'credentials_rejected',
                default => 'expired',
            });

            return;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        try {
            $recorded = DB::transaction(function () use ($attachment, $stored): bool {
                $current = MessageAttachment::query()->lockForUpdate()->find($attachment->id);

                if ($current === null || $current->status !== AttachmentStatus::Pending) {
                    return false;
                }

                $current->forceFill([
                    ...$stored,
                    'status' => AttachmentStatus::Available,
                    'error_code' => null,
                    'downloaded_at' => now(),
                ])->save();

                ConversationChanged::dispatch($attachment->message->conversation_id, 'message');

                return true;
            });
        } catch (Throwable $exception) {
            $mediaDisk->discard($stored['disk'], $stored['path']);

            throw $exception;
        }

        if (! $recorded) {
            // Another attempt already settled the row: this copy is unreferenced.
            $mediaDisk->discard($stored['disk'], $stored['path']);
        }
    }

    /**
     * CDN URL of a Messenger or Instagram attachment (kept in the message
     * content by ReceiveInboundMessage); null for WhatsApp media ids.
     */
    private static function sourceUrl(MessageAttachment $attachment): ?string
    {
        $content = $attachment->message->metadata['content'] ?? null;

        if ($attachment->whatsapp_media_id !== null || ! is_array($content) || ($content['source'] ?? null) !== 'url') {
            return null;
        }

        return is_string($content['url'] ?? null) && $content['url'] !== '' ? $content['url'] : null;
    }

    /**
     * A temporary failure: try again with backoff while attempts remain.
     */
    private function retryLater(MessageAttachment $attachment, string $reason = 'download_failed'): void
    {
        if ($this->attempts() >= $this->tries) {
            $this->markUnavailable($attachment, $reason);

            return;
        }

        $backoff = $this->backoff();
        $this->release($backoff[$this->attempts() - 1] ?? (end($backoff) ?: 60));
    }

    /**
     * The job crashed after every attempt: the file stays unavailable.
     */
    public function failed(?Throwable $exception): void
    {
        $attachment = MessageAttachment::query()->with('message')->find($this->attachmentId);

        if ($attachment !== null) {
            Log::warning('WhatsApp media could not be downloaded after every attempt.', ['attachment_id' => $attachment->id]);
            $this->markUnavailable($attachment, $exception instanceof StorageUnavailable ? StorageUnavailable::CODE : 'download_failed');
        }
    }

    private function markUnavailable(MessageAttachment $attachment, string $reason): void
    {
        $updated = MessageAttachment::query()
            ->whereKey($attachment->id)
            ->where('status', AttachmentStatus::Pending)
            ->update(['status' => AttachmentStatus::Unavailable, 'error_code' => $reason, 'updated_at' => now()]);

        if ($updated > 0) {
            ConversationChanged::dispatch($attachment->message->conversation_id, 'message');
        }
    }
}
