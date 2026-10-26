<?php

namespace App\Actions\Roles;

use App\Models\Role;

/**
 * Outcome of saving a role: whether anything changed and whom the change
 * reaches, so the editor can say it plainly (including when nobody holds
 * the role, or when some holders keep a revoked permission through another
 * role or a direct grant).
 */
final readonly class RoleSaveResult
{
    /**
     * @param  int  $activeHolders  Active accounts holding the role after the save.
     * @param  bool  $accessChanged  Permissions or scope changed (not only the name or description).
     * @param  int  $retainedUsers  Active holders who keep at least one revoked permission elsewhere.
     * @param  array<string, int>  $retainedPermissions  Holders keeping each revoked permission elsewhere.
     */
    public function __construct(
        public Role $role,
        public bool $changed,
        public bool $created,
        public bool $accessChanged = false,
        public int $activeHolders = 0,
        public int $retainedUsers = 0,
        public array $retainedPermissions = [],
    ) {}

    /**
     * Toast answering the save.
     *
     * @return array{type: 'success'|'info'|'warning', message: string}
     */
    public function toast(): array
    {
        if (! $this->changed) {
            return ['type' => 'info', 'message' => 'No había cambios por guardar.'];
        }

        $label = $this->role->label;

        if ($this->created) {
            return ['type' => 'success', 'message' => "Rol {$label} creado."];
        }

        if (! $this->accessChanged) {
            return ['type' => 'success', 'message' => "Rol {$label} actualizado."];
        }

        if ($this->activeHolders === 0) {
            return ['type' => 'warning', 'message' => "Rol {$label} actualizado. Este rol no está asignado a ningún usuario activo: el cambio no afectará a nadie todavía."];
        }

        $message = "Rol {$label} actualizado. El cambio ya se aplica a {$this->activeHolders} "
            .($this->activeHolders === 1 ? 'usuario activo.' : 'usuarios activos.');

        if ($this->retainedUsers === 0) {
            return ['type' => 'success', 'message' => $message];
        }

        $message .= ' '.$this->retainedUsers.' '
            .($this->retainedUsers === 1 ? 'usuario conserva' : 'usuarios conservan')
            .' alguno de los permisos retirados por otro rol o por una asignación directa.';

        return ['type' => 'warning', 'message' => $message];
    }
}
