<?php

namespace App\Console\Commands;

use App\Services\Storage\MediaDisk;
use App\Services\Storage\MountHealth;
use Illuminate\Console\Command;

/**
 * Health of a media disk mounted from a NAS (see MountHealth). Scheduled
 * every minute only while MEDIA_DISK is a mounted disk: it runs the full
 * probe in a separate process with a hard timeout and caches the result
 * that requests read before writing files.
 *
 * With --probe it is that separate process: it runs every check in place
 * (they may block on a dead share) and prints one JSON line
 * {"problem": null|"reason"} for the parent.
 */
class CheckMediaMount extends Command
{
    protected $signature = 'storage:mount-health
        {disk? : Mounted disk to check (default: MEDIA_DISK)}
        {--probe : Run the checks in this process and print the JSON result (used by the health refresh)}';

    protected $description = 'Check that the NAS share of the media disk is mounted, is the expected storage and accepts files';

    public function handle(MountHealth $health, MediaDisk $mediaDisk): int
    {
        $argument = $this->argument('disk');
        $disk = is_string($argument) && $argument !== '' ? $argument : $mediaDisk->name();

        if (! $mediaDisk->isMount($disk)) {
            if ($this->option('probe')) {
                $this->line((string) json_encode(['problem' => 'not_configured']));

                return self::FAILURE;
            }

            $this->info("El disco {$disk} no es un montaje de red: no hay nada que verificar.");

            return self::SUCCESS;
        }

        if ($this->option('probe')) {
            $problem = $health->fullProblem($disk);
            $this->line((string) json_encode(['problem' => $problem]));

            return $problem === null ? self::SUCCESS : self::FAILURE;
        }

        $problem = $health->refresh($disk);

        if ($problem !== null) {
            $this->error("El almacenamiento montado {$disk} no está disponible: ".MediaDisk::explain($problem).'.');

            return self::FAILURE;
        }

        $this->info("El almacenamiento montado {$disk} está disponible.");

        return self::SUCCESS;
    }
}
