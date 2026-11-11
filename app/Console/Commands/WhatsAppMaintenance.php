<?php

namespace App\Console\Commands;

use App\Actions\Connections\CheckConnectionWithMeta;
use App\Actions\Messaging\PruneWebhookEvents;
use App\Actions\Messaging\ReconcileStuckDeliveries;
use App\Enums\ConnectionChannel;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SyncConnectionTemplates;
use App\Models\Connection;
use App\Models\WebhookEvent;
use App\Services\Meta\ConnectionMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operational tasks of the WhatsApp Cloud API integration (scheduled in
 * routes/console.php):
 *
 * - reconcile-deliveries: messages stuck "sending" become delivery_unknown
 *   (web chat replies are queued again: their outcome is never ambiguous);
 * - prune-webhooks: removes the raw webhook log after its retention;
 * - retry-webhooks: queues failed webhook deliveries again (manual review);
 * - recover-webhooks: queues again deliveries still waiting after
 *   RECOVER_AFTER_MINUTES whose job was lost (queue down at dispatch, worker
 *   killed), while they have attempts left, so nothing stays unprocessed;
 * - sync-templates: synchronizes the templates of every active connection;
 * - check-connections: asks Meta again whether the credentials and the
 *   webhook subscription of the active, configured connections (WhatsApp,
 *   Messenger, Instagram) still work, the ones checked longest ago first and
 *   at most whatsapp.monitoring.max_per_run per run. A failure on one
 *   connection never stops the others; when Meta throttles, the run ends and
 *   the rest waits for the next one. Meta being unreachable is never
 *   recorded as a credential failure (CheckConnectionWithMeta).
 */
class WhatsAppMaintenance extends Command
{
    /** A delivery still waiting after this many minutes lost its job. */
    public const RECOVER_AFTER_MINUTES = 10;

    /** Longest a run of check-connections may hold its lock. */
    private const CHECK_LOCK_SECONDS = 900;

    protected $signature = 'whatsapp:maintenance
        {task : reconcile-deliveries, prune-webhooks, retry-webhooks, recover-webhooks, sync-templates or check-connections}
        {--connection= : sync-templates and check-connections: only this connection id}';

    protected $description = 'WhatsApp Cloud API maintenance: reconcile deliveries, prune or retry webhooks, sync templates, check connections';

    public function handle(ReconcileStuckDeliveries $reconcile, PruneWebhookEvents $prune, CheckConnectionWithMeta $check): int
    {
        switch ($this->argument('task')) {
            case 'reconcile-deliveries':
                $this->info('Mensajes sin confirmación reconciliados (delivery_unknown, o reencolados si son del chat web): '.$reconcile->handle());

                return self::SUCCESS;
            case 'prune-webhooks':
                $this->info('Eventos de webhook eliminados por retención: '.$prune->handle());

                return self::SUCCESS;
            case 'retry-webhooks':
                $count = 0;

                WebhookEvent::query()->where('status', WebhookEventStatus::Failed)->select('id')->chunkById(200, function ($events) use (&$count): void {
                    foreach ($events as $event) {
                        WebhookEvent::query()->whereKey($event->id)->update(['status' => WebhookEventStatus::Received, 'updated_at' => now()]);
                        ProcessWhatsAppWebhook::dispatch($event->id);
                        $count++;
                    }
                });

                $this->info("Eventos de webhook en cola nuevamente: {$count}");

                return self::SUCCESS;
            case 'recover-webhooks':
                $count = 0;

                WebhookEvent::query()
                    ->where('status', WebhookEventStatus::Received)
                    ->where('updated_at', '<', now()->subMinutes(self::RECOVER_AFTER_MINUTES))
                    ->where('attempts', '<', max(1, (int) config('whatsapp.webhooks.tries', 5)))
                    ->select('id')
                    ->chunkById(200, function ($events) use (&$count): void {
                        foreach ($events as $event) {
                            WebhookEvent::query()->whereKey($event->id)->update(['updated_at' => now()]);
                            ProcessWhatsAppWebhook::dispatch($event->id);
                            $count++;
                        }
                    });

                $this->info("Eventos de webhook pendientes recuperados: {$count}");

                return self::SUCCESS;
            case 'sync-templates':
                $connections = Connection::query()
                    ->where('channel', ConnectionChannel::Whatsapp)
                    ->active()
                    ->notDeleted()
                    ->when($this->option('connection'), fn ($query, $id) => $query->whereKey((int) $id))
                    ->pluck('id');

                foreach ($connections as $id) {
                    SyncConnectionTemplates::dispatch($id);
                }

                $this->info('Sincronizaciones de plantillas en cola: '.$connections->count());

                return self::SUCCESS;
            case 'check-connections':
                return $this->checkConnections($check);
            default:
                $this->error('Tarea desconocida. Usa reconcile-deliveries, prune-webhooks, retry-webhooks, recover-webhooks, sync-templates o check-connections.');

                return self::FAILURE;
        }
    }

    /**
     * One run at a time (also when started by hand while the scheduled one
     * works): two runs would double the calls to Meta for nothing.
     */
    private function checkConnections(CheckConnectionWithMeta $check): int
    {
        $lock = Cache::lock('whatsapp:maintenance:check-connections', self::CHECK_LOCK_SECONDS);

        if (! $lock->get()) {
            $this->info('Ya hay una comprobación de conexiones en curso.');

            return self::SUCCESS;
        }

        try {
            $limit = max(1, (int) config('whatsapp.monitoring.max_per_run', 50));
            $connections = Connection::query()
                ->whereIn('channel', [ConnectionChannel::Whatsapp, ConnectionChannel::Messenger, ConnectionChannel::Instagram])
                ->active()
                ->notDeleted()
                ->whereNotNull('access_token')
                ->when($this->option('connection'), fn ($query, $id) => $query->whereKey((int) $id))
                ->orderBy('id')
                ->get()
                ->sortBy(fn (Connection $connection): string => (string) ($connection->provider_signals[ConnectionMonitor::CHECK_SIGNAL]['at'] ?? ''))
                ->take($limit);
            $outcomes = [];

            foreach ($connections as $connection) {
                try {
                    $outcome = $check->handle($connection);
                } catch (Throwable $exception) {
                    // One connection failing never stops the others.
                    Log::error('Automatic connection check failed.', ['connection_id' => $connection->id, 'exception' => $exception::class]);
                    $outcome = 'error';
                }

                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;

                if ($outcome === CheckConnectionWithMeta::RATE_LIMITED) {
                    Log::warning('Automatic connection check stopped: Meta is throttling requests.', ['connection_id' => $connection->id]);

                    break;
                }
            }

            $this->info('Conexiones comprobadas con Meta: '.collect($outcomes)->map(fn (int $count, string $outcome): string => "{$outcome}={$count}")->implode(', '));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
