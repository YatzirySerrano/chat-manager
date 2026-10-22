<?php

namespace App\Console\Commands;

use App\Actions\Exports\PruneExpiredTransfers;
use Illuminate\Console\Command;

/**
 * Removes the generated files whose retention window ended.
 */
class PruneExpiredExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete expired export files and mark their transfers as expired';

    public function handle(PruneExpiredTransfers $prune): int
    {
        $this->info("Exportaciones expiradas: {$prune->handle()}");

        return self::SUCCESS;
    }
}
