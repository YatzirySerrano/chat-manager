<?php

namespace App\Console\Commands;

use App\Enums\AttachmentStatus;
use App\Events\Conversations\ConversationChanged;
use App\Exceptions\StorageUnavailable;
use App\Jobs\DownloadInboundMedia;
use App\Models\MessageAttachment;
use App\Services\Storage\MediaDisk;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Settles customer media whose download never finished (a lost job, a
 * worker outage, the media disk down), bounded and idempotent:
 *
 * 1. "pending" files older than Meta keeps them
 *    (attachments.storage.inbound_available_days; WhatsApp media ids from
 *    webhooks expire after 7 days) become unavailable ("expired");
 * 2. "pending" files that already used every download
 *    (attachments.storage.max_download_attempts) become unavailable
 *    ("download_failed");
 * 3. other "pending" files without activity for pending_stale_minutes are
 *    queued again;
 * 4. while the media disk is ready, files that failed only because it was
 *    not ("storage_unavailable") are queued again within the same limits.
 *
 * Every step handles at most recover_batch rows per run, and a queued row
 * is touched so the next run leaves it alone until it is stale again.
 * Nothing is ever deleted.
 */
class RecoverStaleAttachments extends Command
{
    protected $signature = 'attachments:recover-stale {--limit= : Rows per step (default attachments.storage.recover_batch)}';

    protected $description = 'Retry or expire customer media whose download never finished';

    public function handle(MediaDisk $mediaDisk): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('attachments.storage.recover_batch', 200)));
        $maxAttempts = max(1, (int) config('attachments.storage.max_download_attempts', 12));
        $availableSince = CarbonImmutable::now()->subDays(max(1, (int) config('attachments.storage.inbound_available_days', 7)));
        $staleBefore = CarbonImmutable::now()->subMinutes(max(1, (int) config('attachments.storage.pending_stale_minutes', 60)));

        $expired = $this->settle(
            MessageAttachment::query()->where('status', AttachmentStatus::Pending)->where('created_at', '<', $availableSince),
            $limit,
            'expired',
        );

        $exhausted = $this->settle(
            MessageAttachment::query()->where('status', AttachmentStatus::Pending)->where('updated_at', '<', $staleBefore)->where('download_attempts', '>=', $maxAttempts),
            $limit,
            'download_failed',
        );

        $requeued = $this->requeue(
            MessageAttachment::query()
                ->where('status', AttachmentStatus::Pending)
                ->where('created_at', '>=', $availableSince)
                ->where('updated_at', '<', $staleBefore)
                ->where('download_attempts', '<', $maxAttempts),
            $limit,
        );

        $recovered = 0;

        if ($mediaDisk->ready()) {
            $recovered = $this->requeue(
                MessageAttachment::query()
                    ->where('status', AttachmentStatus::Unavailable)
                    ->where('error_code', StorageUnavailable::CODE)
                    ->where('created_at', '>=', $availableSince)
                    ->where('updated_at', '<', $staleBefore)
                    ->where('download_attempts', '<', $maxAttempts),
                $limit,
                fromStatus: AttachmentStatus::Unavailable,
            );
        }

        $this->info("Adjuntos vencidos: {$expired}; sin más intentos: {$exhausted}; reintentados: {$requeued}; recuperados tras falla de almacenamiento: {$recovered}");

        return self::SUCCESS;
    }

    /**
     * Marks the rows unavailable with the reason (only while still pending).
     *
     * @param  Builder<MessageAttachment>  $query
     */
    private function settle(Builder $query, int $limit, string $reason): int
    {
        $rows = $this->batch($query, $limit);

        if ($rows->isEmpty()) {
            return 0;
        }

        $updated = MessageAttachment::query()
            ->whereKey($rows->modelKeys())
            ->where('status', AttachmentStatus::Pending)
            ->update(['status' => AttachmentStatus::Unavailable, 'error_code' => $reason, 'updated_at' => now()]);

        $this->announce($rows);

        return $updated;
    }

    /**
     * Back to pending (touched) and queued again, one job per row.
     *
     * @param  Builder<MessageAttachment>  $query
     */
    private function requeue(Builder $query, int $limit, AttachmentStatus $fromStatus = AttachmentStatus::Pending): int
    {
        $requeued = 0;

        foreach ($this->batch($query, $limit) as $attachment) {
            $updated = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->where('status', $fromStatus)
                ->update(['status' => AttachmentStatus::Pending, 'error_code' => null, 'updated_at' => now()]);

            if ($updated > 0) {
                DownloadInboundMedia::dispatch($attachment->id);
                $requeued++;
            }
        }

        return $requeued;
    }

    /**
     * @param  Builder<MessageAttachment>  $query
     * @return Collection<int, MessageAttachment>
     */
    private function batch(Builder $query, int $limit): Collection
    {
        return $query->with('message:id,conversation_id')->orderBy('id')->limit($limit)->get(['id', 'message_id']);
    }

    /**
     * @param  Collection<int, MessageAttachment>  $rows
     */
    private function announce(Collection $rows): void
    {
        $rows->map(fn (MessageAttachment $attachment): ?int => $attachment->message?->conversation_id)
            ->filter()
            ->unique()
            ->each(fn (int $conversationId) => ConversationChanged::dispatch($conversationId, 'message'));
    }
}
