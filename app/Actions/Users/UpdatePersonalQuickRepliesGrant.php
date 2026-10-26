<?php

namespace App\Actions\Users;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Events\Users\UserAccessChanged;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PrivilegeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants or revokes quick-replies.personal as a direct permission of one
 * user, on top of what their role grants. Revoking never deletes the user's
 * personal replies: they stay usable (with quick-replies.use) but read-only.
 * When the role already grants it the per-user grant cannot be changed here.
 */
class UpdatePersonalQuickRepliesGrant
{
    public function __construct(
        private AuditLogger $audit,
        private PrivilegeGuard $guard,
    ) {}

    /**
     * Changing a user's permissions is a privilege change, like assigning a
     * role: it requires assigning roles to that user and holding the
     * permission oneself.
     */
    public function allows(User $actor, User $user): bool
    {
        return Gate::forUser($actor)->allows('assignRole', $user)
            && $this->guard->canGrantPermissions($actor, [Permission::CreatePersonalQuickReplies->value]);
    }

    public function grantedByRole(User $user): bool
    {
        return $user->role()?->grants(Permission::CreatePersonalQuickReplies) ?? false;
    }

    public function grantedDirectly(User $user): bool
    {
        return $user->hasDirectPermission(Permission::CreatePersonalQuickReplies->value);
    }

    /**
     * @return bool Whether anything changed.
     *
     * @throws ValidationException
     */
    public function handle(User $user, bool $enabled, User $actor): bool
    {
        if ($this->grantedByRole($user)) {
            if (! $enabled) {
                throw ValidationException::withMessages([
                    'personal_quick_replies' => 'Su rol ya le permite crear respuestas rápidas personales. Para retirarlo, cambia el rol o sus permisos en Roles.',
                ]);
            }

            return false;
        }

        if ($this->grantedDirectly($user) === $enabled) {
            return false;
        }

        DB::transaction(function () use ($user, $enabled, $actor): void {
            if ($enabled) {
                $user->givePermissionTo(Permission::CreatePersonalQuickReplies->value);
            } else {
                $user->revokePermissionTo(Permission::CreatePersonalQuickReplies->value);
            }

            $this->audit->record(
                $enabled ? AuditAction::UserPersonalQuickRepliesGranted : AuditAction::UserPersonalQuickRepliesRevoked,
                $user,
                ['personal_quick_replies' => $user->personalQuickReplies()->notDeleted()->count()],
                $actor,
            );

            // Open sessions of the user reload their permissions at once.
            UserAccessChanged::dispatch([$user->id], 'permissions_changed');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('permissions');

        return true;
    }
}
