<?php

namespace App\Services\Storage;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

use function Illuminate\Support\php_binary;

/**
 * Health of a media disk that is a network share mounted on this server
 * (a disk with a "mount" section in config/filesystems.php, e.g. "nas").
 *
 * A dropped CIFS/SMB mount leaves an empty local directory behind (writes
 * would silently land on the server) and a hung one can block PHP in I/O.
 * So the checks are split by cost:
 *
 * - cheapProblem(): configuration, the kernel mount table (read from
 *   /proc, never touching the share) and the identity marker at the mount
 *   root. Runs in the request before every write.
 * - fullProblem(): the cheap checks plus stat() device ids (root vs. its
 *   parent) and a write/read/delete probe. It may hang on a dead share, so
 *   it only runs inside a separate process (`storage:mount-health --probe`)
 *   that refresh() kills after a hard timeout.
 *
 * The result of refresh() is cached for health_ttl_seconds; the scheduler
 * refreshes it every minute, and a request only spawns the probe itself
 * when there is no cached result.
 */
class MountHealth
{
    private const CACHE_PREFIX = 'storage:mount-health:';

    /** Probes kept to tell the state (about ten minutes at one per minute). */
    private const HISTORY = 10;

    /**
     * @param  list<string>|null  $command  Probe process to run instead of the artisan probe (tests).
     */
    public function __construct(
        private Repository $cache,
        private MountInspector $inspector,
        private ?array $command = null,
    ) {}

    /**
     * Mount settings of a disk, or null when the disk is not a mounted disk.
     *
     * @return array{root: string, marker: string, id: string, filesystem_types: list<string>}|null
     */
    public static function settings(string $disk): ?array
    {
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'local' || ! is_array($config['mount'] ?? null)) {
            return null;
        }

        $types = $config['mount']['filesystem_types'] ?? [];
        $types = is_string($types) ? explode(',', $types) : (array) $types;

