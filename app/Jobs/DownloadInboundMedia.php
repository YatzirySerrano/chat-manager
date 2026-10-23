<?php

namespace App\Jobs;

use App\Enums\AttachmentStatus;
use App\Events\Conversations\ConversationChanged;
use App\Models\MessageAttachment;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MediaRejected;
use App\Services\Meta\MediaStorage;
use App\Services\Meta\MetaGraphClient;
use App\Services\Meta\MetaGraphException;
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
 * 1. GET /{media-id} returns a short-lived URL (it expires in minutes, so a
 *    fresh one is requested on every attempt), its size and type;
 * 2. the file is downloaded with the token in the Authorization header,
 *    only from Meta's media hosts, into a temporary file that is always
 *    removed;
 * 3. its real type (finfo) and size are checked against config/whatsapp.php
 *    and it is stored under a random path.
 *
 * Temporary failures (Meta unreachable, throttled, 5xx) are retried with
 * backoff; a file Meta no longer serves, too large or of a forbidden type
 * marks the attachment "unavailable" with the reason, never retried.
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

    public function handle(MetaGraphClient $graph, MediaStorage $storage, ConnectionMonitor $monitor): void
    {
        $attachment = MessageAttachment::query()->with('message.conversation.channelConnection')->find($this->attachmentId);

        if ($attachment === null || $attachment->status !== AttachmentStatus::Pending || $attachment->whatsapp_media_id === null) {
            return;
        }

        $connection = $attachment->message->conversation->channelConnection;
        $token = (string) $connection?->access_token;

        if ($connection === null || $token === '') {
            $this->markUnavailable($attachment, 'credentials_missing');

            return;
        }

        if (str_starts_with($attachment->whatsapp_media_id, 'sim.')) {
            // SIMULACIÓN: a fictitious media id has no file in Meta.
            $this->markUnavailable($attachment, 'simulated_media');

            return;
        }

        MessageAttachment::query()->whereKey($attachment->id)->increment('download_attempts');
        $kind = $attachment->message->type->value;
        $temporary = tempnam(sys_get_temp_dir(), 'wa-media-');

        if ($temporary === false) {
            throw new \RuntimeException('No temporary file could be created for the media download.');
        }

        try {
            $media = $graph->media($token, $attachment->whatsapp_media_id);

            if ($media['file_size'] !== null) {
                $storage->assertSize($kind, $media['file_size']);
            }

            // The limit is enforced while streaming, even when Meta reports no size.
            $graph->download($token, $media['url'], $temporary, $storage->rules($kind)['max_bytes']);
            $stored = $storage->store($temporary, $kind);
        } catch (MediaRejected $exception) {
            $this->markUnavailable($attachment, $exception->reason);

            return;
        } catch (MetaGraphException $exception) {
            if ($exception->isRetryable() || $exception->isAmbiguous()) {
                $this->retryLater($attachment);

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

        DB::transaction(function () use ($attachment, $stored): void {
            $current = MessageAttachment::query()->lockForUpdate()->find($attachment->id);

            if ($current === null || $current->status !== AttachmentStatus::Pending) {
                return;
            }

            $current->forceFill([
                ...$stored,
                'status' => AttachmentStatus::Available,
                'error_code' => null,
                'downloaded_at' => now(),
            ])->save();

            ConversationChanged::dispatch($attachment->message->conversation_id, 'message');
        });
    }

    /**
     * A temporary failure: try again with backoff while attempts remain.
     */
    private function retryLater(MessageAttachment $attachment): void
    {
        if ($this->attempts() >= $this->tries) {
            $this->markUnavailable($attachment, 'download_failed');

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
            $this->markUnavailable($attachment, 'download_failed');
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
