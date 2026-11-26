<?php

namespace App\Console\Commands;

use App\Models\Storage\MediaReplica;
use App\Services\Storage\LocalMedia;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps the local copies of the NAS (LocalMedia) within their room:
 *
 * 1. copies already verified on the NAS that nobody opened for
 *    retention_days are removed from this server;
 * 2. under pressure (the cache above cache_max_bytes, or the free space of
 *    the server below min_free_bytes plus a margin) the least recently used
 *    verified copies go first, files brought back from the NAS before the
 *    ones written here.
 *
 * Files still waiting for their NAS copy ("pending") or in conflict are
 * never removed: only rows "synced" (size and SHA-256 verified on the NAS)
 * are candidates. Bounded per run; --dry-run only reports.
 */
class PruneLocalMedia extends Command
{
    private const PRESSURE_MARGIN_BYTES = 5 * 1024 ** 3;

    protected $signature = 'media:prune-local
        {--dry-run : Only report what would be removed}
        {--limit=5000 : Copies to remove at most}';

    protected $description = 'Remove local copies of media already verified on the NAS (retention and space pressure)';

    public function handle(LocalMedia $local): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $retentionDays = max(0, (int) config('attachments.storage.local_first.retention_days', 7));
        $removed = 0;
        $freed = 0;

        $expired = $this->candidates()
            ->whereRaw('COALESCE(last_accessed_at, synced_at, created_at) < ?', [now()->subDays($retentionDays)])
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'disk', 'path', 'size_bytes'])
            // Images and PDF stay here (MEDIA_SYNC_TYPES): only the kind
            // that goes to the NAS is removed once it is verified there.
            ->filter(fn (MediaReplica $replica): bool => $local->syncable($replica->path));

        foreach ($expired as $replica) {
            $freed += $this->remove($local, $replica, $dryRun);
            $removed++;
        }

        $cacheMax = max(0, (int) config('attachments.storage.local_first.cache_max_bytes', 20 * 1024 ** 3));
        $cacheBytes = (int) $this->candidates()->get(['path', 'size_bytes'])
            ->filter(fn (MediaReplica $replica): bool => $local->syncable($replica->path))
            ->sum('size_bytes') - ($dryRun ? $freed : 0);
        $free = @disk_free_space($local->root());
        $needed = max(
            $cacheBytes - $cacheMax,
            $free === false ? 0 : (int) ($local->minFreeBytes() + self::PRESSURE_MARGIN_BYTES - $free),
        );

        if ($needed > 0 && $removed < $limit) {
            $this->warn('Espacio local bajo presión: se liberan las copias usadas hace más tiempo.');

            foreach ($this->candidates()
                ->orderByRaw("CASE WHEN origin = 'cache' THEN 0 ELSE 1 END")
                ->orderByRaw('COALESCE(last_accessed_at, synced_at, created_at)')
                ->limit($limit - $removed)
                ->cursor() as $replica) {
                if (! $local->syncable($replica->path)) {
                    continue;
                }

                $bytes = $this->remove($local, $replica, $dryRun);
                $freed += $bytes;
                $needed -= $bytes;
                $removed++;

                if ($needed <= 0) {
                    break;
                }
            }
        }

        $local->forgetPendingBytes();
        $this->info(($dryRun ? 'Se quitarían' : 'Copias locales quitadas').": {$removed} (".round($freed / 1048576, 1).' MB). Los archivos siguen en el NAS.');

        return self::SUCCESS;
    }

    /**
     * Only copies verified on the NAS.
     *
     * @return Builder<MediaReplica>
     */
    private function candidates(): Builder
    {
        return MediaReplica::query()
            ->where('state', MediaReplica::SYNCED)
            ->whereNotNull('synced_at')
            ->where('local_present', true);
    }

    private function remove(LocalMedia $local, MediaReplica $replica, bool $dryRun): int
    {
        if ($dryRun) {
            return $replica->size_bytes;
        }

        // Checked again: the row may have changed since it was listed.
        $updated = MediaReplica::query()->whereKey($replica->id)->where('state', MediaReplica::SYNCED)->where('local_present', true)
            ->update(['local_present' => false, 'local_purged_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            return 0;
        }

        @unlink($local->absolute($replica->disk, $replica->path));

        return $replica->size_bytes;
    }
}
