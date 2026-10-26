<?php

namespace App\Console\Commands;

use App\Actions\HistoryImport\PruneHistoryImports as PruneAction;
use Illuminate\Console\Command;

/**
 * Retention of the Whaticket history importer (stored files and staging).
 */
class PruneHistoryImports extends Command
{
    protected $signature = 'history-import:prune';

    protected $description = 'Delete the files and staging rows of expired Whaticket history import batches';

    public function handle(PruneAction $prune): int
    {
        $this->info("Importaciones de historial vencidas: {$prune->handle()}");

        return self::SUCCESS;
    }
}
