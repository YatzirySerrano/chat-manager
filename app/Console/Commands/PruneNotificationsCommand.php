<?php

namespace App\Console\Commands;

use App\Notifications\PruneNotifications;
use Illuminate\Console\Command;

/**
 * Deletes old read notifications and expired deduplication receipts.
 */
class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete old read notifications and expired notification receipts';

    public function handle(PruneNotifications $prune): int
    {
        $result = $prune->handle();

        $this->info("Notificaciones eliminadas: {$result['notifications']}. Registros de deduplicación eliminados: {$result['receipts']}.");

        return self::SUCCESS;
    }
}
