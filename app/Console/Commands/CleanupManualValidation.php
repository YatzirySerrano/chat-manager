<?php

namespace App\Console\Commands;

use App\Support\ManualValidation;
use App\Support\ManualValidationCleanup;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Local development tool: removes only the fictitious records of the manual
 * validation scenario. Without --confirm it only shows what it would remove.
 */
class CleanupManualValidation extends Command
{
    protected $signature = 'chat-manager:demo-cleanup
        {--confirm : Remove the records (without it, only the plan is shown)}';

    protected $description = 'LOCAL ONLY: remove the fictitious data of the Demo B manual validation scenario';

    public function isHidden(): bool
    {
        return ! app()->isLocal();
    }

    public function handle(ManualValidationCleanup $cleanup): int
    {
        try {
            ManualValidation::ensureAllowed();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $plan = $this->option('confirm') ? $cleanup->execute() : $cleanup->plan();

        $this->table(['Registros demo', 'Cantidad', 'Ids'], array_map(
            fn (string $kind, array $ids): array => [$kind, count($ids), implode(', ', $ids)],
            array_keys($plan['remove']),
            $plan['remove'],
        ));

        foreach ($plan['detach'] as $detach) {
            $this->warn("Se desvincularán: {$detach}.");
        }

        if ($plan['blockers'] !== []) {
            foreach ($plan['blockers'] as $blocker) {
                $this->error($blocker);
            }

            $this->error('No se eliminó nada.');

            return self::FAILURE;
        }

        $this->info($this->option('confirm')
            ? 'Datos de la validación Demo B eliminados. Los datos reales y la auditoría se conservan.'
            : 'Vista previa: no se eliminó nada. Ejecuta de nuevo con --confirm para eliminar estos registros.');

        return self::SUCCESS;
    }
}
