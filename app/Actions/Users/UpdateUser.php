<?php

namespace App\Actions\Users;

use App\Actions\Conversations\ReleaseAssignedConversations;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UpdateUser
{
    public function __construct(
        private AuditLogger $audit,
        private AssessRoleChange $assessRoleChange,
        private EnsureRootAuthorityRemains $ensureRootAuthorityRemains,
        private ReleaseAssignedConversations $releaseConversations,
    ) {}

    /**
     * Update an account. A role change is assessed again inside the
     * transaction: if it is significant, the caller must send the fingerprint
     * of the impact the actor confirmed, otherwise the change is rejected.
     *
     * Nothing is written (no save, no audit entry, no released conversations)
     * when the submitted data matches the account; a password equal to the
     * current one is not a change either.
     *
     * @param  array{name: string, email: string, role: Role|null, department_ids: list<int>|null, password?: string|null, role_change_fingerprint?: string|null}  $data
     * @return bool Whether anything changed.
     */
    public function handle(User $user, array $data, User $actor): bool
    {
        return DB::transaction(function () use ($user, $data, $actor): bool {
            $user->loadMissing(['roles.permissions', 'departments']);

            $previousRole = $user->role();
            $newRole = $data['role'] ?? $previousRole;
            $roleChanges = $newRole !== null && ! $newRole->is($previousRole);

            if ($roleChanges) {
                $this->changeRole($user, $newRole, $data['role_change_fingerprint'] ?? null, $actor);
            }

            $profileChanged = $this->updateProfile($user, $data, $actor);

            $allowsMembership = $newRole?->scope->allowsDepartmentMembership() ?? false;
            $departmentsChanged = false;

            if ($data['department_ids'] !== null || ! $allowsMembership) {
                $departmentsChanged = $this->syncDepartments($user, $allowsMembership ? $data['department_ids'] ?? [] : [], $actor);
            }

            $password = $data['password'] ?? null;
            $passwordChanged = filled($password) && ! Hash::check($password, $user->password);

            if ($passwordChanged) {
                $user->forceFill(['password' => $password, 'remember_token' => null])->save();

                $this->audit->record(AuditAction::UserPasswordReset, $user, [], $actor);
            }

            $user->refresh();

            return $roleChanges || $profileChanged || $departmentsChanged || $passwordChanged;
        });
    }

    /**
     * @throws ValidationException
     */
    private function changeRole(User $user, Role $newRole, ?string $confirmedFingerprint, User $actor): void
    {
        $impact = $this->assessRoleChange->handle($user, $newRole, $actor);

        if ($impact->blockers !== []) {
            throw ValidationException::withMessages(['role_id' => $impact->blockers[0]]);
        }

        if ($impact->isSignificant() && ! hash_equals($impact->fingerprint(), (string) $confirmedFingerprint)) {
            throw ValidationException::withMessages([
                'role_change' => 'El impacto del cambio de rol no fue confirmado o cambió mientras lo revisabas. Revísalo de nuevo.',
            ]);
        }

        if ($impact->from?->is_root && ! $newRole->is_root) {
            $this->ensureRootAuthorityRemains->handle($user, 'role_id');
        }

        $user->syncRoles([$newRole]);
        $user->load('roles.permissions');

        $released = $newRole->grants(Permission::ReplyConversations)
            ? 0
            : $this->releaseConversations->handle($user, null, $actor, 'Cambio de rol');

        $this->audit->record(AuditAction::UserRoleChanged, $user, [
            'from' => $impact->from?->label,
            'to' => $newRole->label,
            'scope' => $newRole->scope->label(),
            'lost_permissions' => $impact->lostPermissions,
            'gained_permissions' => $impact->gainedPermissions,
            'released_conversations' => $released,
        ], $actor);
    }

    /**
     * @param  array{name: string, email: string}  $data
     */
    private function updateProfile(User $user, array $data, User $actor): bool
    {
        $user->fill(['name' => $data['name'], 'email' => $data['email']]);

        if (! $user->isDirty()) {
            return false;
        }

        $changes = [];

        foreach ($user->getDirty() as $attribute => $value) {
            $changes[$attribute] = ['old' => $user->getOriginal($attribute), 'new' => $value];
        }

        if ($user->isDirty('email')) {
            $user->forceFill(['email_verified_at' => now()]);
        }

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'El valor de correo electrónico ya está en uso.']);
        }

        $this->audit->record(AuditAction::UserUpdated, $user, ['changes' => $changes], $actor);

        return true;
    }

    /**
     * @param  list<int>  $departmentIds
     */
    private function syncDepartments(User $user, array $departmentIds, User $actor): bool
    {
        $result = $user->departments()->sync($departmentIds);

        /** @var list<int> $attached */
        $attached = $result['attached'];
        /** @var list<int> $detached */
        $detached = $result['detached'];

        if ($attached === [] && $detached === []) {
            return false;
        }

        $released = 0;

        foreach ($detached as $departmentId) {
            $released += $this->releaseConversations->handle($user, $departmentId, $actor, 'Retirado del departamento');
        }

        $names = Department::query()->whereKey([...$attached, ...$detached])->pluck('name', 'id');

        $this->audit->record(AuditAction::UserDepartmentsChanged, $user, [
            'added' => array_values($names->only($attached)->all()),
            'removed' => array_values($names->only($detached)->all()),
            'released_conversations' => $released,
        ], $actor);

        $user->unsetRelation('departments');

        return true;
    }
}
