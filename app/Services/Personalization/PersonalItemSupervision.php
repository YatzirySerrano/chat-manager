<?php

namespace App\Services\Personalization;

use App\Enums\Permission;
use App\Models\User;
use App\Services\PrivilegeGuard;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may consult or change another user's personal items (aliases, chat
 * appearance, personal quick replies). Private between colleagues does not
 * mean private from an authorized administrator: `supervision.view-personal`
 * lets them consult, `supervision.manage-personal` edit or delete, always
 * within the data scope of their role (global: any user; departments: users
 * sharing at least one department; assigned: nobody); changing them also
 * needs authority over the owner's account (canManageUser()). Items of a
 * Superadministrador stay out of reach of anyone else. Consulting never
 * applies the other user's items to the supervisor.
 */
class PersonalItemSupervision
{
    public function __construct(private PrivilegeGuard $guard) {}

    public function canSupervise(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewPersonalItems);
    }

    public function canView(User $actor, User $owner): bool
    {
        return ! $actor->is($owner)
            && $this->canSupervise($actor)
            && $this->guard->withinScope($actor, $owner)
            && ($actor->hasRootAuthority() || ! ($owner->role()->is_root ?? false));
    }

    /**
     * Editing or deleting also needs authority over the owner's account
     * (PrivilegeGuard::canManageUser(): within scope and holding a role the
     * actor could assign), the same rule as personal quick replies.
     */
    public function canManage(User $actor, User $owner): bool
    {
        return $actor->holdsPermission(Permission::ManagePersonalItems)
            && $this->canView($actor, $owner)
            && $this->guard->canManageUser($actor, $owner);
    }

    /**
     * Users whose personal items the actor may consult (never the actor).
     *
     * @return Builder<User>
     */
    public function owners(User $actor): Builder
    {
        $query = User::query()->visibleTo($actor)->whereKeyNot($actor->id);

        if (! $this->canSupervise($actor)) {
            return $query->whereRaw('1 = 0');
        }

        if (! $actor->hasRootAuthority()) {
            $query->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('is_root', true));
        }

        return $query;
    }
}
