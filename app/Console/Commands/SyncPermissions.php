<?php

namespace App\Console\Commands;

use App\Actions\Authorization\SyncRolesAndPermissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-permissions {--prune : Delete permissions that are no longer in the catalog}')]
#[Description('Add missing catalog permissions and the initial roles on a fresh install, never overwriting customized roles (safe on every deploy)')]
class SyncPermissions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SyncRolesAndPermissions $sync): int
    {
        $result = $sync->handle((bool) $this->option('prune'));

        $this->components->info(match (count($result['created_permissions'])) {
            0 => 'El catálogo de permisos ya estaba completo.',
            default => count($result['created_permissions']).' permisos agregados al catálogo.',
        });

        if ($result['granted_to_root'] !== []) {
            $this->components->info('Permisos nuevos otorgados a la autoridad raíz: '.implode(', ', $result['granted_to_root']).'.');
        }

        if ($result['created_roles'] !== []) {
            $this->components->info('Roles iniciales creados: '.implode(', ', $result['created_roles']).'.');
        }

        if ($result['obsolete_permissions'] !== []) {
            $list = implode(', ', $result['obsolete_permissions']);

            $result['pruned']
                ? $this->components->warn("Permisos obsoletos eliminados: {$list}.")
                : $this->components->warn("Permisos fuera del catálogo (usa --prune para eliminarlos): {$list}.");
        }

        $this->components->info($result['granted_to_root'] === []
            ? 'Los roles existentes y sus permisos no se modificaron.'
            : 'Los demás roles y sus permisos no se modificaron.');

        return self::SUCCESS;
    }
}
