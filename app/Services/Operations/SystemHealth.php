<?php

namespace App\Services\Operations;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Health of the running application: database, cache, storage, queues,
 * failed jobs, scheduler, WhatsApp webhook backlog and the real-time server.
 *
 * Every check is read-only and bounded (a handful of indexed queries and at
 * most one TCP connection), so it can back the public readiness endpoint,
 * the system page and the alerts. Readiness fails only on conditions that
 * stop the service (database, cache, storage, critical queue or webhook
 * backlog, stopped scheduler); the rest are warnings.
 */
class SystemHealth
{
    public function __construct(
        private ConnectionResolverInterface $database,
        private Cache $cache,
        private QueueMonitor $queues,
        private FailedJobs $failedJobs,
        private SchedulerHeartbeat $heartbeat,
    ) {}

    /**
     * @return list<HealthCheck>
     */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->cache(),
            $this->storage(),
            $this->queueBacklog(),
            $this->failedJobsCheck(),
            $this->scheduler(),
            $this->webhookBacklog(),
            $this->realtime(),
        ];
    }

    /**
     * @param  list<HealthCheck>|null  $checks
     */
    public function overall(?array $checks = null): HealthStatus
    {
        return HealthStatus::worst(array_map(fn (HealthCheck $check): HealthStatus => $check->status, $checks ?? $this->checks()));
    }

    public function database(): HealthCheck
    {
        try {
            $connection = $this->database->connection();
            $version = (string) $connection->selectOne('select version() as version')?->version;

            return HealthCheck::ok('database', 'Base de datos', 'Conexión correcta.', ['driver' => (string) config('database.connections.'.config('database.default').'.driver'), 'version' => $version]);
        } catch (Throwable $exception) {
            return HealthCheck::fail('database', 'Base de datos', 'Sin conexión a la base de datos ('.class_basename($exception).').');
        }
    }

    public function cache(): HealthCheck
    {
        try {
            $this->cache->get('operations:health-probe');

            return HealthCheck::ok('cache', 'Caché', 'El almacén de caché responde.', ['store' => (string) config('cache.default')]);
        } catch (Throwable $exception) {
            return HealthCheck::fail('cache', 'Caché', 'El almacén de caché no responde ('.class_basename($exception).').', ['store' => (string) config('cache.default')]);
        }
    }

    public function storage(): HealthCheck
    {
        $notWritable = array_values(array_filter(
            self::writablePaths(),
            fn (string $path): bool => ! is_dir($path) || ! is_writable($path),
        ));

        if ($notWritable !== []) {
            return HealthCheck::fail('storage', 'Almacenamiento', 'Sin permiso de escritura en: '.implode(', ', array_map(fn (string $path): string => str_replace(base_path().'/', '', $path), $notWritable)).'.');
        }

        return HealthCheck::ok('storage', 'Almacenamiento', 'Los directorios de la aplicación admiten escritura.');
    }

    /**
     * Directories the application writes to at runtime.
     *
     * @return list<string>
     */
    public static function writablePaths(): array
    {
        return [
            storage_path('app'),
            storage_path('app/private'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];
    }

    public function queueBacklog(): HealthCheck
    {
        if (! $this->queues->isAsynchronous()) {
            return HealthCheck::skipped('queues', 'Colas', 'La conexión "'.$this->queues->connectionName().'" ejecuta los trabajos en el momento; no hay workers que supervisar.');
        }

        $stats = $this->queues->stats();
        $status = HealthStatus::worst(array_map(fn (array $queue): HealthStatus => HealthStatus::from($queue['status']), $stats));
        $pending = array_sum(array_map(fn (array $queue): int => (int) $queue['pending'], $stats));
        $troubled = array_values(array_filter($stats, fn (array $queue): bool => in_array($queue['status'], [HealthStatus::Warning->value, HealthStatus::Fail->value], true)));

        $summary = $troubled === []
            ? "Colas al día ({$pending} trabajos pendientes en total)."
            : implode(' ', array_map(fn (array $queue): string => "{$queue['label']} ({$queue['name']}): {$queue['summary']}", $troubled));

        return new HealthCheck('queues', 'Colas', $status, $summary, ['connection' => $this->queues->connectionName(), 'pending' => $pending]);
    }

    public function failedJobsCheck(): HealthCheck
    {
        if (! $this->failedJobs->isAvailable()) {
            return HealthCheck::warning('failed_jobs', 'Trabajos fallidos', 'Los trabajos fallidos no se guardan en la base de datos (QUEUE_FAILED_DRIVER); no se pueden revisar desde aquí.');
        }

        try {
            $recent = $this->failedJobs->count(CarbonImmutable::now()->subDay());
            $total = $this->failedJobs->count();
        } catch (Throwable $exception) {
            return HealthCheck::fail('failed_jobs', 'Trabajos fallidos', 'No se pudo consultar la tabla de trabajos fallidos ('.class_basename($exception).').');
        }

        $details = ['last_24h' => $recent, 'total' => $total];

        if ($recent >= max(1, (int) config('operations.failed_jobs.warning_count', 1))) {
            return HealthCheck::warning('failed_jobs', 'Trabajos fallidos', "{$recent} trabajos fallaron en las últimas 24 horas ({$total} en la lista). Revísalos y reintenta o descarta.", $details);
        }

        return HealthCheck::ok('failed_jobs', 'Trabajos fallidos', $total === 0 ? 'No hay trabajos fallidos.' : "Sin fallas recientes ({$total} antiguos en la lista).", $details);
    }

    public function scheduler(): HealthCheck
    {
        $age = $this->heartbeat->ageInSeconds();
        $limit = $this->heartbeat->staleAfterSeconds();

        if ($age === null) {
            return HealthCheck::warning('scheduler', 'Tareas programadas', 'Aún no hay registro del programador. Configura schedule:work (Supervisor) o la línea de cron de schedule:run.', ['age_seconds' => null]);
        }

        if ($age > $limit) {
            return HealthCheck::fail('scheduler', 'Tareas programadas', "El programador no se ejecuta desde hace {$age} s (límite {$limit} s): no se cierran conversaciones inactivas ni se reconcilian entregas.", ['age_seconds' => $age]);
        }

        return HealthCheck::ok('scheduler', 'Tareas programadas', "Última ejecución hace {$age} s.", ['age_seconds' => $age]);
    }

    public function webhookBacklog(): HealthCheck
    {
        try {
            if (! class_exists(WebhookEvent::class) || ! Schema::hasTable((new WebhookEvent)->getTable())) {
                return HealthCheck::skipped('webhooks', 'Webhooks de WhatsApp', 'El registro de webhooks no está instalado.');
            }

            $grace = max(10, (int) config('operations.webhooks.grace_seconds', 120));
            $backlog = WebhookEvent::query()
                ->where('status', WebhookEventStatus::Received)
                ->where('received_at', '<', CarbonImmutable::now()->subSeconds($grace))
                ->count();
            $failed = WebhookEvent::query()
                ->where('status', WebhookEventStatus::Failed)
                ->where('updated_at', '>=', CarbonImmutable::now()->subDay())
                ->count();
        } catch (Throwable $exception) {
            return HealthCheck::fail('webhooks', 'Webhooks de WhatsApp', 'No se pudo consultar el registro de webhooks ('.class_basename($exception).').');
        }

        $details = ['backlog' => $backlog, 'failed_24h' => $failed];

        if ($backlog >= (int) config('operations.webhooks.critical_backlog', 1000)) {
            return HealthCheck::fail('webhooks', 'Webhooks de WhatsApp', "{$backlog} entregas de Meta llevan más de {$grace} s sin procesarse. Revisa el worker de la cola de webhooks.", $details);
        }

        if ($backlog >= (int) config('operations.webhooks.warning_backlog', 50) || $failed > 0) {
            return HealthCheck::warning('webhooks', 'Webhooks de WhatsApp', "{$backlog} entregas atrasadas y {$failed} con error en las últimas 24 horas.", $details);
        }

        return HealthCheck::ok('webhooks', 'Webhooks de WhatsApp', 'Las entregas de Meta se procesan al día.', $details);
    }

    public function realtime(): HealthCheck
    {
        if (config('broadcasting.default') !== 'reverb') {
            return HealthCheck::skipped('realtime', 'Tiempo real (Reverb)', 'Tiempo real desactivado (BROADCAST_CONNECTION='.config('broadcasting.default').'); las pantallas se actualizan periódicamente.');
        }

        [$host, $port] = $this->reverbAddress();
        $timeout = (float) config('operations.reverb_probe.timeout', 1.5);
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, $timeout);

        if ($socket === false) {
            return HealthCheck::warning('realtime', 'Tiempo real (Reverb)', "El servidor de Reverb no responde en {$host}:{$port}. Las pantallas siguen funcionando con actualización periódica.", ['host' => $host, 'port' => $port]);
        }

        fclose($socket);

        return HealthCheck::ok('realtime', 'Tiempo real (Reverb)', "Servidor de Reverb disponible en {$host}:{$port}.", ['host' => $host, 'port' => $port]);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function reverbAddress(): array
    {
        $host = (string) (config('operations.reverb_probe.host') ?: config('reverb.servers.reverb.host', '127.0.0.1'));
        $port = (int) (config('operations.reverb_probe.port') ?: config('reverb.servers.reverb.port', 8080));

        return [in_array($host, ['0.0.0.0', '::', '[::]', ''], true) ? '127.0.0.1' : $host, $port];
    }
}
