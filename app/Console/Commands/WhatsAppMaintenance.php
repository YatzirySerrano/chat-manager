<?php

namespace App\Console\Commands;

use App\Actions\Messaging\PruneWebhookEvents;
use App\Actions\Messaging\ReconcileStuckDeliveries;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SyncConnectionTemplates;
use App\Models\Connection;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Operational tasks of the WhatsApp Cloud API integration (scheduled in
 * routes/console.php):
 *
 * - reconcile-deliveries: messages stuck "sending" become delivery_unknown;
 * - prune-webhooks: removes the raw webhook log after its retention;
 * - retry-webhooks: queues failed webhook deliveries again (manual review);
 * - recover-webhooks: queues again deliveries still waiting after
 *   RECOVER_AFTER_MINUTES whose job was lost (queue down at dispatch, worker
 *   killed), while they have attempts left, so nothing stays unprocessed;
 * - sync-templates: synchronizes the templates of every active connection.
 */
class WhatsAppMaintenance extends Command
{
    /** A delivery still waiting after this many minutes lost its job. */
    public const RECOVER_AFTER_MINUTES = 10;

    protected $signature = 'whatsapp:maintenance
        {task : reconcile-deliveries, prune-webhooks, retry-webhooks, recover-webhooks or sync-templates}
        {--connection= : sync-templates: only this connection id}';

    protected $description = 'WhatsApp Cloud API maintenance: reconcile deliveries, prune or retry webhooks, sync templates';

    public function handle(ReconcileStuckDeliveries $reconcile, PruneWebhookEvents $prune): int
    {
        switch ($this->argument('task')) {
            case 'reconcile-deliveries':
                $this->info('Mensajes sin confirmación marcados como delivery_unknown: '.$reconcile->handle());

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
                    ->active()
                    ->notDeleted()
                    ->when($this->option('connection'), fn ($query, $id) => $query->whereKey((int) $id))
                    ->pluck('id');

                foreach ($connections as $id) {
                    SyncConnectionTemplates::dispatch($id);
                }

                $this->info('Sincronizaciones de plantillas en cola: '.$connections->count());

                return self::SUCCESS;
            default:
                $this->error('Tarea desconocida. Usa reconcile-deliveries, prune-webhooks, retry-webhooks, recover-webhooks o sync-templates.');

                return self::FAILURE;
        }
    }
}
