<?php

namespace App\Console\Commands;

use App\Notifications\PruneNotifications;
use Illuminate\Console\Command;

/**
 * Deletes old read notifications, expired deduplication receipts and stale
 * Web Push subscriptions.
 */
class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete old read notifications and expired notification receipts';

    public function handle(PruneNotifications $prune): int
    {
        $result = $prune->handle();

        $this->info("Notificaciones eliminadas: {$result['notifications']}. Registros de deduplicación eliminados: {$result['receipts']}. Suscripciones push antiguas eliminadas: {$result['push_subscriptions']}.");

        return self::SUCCESS;
    }
}
