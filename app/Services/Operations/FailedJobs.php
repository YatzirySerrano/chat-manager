<?php

namespace App\Services\Operations;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use stdClass;

/**
 * Read and administer the failed jobs table (queue.failed, driver
 * database-uuids). What leaves this class is always sanitized: the job
 * class, its queue, when it failed and the first line of the exception with
 * secrets masked. Payloads are never exposed (they may carry identifiers of
 * customers or encrypted commands).
 *
 * Retrying is limited to the idempotent job classes of
 * operations.failed_jobs.retryable and audited; discarding is allowed for
 * any job and audited too.
 *
 * @phpstan-type FailedJobRow array{
 *     uuid: string, job: string, job_class: string, queue: string, connection: string,
 *     failed_at: string|null, exception: string, retryable: bool
 * }
 */
class FailedJobs
{
    public function __construct(
        private ConnectionResolverInterface $database,
        private AuditLogger $audit,
    ) {}

    /**
     * Whether failed jobs are stored where this class can read them.
     */
    public function isAvailable(): bool
    {
        return config('queue.failed.driver') === 'database-uuids';
    }

    /**
     * @return LengthAwarePaginator<int, FailedJobRow>
     */
    public function paginate(int $perPage, ?string $queue = null): LengthAwarePaginator
    {
        $page = $this->table()
            ->select(['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->when($queue !== null && $queue !== '', fn (Builder $query) => $query->where('queue', $queue))
            ->orderByDesc('id')
            ->paginate($perPage);

        $rows = array_map(fn (stdClass $row): array => $this->present($row), array_values($page->items()));

        return new LengthAwarePaginator($rows, $page->total(), $page->perPage(), $page->currentPage(), [
            'path' => $page->path(),
            'pageName' => $page->getPageName(),
        ]);
    }

    public function count(?CarbonImmutable $since = null): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        return $this->table()
            ->when($since !== null, fn (Builder $query) => $query->where('failed_at', '>=', $since))
            ->count();
    }

    /**
     * @return list<string>
     */
    public function queues(): array
    {
        return array_values($this->table()->distinct()->orderBy('queue')->pluck('queue')->map(fn (mixed $queue): string => (string) $queue)->all());
    }

    /**
     * @return FailedJobRow|null
     */
    public function find(string $uuid): ?array
    {
        $row = $this->table()->where('uuid', $uuid)->first(['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        return $row instanceof stdClass ? $this->present($row) : null;
    }

    public function isRetryable(string $jobClass): bool
    {
        foreach ((array) config('operations.failed_jobs.retryable', []) as $allowed) {
            $allowed = (string) $allowed;

            if ($allowed !== '' && (str_ends_with($allowed, '\\') ? str_starts_with($jobClass, $allowed) : $jobClass === $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send an allowlisted failed job back to its original queue (attempts
     * reset) and remove it from the failed list. A lock makes two clicks or
     * two administrators push it only once.
     *
     * @throws FailedJobActionRefused
     * @throws LockTimeoutException
     */
    public function retry(string $uuid, User $actor): void
    {
        Cache::lock("operations:failed-job:{$uuid}", 30)->block(5, function () use ($uuid, $actor): void {
            $job = $this->find($uuid) ?? throw new FailedJobActionRefused('El trabajo ya no está en la lista de fallidos (otra persona lo reintentó o lo descartó).');

            if (! $this->isRetryable($job['job_class'])) {
                throw new FailedJobActionRefused('Este tipo de trabajo no se puede reintentar desde aquí porque repetirlo podría duplicar efectos (por ejemplo, un mensaje que quizá ya llegó a Meta). Usa la acción propia del módulo o descártalo.');
            }

            Artisan::call('queue:retry', ['id' => [$uuid]]);

            if ($this->table()->where('uuid', $uuid)->exists()) {
                throw new FailedJobActionRefused('La cola no aceptó el trabajo; sigue en la lista de fallidos.');
            }

            $this->audit->record(AuditAction::SystemJobRetried, null, $this->auditProperties($job), $actor);
        });
    }

    /**
     * Delete a failed job without running it again.
     *
     * @throws FailedJobActionRefused
     */
    public function forget(string $uuid, User $actor): void
    {
        $job = $this->find($uuid) ?? throw new FailedJobActionRefused('El trabajo ya no está en la lista de fallidos.');

        if ($this->table()->where('uuid', $uuid)->delete() === 0) {
            throw new FailedJobActionRefused('El trabajo ya no está en la lista de fallidos.');
        }

        $this->audit->record(AuditAction::SystemJobForgotten, null, $this->auditProperties($job), $actor);
    }

    /**
     * @param  FailedJobRow  $job
     * @return array<string, string|null>
     */
    private function auditProperties(array $job): array
    {
        return [
            'uuid' => $job['uuid'],
            'job' => $job['job_class'],
            'queue' => $job['queue'],
            'failed_at' => $job['failed_at'],
        ];
    }

    /**
     * @return FailedJobRow
     */
    private function present(stdClass $row): array
    {
        $payload = json_decode((string) $row->payload, true);
        $class = is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'Desconocido';
        $failedAt = $row->failed_at === null ? null : CarbonImmutable::parse((string) $row->failed_at)->toIso8601String();

        return [
            'uuid' => (string) $row->uuid,
            'job' => class_basename($class),
            'job_class' => $class,
            'queue' => (string) $row->queue,
            'connection' => (string) $row->connection,
            'failed_at' => $failedAt,
            'exception' => self::describeException((string) $row->exception),
            'retryable' => $this->isRetryable($class),
        ];
    }

    /**
     * "Class: message" of the first exception line, without the file path
     * and with secrets masked.
     */
    public static function describeException(string $exception): string
    {
        $first = trim((string) strtok(str_replace("\r", "\n", $exception), "\n"));
        $first = (string) preg_replace('/\s+in\s+\/\S+:\d+$/', '', $first);

        if (preg_match('/^([A-Za-z0-9_\\\\]+)(:\s*(.*))?$/s', $first, $matches) === 1) {
            $first = class_basename($matches[1]).(isset($matches[3]) && $matches[3] !== '' ? ': '.$matches[3] : '');
        }

        return SensitiveText::scrub(Str::of($first)->trim()->toString(), 300);
    }

    private function table(): Builder
    {
        return $this->database
            ->connection(config('queue.failed.database'))
            ->table((string) config('queue.failed.table', 'failed_jobs'));
    }
}
