<?php

namespace App\Console\Commands;

use App\Services\Operations\HealthCheck;
use App\Services\Operations\HealthStatus;
use App\Services\Operations\Preflight as ProductionPreflight;
use Illuminate\Console\Command;

/**
 * Read-only production preflight (see App\Services\Operations\Preflight).
 * Exit code 1 when any verification fails, so a deployment script can stop
 * before switching traffic; warnings do not change the exit code unless
 * --strict is given.
 */
class Preflight extends Command
{
    protected $signature = 'app:preflight
        {--json : Print the result as JSON}
        {--strict : Also exit with an error when there are warnings}';

    protected $description = 'Verify server dependencies and production configuration without modifying data';

    public function handle(ProductionPreflight $preflight): int
    {
        $checks = $preflight->run();
        $overall = HealthStatus::worst(array_map(fn (HealthCheck $check): HealthStatus => $check->status, $checks));
        $failed = $overall === HealthStatus::Fail || ($this->option('strict') && $overall === HealthStatus::Warning);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $overall->value,
                'environment' => config('app.env'),
                'checked_at' => now()->toIso8601String(),
                'checks' => array_map(fn (HealthCheck $check): array => $check->toArray(), $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['Estado', 'Verificación', 'Detalle'],
            array_map(fn (HealthCheck $check): array => [
                $this->badge($check->status),
                $check->label,
                $check->summary,
            ], $checks),
        );

        $counts = array_count_values(array_map(fn (HealthCheck $check): string => $check->status->value, $checks));
        $summary = sprintf(
            '%d correctas, %d advertencias, %d bloqueantes.',
            ($counts['ok'] ?? 0) + ($counts['skipped'] ?? 0),
            $counts['warning'] ?? 0,
            $counts['fail'] ?? 0,
        );

        $failed ? $this->error("Preflight NO superado: {$summary}") : $this->info("Preflight superado: {$summary}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function badge(HealthStatus $status): string
    {
        return match ($status) {
            HealthStatus::Ok => '<fg=green>CORRECTO</>',
            HealthStatus::Warning => '<fg=yellow>ADVERTENCIA</>',
            HealthStatus::Fail => '<fg=red>BLOQUEANTE</>',
            HealthStatus::Skipped => '<fg=gray>NO APLICA</>',
        };
    }
}
