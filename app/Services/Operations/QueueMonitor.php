<?php

namespace App\Services\Operations;

use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * Size and age of every queue of the application (one per responsibility,
 * see App\Support\Queues), read through the queue driver itself so it works
 * with the database driver (default) and with Redis.
 *
 * @phpstan-type QueueStats array{
 *     responsibility: string, label: string, name: string, pending: int|null, delayed: int|null,
 *     reserved: int|null, oldest_pending_age: int|null, status: string, summary: string
 * }
 */
class QueueMonitor
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        Queues::WEBHOOKS => 'Webhooks de WhatsApp',
        Queues::MESSAGES => 'Mensajes salientes',
        Queues::MEDIA => 'Multimedia',
        Queues::NOTIFICATIONS => 'Notificaciones y tiempo real',
        Queues::EXPORTS => 'Exportaciones e importaciones',
        Queues::DEFAULT => 'General',
    ];

    public function __construct(private QueueFactory $queues) {}

    public function connectionName(): string
    {
        return (string) config('queue.default');
    }

    /**
     * Whether jobs are executed by workers (and therefore can pile up).
     */
    public function isAsynchronous(): bool
    {
        $driver = config('queue.connections.'.$this->connectionName().'.driver');

        return ! in_array($driver, ['sync', 'null', 'deferred', 'background'], true);
    }

    /**
     * Actual queue name of each responsibility. Exports and imports use
     * EXPORTS_QUEUE (config/exports.php), which may differ from the
     * "exports" name of the map.
     *
     * @return array<string, string>
     */
    public function queueNames(): array
    {
        $names = [];

        foreach (array_keys(self::LABELS) as $responsibility) {
            $names[$responsibility] = $responsibility === Queues::EXPORTS
                ? (string) (config('exports.queue') ?: Queues::name(Queues::EXPORTS))
                : Queues::name($responsibility);
        }

        return $names;
    }

    /**
     * Statistics per responsibility (two responsibilities mapped to the same
     * queue name share its numbers).
     *
     * @return list<QueueStats>
     */
    public function stats(): array
    {
        $stats = [];

        foreach ($this->queueNames() as $responsibility => $name) {
            $stats[] = $this->statsFor($responsibility, $name);
        }

        return $stats;
    }

    /**
     * @return QueueStats
     */
    private function statsFor(string $responsibility, string $name): array
    {
        $base = [
            'responsibility' => $responsibility,
            'label' => self::LABELS[$responsibility],
            'name' => $name,
            'pending' => null,
            'delayed' => null,
            'reserved' => null,
            'oldest_pending_age' => null,
        ];

        if (! $this->isAsynchronous()) {
            return [...$base, 'status' => HealthStatus::Skipped->value, 'summary' => 'La conexión de colas ejecuta los trabajos en el momento (sin workers).'];
        }

        try {
            $queue = $this->queues->connection($this->connectionName());
            $oldest = $queue->creationTimeOfOldestPendingJob($name);
            $stats = [
                ...$base,
                'pending' => (int) $queue->pendingSize($name),
                'delayed' => (int) $queue->delayedSize($name),
                'reserved' => (int) $queue->reservedSize($name),
                'oldest_pending_age' => is_numeric($oldest) ? max(0, CarbonImmutable::now()->getTimestamp() - (int) $oldest) : null,
            ];
        } catch (Throwable $exception) {
            return [...$base, 'status' => HealthStatus::Fail->value, 'summary' => 'No se pudo consultar la cola: '.SensitiveText::firstLine(class_basename($exception).': '.$exception->getMessage(), 160)];
        }

        [$status, $summary] = $this->evaluate($responsibility, $stats['pending'], $stats['oldest_pending_age']);

        return [...$stats, 'status' => $status->value, 'summary' => $summary];
    }

    /**
     * @return array{0: HealthStatus, 1: string}
     */
    private function evaluate(string $responsibility, int $pending, ?int $oldestAge): array
    {
        $limits = $this->thresholds($responsibility);
        $age = $oldestAge ?? 0;

        if ($pending >= $limits['critical_pending'] || $age >= $limits['critical_age']) {
            return [HealthStatus::Fail, "Acumulación crítica: {$pending} pendientes, el más antiguo espera {$age} s. Revisa que los workers de esta cola estén activos."];
        }

        if ($pending >= $limits['warning_pending'] || $age >= $limits['warning_age']) {
            return [HealthStatus::Warning, "Acumulación: {$pending} pendientes, el más antiguo espera {$age} s."];
        }

        return [HealthStatus::Ok, $pending === 0 ? 'Sin trabajos pendientes.' : "{$pending} pendientes en proceso normal."];
    }

    /**
     * @return array{warning_pending: int, critical_pending: int, warning_age: int, critical_age: int}
     */
    public function thresholds(string $responsibility): array
    {
        $defaults = (array) config('operations.queues.default', []);
        $override = (array) config("operations.queues.overrides.{$responsibility}", []);
        $merged = [...$defaults, ...$override];

        return [
            'warning_pending' => (int) ($merged['warning_pending'] ?? 500),
            'critical_pending' => (int) ($merged['critical_pending'] ?? 5000),
            'warning_age' => (int) ($merged['warning_age'] ?? 120),
            'critical_age' => (int) ($merged['critical_age'] ?? 900),
        ];
    }
}