        return [
            'root' => rtrim(trim((string) ($config['root'] ?? '')), '/'),
            'marker' => trim((string) ($config['mount']['marker'] ?? ''), "/ \t\n\r\0\x0B"),
            'id' => trim((string) ($config['mount']['id'] ?? '')),
            'filesystem_types' => array_values(array_filter(array_map(fn (mixed $type): string => strtolower(trim((string) $type)), $types))),
        ];
    }

    /**
     * "not_configured" when the mount lacks an absolute root, a marker file
     * name or an identifier; null when its configuration is complete.
     */
    public static function configurationProblem(string $disk): ?string
    {
        $settings = self::settings($disk);

        return $settings === null || $settings['id'] === '' || $settings['marker'] === '' || ! str_starts_with($settings['root'], '/')
            ? 'not_configured'
            : null;
    }

    /**
     * Checks that never touch the share beyond reading the small marker
     * file. Null when the mount looks right.
     */
    public function cheapProblem(string $disk): ?string
    {
        $settings = self::settings($disk);

        if ($settings === null || self::configurationProblem($disk) !== null) {
            return 'not_configured';
        }

        if ($this->inspector->canReadMountTable()) {
            $type = $this->inspector->mountedType($settings['root']);

            if ($type === null) {
                return 'not_mounted';
            }

            if ($settings['filesystem_types'] !== [] && ! in_array(strtolower($type), $settings['filesystem_types'], true)) {
                return 'wrong_filesystem';
            }
        }

        $marker = @file_get_contents($settings['root'].'/'.$settings['marker'], false, null, 0, 512);

        if (! is_string($marker)) {
            return 'marker_missing';
        }

        return hash_equals($settings['id'], trim($marker)) ? null : 'marker_mismatch';
    }

    /**
     * Every check, including the ones that may block on a dead share. Only
     * run it inside the probe process.
     */
    public function fullProblem(string $disk): ?string
    {
        $problem = $this->cheapProblem($disk);

        if ($problem !== null) {
            return $problem;
        }

        $root = (string) self::settings($disk)['root'];
        $devices = $this->inspector->deviceIds($root);

        if ($devices === null || $devices['path'] === $devices['parent']) {
            return 'not_mounted';
        }

        $directory = $root.'/health';
        $final = $directory.'/probe-'.Str::uuid().'.txt';
        $temporary = $final.'.part';
        $content = 'ok '.Str::random(16);

        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                return 'write_failed';
            }

            if (@file_put_contents($temporary, $content) !== strlen($content) || ! @rename($temporary, $final)) {
                return 'write_failed';
            }

            clearstatcache(true, $final);

            if (@file_get_contents($final) !== $content) {
                return 'write_failed';
            }

            if (! @unlink($final)) {
                return 'delete_failed';
            }

            clearstatcache(true, $final);

            return file_exists($final) ? 'delete_failed' : null;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * The cached result of the last probe. When there is none, a console
     * process (scheduler, workers, commands) runs the probe now (bounded,
     * one at a time); a web request never does: it gets "health_unknown"
     * and is not held by a share that may be dead.
     */
    public function cachedProblem(string $disk): ?string
    {
        $last = $this->lastCheck($disk);

        if ($last !== null) {
            return $last['problem'];
        }

        return app()->runningInConsole() ? $this->refresh($disk) : 'health_unknown';
    }

    /**
     * Whether remote operations (copies to the NAS, reads of files only on
     * the NAS) may touch the share now: the last cached probe passed. Never
     * probes.
     */
    public function remoteAllowed(string $disk): bool
    {
        $last = $this->lastCheck($disk);

        return $last !== null && $last['problem'] === null;
    }

    /**
     * Runs the full probe in a separate process with a hard timeout and
     * caches the outcome (a hung or crashed probe counts as unavailable).
     * Only one physical check runs at a time: a concurrent call, or one made
     * while a previous probe is still stuck in the kernel (a process blocked
     * on CIFS cannot be killed), gets the last known result instead of
     * piling up another process on the share.
     */
    public function refresh(string $disk): ?string
    {
        $stuck = $this->stuckProbe($disk);

        if ($stuck !== null) {
            $this->record($disk, 'probe_stuck');

            return 'probe_stuck';
        }

        $lock = null;

        try {
            $store = $this->cache->getStore();
            $lock = $store instanceof LockProvider ? $store->lock(self::CACHE_PREFIX.'probe:'.$disk, (int) ceil($this->timeoutSeconds()) + 30) : null;

            if ($lock !== null && ! $lock->get()) {
                return $this->lastCheck($disk)['problem'] ?? 'probe_busy';
            }
        } catch (Throwable) {
            $lock = null;
        }

        try {
            $started = microtime(true);
            $problem = $this->runProbe($disk);
            $this->record($disk, $problem, (int) round((microtime(true) - $started) * 1000));

            return $problem;
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
                // It expires by itself.
            }
        }
    }

    public function record(string $disk, ?string $problem, ?int $latencyMs = null): void
    {
        try {
            $this->cache->put(self::CACHE_PREFIX.$disk, ['problem' => $problem, 'checked_at' => now()->getTimestamp(), 'latency_ms' => $latencyMs], $this->ttlSeconds());

            $history = $this->history($disk);
            $previous = $history === [] ? null : self::stateOf($history);
            $history[] = ['problem' => $problem, 'latency_ms' => $latencyMs, 'at' => now()->getTimestamp()];
            $history = array_slice($history, -self::HISTORY, self::HISTORY);
            $this->cache->put(self::CACHE_PREFIX.'history:'.$disk, $history, 86400);
            $state = self::stateOf($history);

            if ($state !== $previous) {
                $this->recordEvent($disk, $state, $previous, $problem, $latencyMs);
            }
        } catch (Throwable $exception) {
            Log::warning('Media mount health could not be cached.', ['disk' => $disk, 'exception' => $exception::class]);
        }
    }

    /**
     * Last probes (oldest first), at most HISTORY of them.
     *
     * @return list<array{problem: string|null, latency_ms: int|null, at: int}>
     */
    public function history(string $disk): array
    {
        try {
            $value = $this->cache->get(self::CACHE_PREFIX.'history:'.$disk);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($value)) {
            return [];
        }

        $history = [];

        foreach ($value as $entry) {
            if (is_array($entry) && is_int($entry['at'] ?? null)) {
                $history[] = [
                    'problem' => is_string($entry['problem'] ?? null) ? $entry['problem'] : null,
                    'latency_ms' => is_int($entry['latency_ms'] ?? null) ? $entry['latency_ms'] : null,
                    'at' => $entry['at'],
                ];
            }
        }

        return $history;
    }

    /**
     * State of the NAS from its last probes: "operational", "degraded" (it
     * answers, slowly), "intermittent" (it comes and goes), "disconnected"
     * (the last probes failed), "unmounted" (the share is not mounted or is
     * not the expected storage), "recovering" (it answers again after a
     * failure) or "unknown" (no probe yet).
     *
     * @param  list<array{problem: string|null, latency_ms: int|null, at: int}>  $history
     */
    public static function stateOf(array $history): string
    {
        if ($history === []) {
            return 'unknown';
        }

        $last = $history[count($history) - 1];
        $failures = count(array_filter($history, fn (array $entry): bool => $entry['problem'] !== null));

        if ($last['problem'] !== null) {
            if (in_array($last['problem'], ['not_mounted', 'wrong_filesystem', 'marker_missing', 'marker_mismatch', 'not_configured'], true)) {
                return 'unmounted';
            }

            $recent = array_slice($history, -3);

            return count($recent) >= 3 && count(array_filter($recent, fn (array $entry): bool => $entry['problem'] !== null)) === 3
                ? 'disconnected'
                : 'intermittent';
        }

        $previous = $history[count($history) - 2] ?? null;

        if ($previous !== null && $previous['problem'] !== null) {
            return 'recovering';
        }

        if ($failures >= 2) {
            return 'intermittent';
        }

        $slowMs = max(500, (int) config('attachments.storage.mount.slow_ms', 3000));

        return ($last['latency_ms'] ?? 0) > $slowMs ? 'degraded' : 'operational';
    }

    public function state(string $disk): string
    {
        return self::stateOf($this->history($disk));
    }

    private function recordEvent(string $disk, string $state, ?string $previous, ?string $problem, ?int $latencyMs): void
    {
        try {
            DB::table('media_health_events')->insert([
                'disk' => $disk,
                'state' => $state,
                'previous_state' => $previous,
                'problem' => $problem,
                'latency_ms' => $latencyMs,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // The incident history is informative only.
        }

        Log::info('Media mount state changed.', ['disk' => $disk, 'state' => $state, 'previous' => $previous, 'problem' => $problem]);
    }

    /**
     * Pid of an earlier probe that outlived its timeout and is still alive
     * (blocked in the kernel on the share), or null.
     */
    private function stuckProbe(string $disk): ?int
    {
        try {
            $pid = $this->cache->get(self::CACHE_PREFIX.'stuck:'.$disk);
        } catch (Throwable) {
            return null;
        }

        if (! is_int($pid) || $pid <= 0) {
            return null;
        }

        if (is_dir('/proc/'.$pid)) {
            return $pid;
        }

        try {
            $this->cache->forget(self::CACHE_PREFIX.'stuck:'.$disk);
        } catch (Throwable) {
            // Expires by itself.
        }

        return null;
    }

    /**
     * @return array{problem: string|null, checked_at: int, latency_ms: int|null}|null
     */
    public function lastCheck(string $disk): ?array
    {
        try {
            $value = $this->cache->get(self::CACHE_PREFIX.$disk);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($value) || ! array_key_exists('problem', $value) || ! is_int($value['checked_at'] ?? null)) {
            return null;
        }

        return [
            'problem' => is_string($value['problem']) ? $value['problem'] : null,
            'checked_at' => $value['checked_at'],
            'latency_ms' => is_int($value['latency_ms'] ?? null) ? $value['latency_ms'] : null,
        ];
    }

    public function forget(string $disk): void
    {
        try {
            $this->cache->forget(self::CACHE_PREFIX.$disk);
        } catch (Throwable) {
            // Nothing cached is the same as an expired result.
        }
    }

    public function ttlSeconds(): int
    {
        return max(10, (int) config('attachments.storage.mount.health_ttl_seconds', 90));
    }

    public function timeoutSeconds(): float
    {
        return max(1.0, (float) config('attachments.storage.mount.probe_timeout_seconds', 10));
    }

    private function runProbe(string $disk): ?string
    {
        $command = $this->command ?? [php_binary(), base_path('artisan'), 'storage:mount-health', $disk, '--probe', '--no-interaction'];

        $process = null;

        try {
            $process = new Process($command, base_path(), null, null, $this->timeoutSeconds());
            $process->run();
        } catch (ProcessTimedOutException) {
            Log::warning('Media mount health probe timed out.', ['disk' => $disk, 'timeout_seconds' => $this->timeoutSeconds()]);
            $this->rememberIfStuck($disk, $process);

            return 'probe_timeout';
        } catch (Throwable $exception) {
            Log::warning('Media mount health probe could not run.', ['disk' => $disk, 'exception' => $exception::class]);

            return 'probe_failed';
        }

        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
        $result = json_decode((string) end($lines), true);

        if (! is_array($result) || ! array_key_exists('problem', $result) || ($result['problem'] !== null && ! is_string($result['problem']))) {
            Log::warning('Media mount health probe gave no result.', ['disk' => $disk, 'exit_code' => $process->getExitCode()]);

            return 'probe_failed';
        }

        return $result['problem'];
    }

    /**
     * A probe killed on timeout may survive in uninterruptible sleep (state
     * D): its pid is remembered so no other probe is started on top of it.
     */
    private function rememberIfStuck(string $disk, ?Process $process): void
    {
        $pid = $process?->getPid();

        if ($pid === null || ! is_dir('/proc/'.$pid)) {
            return;
        }

        try {
            $this->cache->put(self::CACHE_PREFIX.'stuck:'.$disk, $pid, 3600);
        } catch (Throwable) {
            // Best effort.
        }

        Log::warning('Media mount health probe is stuck on the share.', ['disk' => $disk, 'pid' => $pid]);
    }
}
