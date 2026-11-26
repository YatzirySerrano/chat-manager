<?php

namespace App\Jobs;

use App\Exceptions\StorageUnavailable;
use App\Models\Storage\MediaReplica;
use App\Services\Storage\LocalMedia;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MountHealth;
use App\Services\Storage\RemoteCircuit;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies one file kept on this server to the NAS (local first, see
 * LocalMedia) on its own queue (media-sync) and workers, so a share that
 * hangs in the kernel never blocks downloads, sends or web requests.
 *
 * Idempotent and safe to repeat: the row is claimed with a lease (a worker
 * killed mid copy leaves it to be claimed again once the lease expires), the
 * copy goes through a temporary file renamed into place, the remote copy is
 * verified by size and SHA-256, and a file already identical on the NAS is
 * simply accepted. Failures wait with exponential backoff and jitter, feed
 * the circuit breaker and are retried for as long as needed (the file stays
 * available here meanwhile). Nothing reaches failed_jobs: the row holds the
 * state, and media:sync dispatches the due rows again.
 */
class SyncMediaReplica implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 900;

    public function __construct(public int $replicaId)
    {
        $this->onQueue(Queues::name(Queues::MEDIA_SYNC));
        $this->timeout = max(60, (int) config('attachments.storage.local_first.sync_timeout_seconds', 600));
    }

    public function uniqueId(): string
    {
        return (string) $this->replicaId;
    }

    public function handle(MediaDisk $media, LocalMedia $local, MountHealth $mounts, RemoteCircuit $circuit): void
    {
        $replica = MediaReplica::query()->find($this->replicaId);

        if ($replica === null || $replica->state !== MediaReplica::PENDING) {
            return;
        }

        // The NAS is not answering (last health check) or the circuit is open:
        // not an attempt, just later.
        if (! $mounts->remoteAllowed($replica->disk) || ! $circuit->allows($replica->disk)) {
            MediaReplica::query()->whereKey($replica->id)->where('state', MediaReplica::PENDING)
                ->update(['next_attempt_at' => now()->addSeconds(60), 'updated_at' => now()]);

            return;
        }

        $lease = max(120, (int) config('attachments.storage.local_first.sync_lease_seconds', 900));
        $claimed = MediaReplica::query()
            ->whereKey($replica->id)
            ->where('state', MediaReplica::PENDING)
            ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<', now()))
            ->update(['claimed_until' => now()->addSeconds($lease), 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $source = $local->absolute($replica->disk, $replica->path);

        if (! is_file($source)) {
            $this->settle($replica, MediaReplica::CONFLICT, 'local_missing');

            return;
        }

        $started = microtime(true);

        try {
            $media->copyToMount($replica->disk, $source, $replica->path, $replica->size_bytes, (string) $replica->sha256);
        } catch (StorageUnavailable $exception) {
            if (in_array($exception->reason, ['remote_conflict', 'local_missing'], true)) {
                $this->settle($replica, MediaReplica::CONFLICT, $exception->reason);

                return;
            }

            $this->retryLater($replica, $exception->reason, $circuit);

            return;
        } catch (Throwable $exception) {
            Log::warning('Media sync failed.', ['replica_id' => $replica->id, 'exception' => $exception::class]);
            $this->retryLater($replica, 'sync_failed', $circuit);

            return;
        }

        MediaReplica::query()->whereKey($replica->id)->update([
            'state' => MediaReplica::SYNCED,
            'synced_at' => now(),
            'sync_ms' => (int) round((microtime(true) - $started) * 1000),
            'attempts' => $replica->attempts + 1,
            'claimed_until' => null,
            'next_attempt_at' => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);

        $circuit->success($replica->disk);
        $local->forgetPendingBytes();

        try {
            Cache::put('storage:local-media:last-sync', now()->getTimestamp(), 86400 * 30);
        } catch (Throwable) {
            // Informative only.
        }
    }

    private function retryLater(MediaReplica $replica, string $reason, RemoteCircuit $circuit): void
    {
        $attempts = $replica->attempts + 1;
        $base = max(5, (int) config('attachments.storage.local_first.sync_backoff_base', 30));
        $max = max($base, (int) config('attachments.storage.local_first.sync_backoff_max', 3600));
        $delay = min($max, $base * (2 ** min(16, $attempts - 1)));
        // Jitter: workers coming back after an outage do not hit the NAS at once.
        $delay = (int) round($delay * (0.75 + mt_rand(0, 500) / 1000));

        MediaReplica::query()->whereKey($replica->id)->update([
            'attempts' => $attempts,
            'next_attempt_at' => now()->addSeconds($delay),
            'claimed_until' => null,
            'last_error' => mb_substr($reason, 0, 64),
            'updated_at' => now(),
        ]);

        $circuit->failure($replica->disk);
    }

    private function settle(MediaReplica $replica, string $state, string $reason): void
    {
        MediaReplica::query()->whereKey($replica->id)->update([
            'state' => $state,
            'claimed_until' => null,
            'next_attempt_at' => null,
            'last_error' => $reason,
            'updated_at' => now(),
        ]);

        Log::warning('Media sync needs attention.', ['replica_id' => $replica->id, 'reason' => $reason]);
    }
}
