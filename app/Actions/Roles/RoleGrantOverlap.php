<?php

namespace App\Actions\Roles;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Active holders of a role who also receive some of its permissions from
 * somewhere else: a permission granted directly to the account or another
 * role the account holds. Revoking such a permission from the role does not
 * take it away from them, and the roles screen says so instead of pretending
 * that editing a role removes every possible grant.
 */
class RoleGrantOverlap
{
    /**
     * For each role, how many active holders keep each of the role's own
     * permissions through another source. Only non-zero counts are listed.
     * One aggregate query whatever the number of roles or holders.
     *
     * @param  list<int>  $roleIds
     * @return array<int, array<string, int>>
     */
    public function retainedElsewhere(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $own = $this->table('role_has_permissions');
        $rows = DB::query()
            ->fromSub($this->directGrants($roleIds)->unionAll($this->otherRoleGrants($roleIds)), 'source')
            ->join($own.' as own', fn (JoinClause $join): JoinClause => $join
                ->on('own.'.$this->pivotRole(), '=', 'source.role_id')
                ->on('own.'.$this->pivotPermission(), '=', 'source.permission_id'))
            ->groupBy('source.role_id', 'source.name')
            ->get(['source.role_id', 'source.name', DB::raw('count(distinct source.user_id) as users')]);

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->role_id][(string) $row->name] = (int) $row->users;
        }

        foreach ($counts as $roleId => $byPermission) {
            ksort($byPermission);
            $counts[$roleId] = $byPermission;
        }

        return $counts;
    }

    /**
     * After permissions were revoked from the role (and the permission cache
     * refreshed), the active holders who can still exercise them, checked
     * exactly as authorization does. Only accounts with another source of
     * permissions are evaluated.
     *
     * @param  list<string>  $revoked
     * @return array{users: int, permissions: array<string, int>}
     */
    public function retainedAfterRevocation(Role $role, array $revoked): array
    {
        $revoked = array_values(array_filter($revoked, fn (string $name): bool => Permission::tryFrom($name) !== null));

        if ($revoked === []) {
            return ['users' => 0, 'permissions' => []];
        }

        $candidates = $this->directGrants([$role->id])->get()
            ->merge($this->otherRoleGrants([$role->id])->get())
            ->filter(fn (object $row): bool => in_array($row->name, $revoked, true))
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($candidates === []) {
            return ['users' => 0, 'permissions' => []];
        }

        $holders = User::query()->active()->whereKey($candidates)->with(['roles.permissions', 'permissions'])->get();
        $permissions = [];
        $users = 0;

        foreach ($holders as $holder) {
            $keeps = false;

            foreach ($revoked as $name) {
                if ($holder->holdsPermission(Permission::from($name))) {
                    $permissions[$name] = ($permissions[$name] ?? 0) + 1;
                    $keeps = true;
                }
            }

            $users += $keeps ? 1 : 0;
        }

        ksort($permissions);

        return ['users' => $users, 'permissions' => $permissions];
    }

    /**
     * (role_id, user_id, permission_id, name) of permissions granted directly to active
     * holders of the roles.
     *
     * @param  list<int>  $roleIds
     */
    private function directGrants(array $roleIds): Builder
    {
        return $this->activeHolders($roleIds)
            ->join($this->table('model_has_permissions').' as direct', fn (JoinClause $join): JoinClause => $join
                ->on('direct.'.$this->morphKey(), '=', 'holder.'.$this->morphKey())
                ->on('direct.model_type', '=', 'holder.model_type'))
            ->join($this->table('permissions').' as permission', 'permission.id', '=', 'direct.'.$this->pivotPermission())
            ->select(['holder.'.$this->pivotRole().' as role_id', 'holder.'.$this->morphKey().' as user_id', 'permission.id as permission_id', 'permission.name']);
    }

    /**
     * (role_id, user_id, permission_id, name) of permissions active holders of the roles
     * receive through another role they also hold.
     *
     * @param  list<int>  $roleIds
     */
    private function otherRoleGrants(array $roleIds): Builder
    {
        return $this->activeHolders($roleIds)
            ->join($this->table('model_has_roles').' as other', fn (JoinClause $join): JoinClause => $join
                ->on('other.'.$this->morphKey(), '=', 'holder.'.$this->morphKey())
                ->on('other.model_type', '=', 'holder.model_type')
                ->on('other.'.$this->pivotRole(), '<>', 'holder.'.$this->pivotRole()))
            ->join($this->table('role_has_permissions').' as granted', 'granted.'.$this->pivotRole(), '=', 'other.'.$this->pivotRole())
            ->join($this->table('permissions').' as permission', 'permission.id', '=', 'granted.'.$this->pivotPermission())
            ->select(['holder.'.$this->pivotRole().' as role_id', 'holder.'.$this->morphKey().' as user_id', 'permission.id as permission_id', 'permission.name']);
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function activeHolders(array $roleIds): Builder
    {
        return DB::table($this->table('model_has_roles').' as holder')
            ->join('users', 'users.id', '=', 'holder.'.$this->morphKey())
            ->where('holder.model_type', (new User)->getMorphClass())
            ->whereIn('holder.'.$this->pivotRole(), $roleIds)
            ->where('users.is_active', true);
    }

    private function table(string $name): string
    {
        $table = config("permission.table_names.{$name}", $name);

        return is_string($table) ? $table : $name;
    }

    private function morphKey(): string
    {
        $key = config('permission.column_names.model_morph_key', 'model_id');

        return is_string($key) ? $key : 'model_id';
    }

    private function pivotRole(): string
    {
        $key = config('permission.column_names.role_pivot_key');

        return is_string($key) && $key !== '' ? $key : 'role_id';
    }

    private function pivotPermission(): string
    {
        $key = config('permission.column_names.permission_pivot_key');

        return is_string($key) && $key !== '' ? $key : 'permission_id';
    }
}
