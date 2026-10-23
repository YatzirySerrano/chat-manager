<?php

namespace App\Console\Commands;

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Authorization\UpgradeDefaultRoles;
use App\Enums\DefaultRole;
use App\Models\Role;
use App\Models\User;
use App\Support\ManualValidation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-permissions
    {--prune : Delete permissions that are no longer in the catalog}
    {--upgrade-default-roles : Show what the default roles (Administrador, Supervisor, Agente) lack from their current definition; nothing is written without --apply}
    {--apply : With --upgrade-default-roles: apply the shown changes, audited}
    {--demo-b : With --upgrade-default-roles: also upgrade the local "Demo B · Agente" role (agent definition)}
    {--include-sensitive : With --upgrade-default-roles: also grant the sensitive permissions of the definition}
    {--actor= : With --apply: e-mail of the active Superadministrador recorded as author (default: the first one)}')]
#[Description('Add missing catalog permissions and the initial roles on a fresh install, never overwriting customized roles (safe on every deploy); --upgrade-default-roles brings the default roles up to date on request')]
class SyncPermissions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SyncRolesAndPermissions $sync, UpgradeDefaultRoles $upgrade): int
    {
        if ($this->option('upgrade-default-roles')) {
            return $this->upgradeDefaultRoles($upgrade);
        }

        foreach (['apply', 'demo-b', 'include-sensitive', 'actor'] as $option) {
            if ($this->option($option)) {
                $this->components->error("--{$option} solo se usa junto con --upgrade-default-roles.");

                return self::FAILURE;
            }
        }

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

    /**
     * Preview (default) or apply the upgrade of the default roles.
     */
    private function upgradeDefaultRoles(UpgradeDefaultRoles $upgrade): int
    {
        $includeDemo = (bool) $this->option('demo-b');
        $includeSensitive = (bool) $this->option('include-sensitive');

        if ($this->option('prune')) {
            $this->components->error('--prune no se combina con --upgrade-default-roles.');

            return self::FAILURE;
        }

        $actor = null;

        if ($this->option('apply')) {
            $actor = $this->rootActor();

            if ($actor === null) {
                $this->components->error('No hay un Superadministrador activo con ese correo para registrar el cambio en la auditoría.');

                return self::FAILURE;
            }
        }

        $entries = $actor === null
            ? $upgrade->plan($includeDemo, $includeSensitive)
            : $upgrade->apply($actor, $includeDemo, $includeSensitive);

        $this->renderUpgrade($entries, $actor, $includeDemo);

        return self::SUCCESS;
    }

    /**
     * @param  list<array{role: Role, definition: DefaultRole, users: int, add: list<string>, remove: list<string>, held_back: list<string>}>  $entries
     * @param  User|null  $actor  Who applied the changes, or null for a preview.
     */
    private function renderUpgrade(array $entries, ?User $actor, bool $includeDemo): void
    {
        if ($entries === []) {
            $this->components->warn('No hay roles por defecto que actualizar.');
        }

        foreach ($entries as $entry) {
            $role = $entry['role'];
            $this->newLine();
            $this->line(sprintf(
                '<options=bold>%s</> (%s · %s · base: %s · %d %s)',
                $role->label,
                $role->name,
                $role->scope->label(),
                $entry['definition']->label(),
                $entry['users'],
                $entry['users'] === 1 ? 'usuario' : 'usuarios',
            ));
            $this->components->twoColumnDetail($actor !== null ? 'Añadidos' : 'Añadiría', $this->list($entry['add']));
            $this->components->twoColumnDetail($actor !== null ? 'Quitados' : 'Quitaría', $this->list($entry['remove']));

            if ($entry['held_back'] !== []) {
                $this->components->twoColumnDetail('Sensibles sin conceder (usa --include-sensitive)', $this->list($entry['held_back']));
            }
        }

        $this->newLine();

        if (! $includeDemo) {
            $this->line('El rol «'.ManualValidation::ROLE_LABEL.'» no se revisó (usa --demo-b).');
        }

        $this->line('La autoridad raíz, los roles personalizados y las credenciales no se modifican.');
        $this->components->info($actor !== null
            ? 'Cambios aplicados y registrados en la auditoría como «'.$actor->email.'».'
            : 'Vista previa: no se modificó nada. Ejecuta de nuevo con --apply para aplicar estos cambios.');
    }

    private function rootActor(): ?User
    {
        $email = $this->option('actor');

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('is_root', true))
            ->when(is_string($email) && $email !== '', fn ($query) => $query->where('email', $email))
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function list(array $permissions): string
    {
        return $permissions === [] ? '—' : implode(', ', $permissions);
    }
}
