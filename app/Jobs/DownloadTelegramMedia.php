<?php

namespace App\Jobs;

use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Events\Conversations\ConversationChanged;
use App\Exceptions\StorageUnavailable;
use App\Models\MessageAttachment;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MediaRejected;
use App\Services\Meta\MediaStorage;
use App\Services\Storage\MediaDisk;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramException;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Downloads a file a customer sent to a Telegram bot to the private media
 * disk. The Telegram counterpart of DownloadInboundMedia:
 *
 * 1. getFile turns the file_id (kept encrypted on the attachment) into a
 *    path valid for about an hour, so it is asked again on every attempt;
 * 2. the file is downloaded server-side from /file/bot<token>/<path> (the
 *    token never reaches the browser) into a temporary file that is always
 *    removed, with the 20 MB a bot may download enforced while streaming;
 * 3. its real type (finfo) and size are checked against the Telegram table
 *    of MediaStorage and it is stored in the contact's folder
 *    ("Telegram/…", MediaPaths).
 *
 * Telegram unreachable, throttled or failing, and a media disk that is not
 * ready, are retried with backoff; a file Telegram no longer serves, too
 * large or of a forbidden type marks the attachment "unavailable" with the
 * reason, never retried. The file_id is forgotten once the file is stored.
 */
class DownloadTelegramMedia implements ShouldQueue
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

    public function handle(TelegramBotClient $client, MediaStorage $storage, MediaDisk $mediaDisk, ConnectionMonitor $monitor): void
    {
        $attachment = MessageAttachment::query()->with('message.conversation.channelConnection')->find($this->attachmentId);

        if ($attachment === null || $attachment->status !== AttachmentStatus::Pending) {
            return;
        }

        $connection = $attachment->message->conversation->channelConnection;
        $fileId = $attachment->provider_reference['file_id'] ?? null;

        if ($connection === null || ! $connection->isTelegram() || ! is_string($fileId) || $fileId === '') {
            $this->markUnavailable($attachment, 'expired');

            return;
        }

        $token = (string) $connection->access_token;

        if ($token === '') {
            $this->markUnavailable($attachment, 'credentials_missing');

            return;
        }

        MessageAttachment::query()->whereKey($attachment->id)->increment('download_attempts');

        if (! $mediaDisk->ready()) {
            $this->retryLater($attachment, StorageUnavailable::CODE);

            return;
        }

        $kind = $attachment->message->type->value;
        $temporary = tempnam(sys_get_temp_dir(), 'tg-media-');

        if ($temporary === false) {
            throw new \RuntimeException('No temporary file could be created for the media download.');
        }

        try {
            $maxBytes = min(TelegramBotClient::DOWNLOAD_MAX_BYTES, $storage->rules($kind, ConnectionChannel::Telegram)['max_bytes']);
            $file = $client->getFile($token, $fileId);

            if ($file['file_size'] !== null) {
                $storage->assertSize($kind, $file['file_size'], ConnectionChannel::Telegram);
            }

            $client->download($token, $file['file_path'], $temporary, $maxBytes);
            $stored = $storage->store($temporary, $kind, ConnectionChannel::Telegram, $attachment);

            // Audio in an MP4 or WebM container is detected as video: it is
            // still the audio the message says, and the chat picks its player by type.
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
        } catch (TelegramException $exception) {
            if ($exception->isRetryable() || $exception->kind === TelegramException::AMBIGUOUS) {
                $this->retryLater($attachment);

                return;
            }

            if ($exception->kind === TelegramException::UNAUTHORIZED) {
                $monitor->credentialsRejected($connection, $exception->getMessage());
            }

            $this->markUnavailable($attachment, match ($exception->kind) {
                TelegramException::UNAUTHORIZED => 'credentials_rejected',
                TelegramException::FILE_TOO_BIG => 'too_large',
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
        // A storage failure is ours, not the file's: it never uses up the
        // download budget nor marks the file unavailable for good.
        if ($reason === StorageUnavailable::CODE) {
            self::parkForStorage($attachment->id);
        }

        if ($this->attempts() >= $this->tries) {
            if ($reason !== StorageUnavailable::CODE) {
                $this->markUnavailable($attachment, $reason);
            }

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
            if ($exception instanceof StorageUnavailable) {
                // Still pending: attachments:recover-stale downloads it again.
                self::parkForStorage($attachment->id);

                return;
            }

            $this->markUnavailable($attachment, 'download_failed');
        }
    }

    /**
     * The file stays "pending" with the storage reason (shown as waiting,
     * downloaded again by attachments:recover-stale once storage answers),
     * and this attempt is not counted.
     */
    public static function parkForStorage(int $attachmentId): void
    {
        MessageAttachment::query()
            ->whereKey($attachmentId)
            ->where('status', AttachmentStatus::Pending)
            ->update([
                'error_code' => StorageUnavailable::CODE,
                'download_attempts' => DB::raw('CASE WHEN download_attempts > 0 THEN download_attempts - 1 ELSE 0 END'),
                'updated_at' => now(),
            ]);
    }

    private function markUnavailable(MessageAttachment $attachment, string $reason): void
    {
        $updated = MessageAttachment::query()
            ->whereKey($attachment->id)
            ->where('status', AttachmentStatus::Pending)
            ->update(['status' => AttachmentStatus::Unavailable, 'error_code' => $reason, 'provider_reference' => null, 'updated_at' => now()]);

        if ($updated > 0) {
            // Never the file id, the token, the sender or the file's content.
            Log::warning('Telegram media marked unavailable.', [
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
