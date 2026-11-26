<?php

namespace App\Console\Commands;

use App\Jobs\SyncMediaReplica;
use App\Models\Storage\MediaReplica;
use App\Services\Storage\LocalMedia;
use App\Services\Storage\MountHealth;
use App\Services\Storage\RemoteCircuit;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Local first synchronization to the NAS (LocalMedia, SyncMediaReplica),
 * run every minute by the scheduler:
 *
 * - dispatches the pending copies that are due (backoff elapsed, no live
 *   lease), at most sync_batch per run, only while the last NAS health check
 *   passed and the circuit is closed: after an outage the backlog drains
 *   gradually instead of hitting the share all at once;
 * - --status prints the figures without changing anything;
 * - --reconcile checks the rows against the local files (a pending file
 *   whose local copy is gone, a cached copy already removed) and clears
 *   temporary files left by interrupted writes;
 * - --retry-conflicts puts the files that found a different file on the NAS
 *   back to pending (after an administrator resolved it there).
 */
class SyncLocalMedia extends Command
{
    protected $signature = 'media:sync
        {--status : Only print the figures}
        {--reconcile : Check rows against local files and remove stale temporary files}
        {--retry-conflicts : Put the files in conflict back to pending}
        {--limit= : Copies to dispatch at most (default attachments.storage.local_first.sync_batch)}';

    protected $description = 'Copy the files kept on this server to the NAS (local first), gradually and only while it answers';

    public function handle(LocalMedia $local, MountHealth $mounts, RemoteCircuit $circuit): int
    {
        if ($this->option('status')) {
            return $this->status($mounts, $circuit);
        }

        if ($this->option('reconcile')) {
            return $this->reconcile($local);
        }

        if ($this->option('retry-conflicts')) {
            $count = MediaReplica::query()->where('state', MediaReplica::CONFLICT)->where('last_error', 'remote_conflict')
                ->update(['state' => MediaReplica::PENDING, 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null, 'updated_at' => now()]);
            $this->info("Archivos en conflicto que vuelven a sincronizarse: {$count}.");

            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('attachments.storage.local_first.sync_batch', 60)));
        $dispatched = 0;

        foreach (MediaReplica::query()->where('state', MediaReplica::PENDING)->distinct()->pluck('disk') as $disk) {
            $disk = (string) $disk;

            if (! $mounts->remoteAllowed($disk) || ! $circuit->allows($disk)) {
                $this->line("El NAS «{$disk}» no está disponible: los archivos siguen guardados en el servidor y se copiarán cuando responda.");

                continue;
            }

            $ids = $this->due($disk)->orderByRaw('next_attempt_at IS NULL DESC')->orderBy('next_attempt_at')->orderBy('id')
                ->limit($limit - $dispatched)->pluck('id');

            foreach ($ids as $id) {
                SyncMediaReplica::dispatch((int) $id);
                $dispatched++;
            }

            if ($dispatched >= $limit) {
                break;
            }
        }

        $this->info("Copias al NAS encoladas: {$dispatched}.");

        return self::SUCCESS;
    }

    /**
     * @return Builder<MediaReplica>
     */
    private function due(string $disk): Builder
    {
        return MediaReplica::query()
            ->where('disk', $disk)
            ->where('state', MediaReplica::PENDING)
            ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<', now()));
    }

    private function status(MountHealth $mounts, RemoteCircuit $circuit): int
    {
        $rows = MediaReplica::query()
            ->selectRaw('disk, state, local_present, COUNT(*) as files, COALESCE(SUM(size_bytes), 0) as bytes, MIN(created_at) as oldest')
            ->groupBy('disk', 'state', 'local_present')
            ->get();

        $this->table(['Disco', 'Estado', 'Copia local', 'Archivos', 'Peso (MB)', 'Más antiguo'], $rows->map(fn (MediaReplica $row): array => [
            $row->disk,
            $row->state,
            $row->local_present ? 'sí' : 'no',
            $row->getAttribute('files'),
            round(((int) $row->getAttribute('bytes')) / 1048576, 1),
            (string) $row->getAttribute('oldest'),
        ])->all());

        foreach ($rows->pluck('disk')->unique() as $disk) {
            $last = $mounts->lastCheck((string) $disk);
            $open = $circuit->openFor((string) $disk);
            $this->line("NAS «{$disk}»: estado ".$mounts->state((string) $disk).', última prueba '.($last === null ? 'ninguna' : ($last['problem'] ?? 'correcta').' ('.($last['latency_ms'] ?? '?').' ms)').($open !== null ? ", copias en pausa {$open} s" : ''));
        }

        return self::SUCCESS;
    }

    private function reconcile(LocalMedia $local): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: 5000));
        $lost = 0;
        $gone = 0;

        MediaReplica::query()->where('local_present', true)->orderBy('id')->limit($limit)->get(['id', 'disk', 'path', 'state'])
            ->each(function (MediaReplica $replica) use ($local, &$lost, &$gone): void {
                if ($local->hasLocal($replica->disk, $replica->path)) {
                    return;
                }

                if ($replica->state === MediaReplica::SYNCED) {
                    MediaReplica::query()->whereKey($replica->id)->update(['local_present' => false, 'local_purged_at' => now(), 'updated_at' => now()]);
                    $gone++;

                    return;
                }

                // Only here and missing: an administrator must look at it.
                MediaReplica::query()->whereKey($replica->id)->update(['state' => MediaReplica::CONFLICT, 'last_error' => 'local_missing', 'updated_at' => now()]);
                $lost++;
            });

        $temporary = 0;
        $root = $local->root();

        if (is_dir($root)) {
            try {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

                /** @var SplFileInfo $file */
                foreach ($files as $file) {
                    if ($file->isFile() && str_ends_with($file->getFilename(), '.part') && str_starts_with($file->getFilename(), '.') && $file->getMTime() < now()->subDay()->getTimestamp()) {
                        @unlink($file->getPathname());
                        $temporary++;
                    }
                }
            } catch (Throwable $exception) {
                $this->warn('No se pudo recorrer la carpeta local: '.$exception::class);
            }
        }

        $local->forgetPendingBytes();
        $this->info("Pendientes sin copia local (revisar): {$lost}; copias locales ya ausentes: {$gone}; temporales viejos borrados: {$temporary}.");

        return self::SUCCESS;
    }
}
