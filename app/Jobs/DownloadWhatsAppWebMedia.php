<?php

namespace App\Jobs;

use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Events\Conversations\ConversationChanged;
use App\Exceptions\StorageUnavailable;
use App\Models\MessageAttachment;
use App\Services\Meta\MediaRejected;
use App\Services\Meta\MediaStorage;
use App\Services\Storage\MediaDisk;
use App\Services\WhatsAppWeb\WhatsAppWebClient;
use App\Services\WhatsAppWeb\WhatsAppWebException;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Downloads a file received through WhatsApp Web (QR) to the private media
 * disk. The WhatsApp Web counterpart of DownloadInboundMedia:
 *
 * 1. the private Node service downloads and decrypts the file from the
 *    descriptor that came with the message (kept encrypted on the
 *    attachment) and streams it to a temporary file that is always removed;
 * 2. its real type (finfo) and size are checked against the limits of this
 *    modality (MediaStorage::whatsAppWebRules, config whatsapp_web.media.types)
 *    and it is stored in the folder of its contact on the media disk
 *    (MediaDisk), never on a public one.
 *
 * A media disk that is not ready or refuses the write (a NAS that is not
 * mounted, when it is required) is retried with backoff and never replaced
 * by the server's own disk; a session that is not connected or a service
 * that does not answer is retried too. A file WhatsApp no longer serves,
 * too large or of a forbidden type marks the attachment "unavailable" with
 * the reason, never retried. The descriptor is forgotten once the file is
 * stored.
 */
class DownloadWhatsAppWebMedia implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 140;

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

    public function handle(WhatsAppWebClient $client, MediaStorage $storage, MediaDisk $mediaDisk): void
    {
        $attachment = MessageAttachment::query()->with('message.conversation.channelConnection')->find($this->attachmentId);

        if ($attachment === null || $attachment->status !== AttachmentStatus::Pending) {
            return;
        }

        $connection = $attachment->message->conversation->channelConnection;
        $descriptor = $attachment->provider_reference;

        if ($connection === null || ! $connection->isWhatsAppWeb() || ! is_array($descriptor) || $descriptor === []) {
            $this->markUnavailable($attachment, 'expired');

            return;
        }

        MessageAttachment::query()->whereKey($attachment->id)->increment('download_attempts');

        // Nothing to download into while the media disk cannot keep it.
        if (! $mediaDisk->ready()) {
            $this->retryLater($attachment, StorageUnavailable::CODE);

            return;
        }

        $kind = $attachment->message->type->value;
        $rules = $storage->whatsAppWebRules($kind);
        $temporary = tempnam(sys_get_temp_dir(), 'wweb-media-');

        if ($temporary === false) {
            throw new \RuntimeException('No temporary file could be created for the media download.');
        }

        try {
            // The limit is enforced while streaming, whatever size was announced.
            $client->downloadMedia($connection->id, $descriptor, $temporary, $rules['max_bytes']);
            $stored = $storage->store($temporary, $kind, ConnectionChannel::Whatsapp, $attachment, $rules);

            // An audio in an MP4, 3GP, WebM or Ogg container is detected as
            // video: it is still the audio the message says, and the chat
            // picks its player by this type.
            if ($kind === 'audio' && str_starts_with($stored['mime_type'], 'video/')) {
                $stored['mime_type'] = 'audio/'.substr($stored['mime_type'], 6);
            }
        } catch (MediaRejected $exception) {
            $this->markUnavailable($attachment, $exception->reason);

            return;
        } catch (StorageUnavailable $exception) {
            $exception->report();
            $this->retryLater($attachment, StorageUnavailable::CODE);

            return;
        } catch (WhatsAppWebException $exception) {
            // WhatsApp no longer serves the file (or the descriptor is unusable).
            if ($exception->kind === WhatsAppWebException::REJECTED) {
                $this->markUnavailable($attachment, 'expired');

                return;
            }

            // The service or the session is not available right now.
            $this->retryLater($attachment);

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
                    // The decryption key is not needed any more.
                    'provider_reference' => null,
                ])->save();

                ConversationChanged::dispatch($attachment->message->conversation_id, 'message', messageId: $attachment->message_id);

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
            // Never the descriptor, the sender or the file's content.
            Log::warning('WhatsApp Web media marked unavailable.', [
                'attachment_id' => $attachment->id,
                'message_type' => $attachment->message->type->value,
                'reason' => $reason,
                'declared_mime' => $attachment->mime_type,
                'attempt' => $this->job !== null ? $this->attempts() : null,
            ]);

            ConversationChanged::dispatch($attachment->message->conversation_id, 'message', messageId: $attachment->message_id);
        }
    }
}
