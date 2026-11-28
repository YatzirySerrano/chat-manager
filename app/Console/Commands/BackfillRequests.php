<?php

namespace App\Console\Commands;

use App\Services\Conversations\RequestBackfill;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Completes the request data and the results of the requests attended
 * before the automatic captures existed (RequestBackfill): the request the
 * field manager wrote becomes «Datos de la solicitud», and a completed
 * request without a result takes the one the team announced.
 *
 * Without --apply it is a dry run: it prints what would change (figures
 * and examples) and writes nothing. Idempotent: a second run finds nothing
 * left to do. Only live conversations with activity since --since (default
 * the last 30 days), never imported history nor «No es solicitud»; nothing
 * stored is ever replaced.
 */
class BackfillRequests extends Command
{
    protected $signature = 'requests:backfill
        {--dry-run : Only show what would change, without writing anything (default)}
        {--apply : Write the changes}
        {--since= : Conversations with activity since this date (YYYY-MM-DD; default: 30 days ago)}';

    protected $description = 'Complete the request data and the credit results of past requests from their messages';

    public function handle(RequestBackfill $backfill): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Usa --dry-run o --apply, no ambos.');

            return self::INVALID;
        }

        $since = $this->since();

        if ($since === null) {
            $this->error('--since debe ser una fecha YYYY-MM-DD.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');

        $this->info(($apply ? 'Aplicando' : 'Simulación (no se escribe nada)').': conversaciones con actividad desde '.$since->toDateString().'.');

        $bar = $this->output->createProgressBar($backfill->candidates($since)->count());
        $result = $backfill->run($since, $apply, fn (int $chunk) => $bar->advance($chunk));
        $bar->finish();
        $this->newLine(2);

        $stats = $result['stats'];
        $verb = $apply ? 'Actualizadas' : 'Se actualizarían';

        $this->table(['Concepto', $verb], [
            ['Conversaciones revisadas', $stats['reviewed']],
            ['Datos de la solicitud capturados (cliente)', $stats['fields']],
            ['Grupo agregado a una solicitud grupal', $stats['groups']],
            ['Campos capturados en total', $stats['field_values']],
            ['Resultado: crédito aprobado', $stats['approved']],
            ['  … con monto autorizado', $stats['approved_with_amount']],
            ['Resultado: rechazada', $stats['rejected']],
            ['Datos de resolución completados', $stats['resolution']],
        ]);

        if ($result['samples'] !== []) {
            $this->newLine();
            $this->line('Ejemplos:');
            $this->table(['Conversación', 'Cambio', 'Detalle'], array_map(
                fn (array $sample): array => [$sample['conversation_id'], $sample['change'], $sample['detail']],
                $result['samples'],
            ));
        }

        if (! $apply) {
            $this->comment('Nada se escribió. Ejecuta con --apply para guardar los cambios.');
        }

        return self::SUCCESS;
    }

    private function since(): ?CarbonImmutable
    {
        $option = $this->option('since');

        if ($option === null || $option === '') {
            return CarbonImmutable::now()->subDays(30)->startOfDay();
        }

        if (! is_string($option) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $option) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $option) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
