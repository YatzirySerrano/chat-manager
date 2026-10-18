<?php

namespace App\Models;

use App\Enums\DataScope;
use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'roles', 'permissions'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Departments the user belongs to.
     *
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withTimestamps();
    }

    /**
     * Only include active accounts.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Only include accounts holding the given role.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function withRole(Builder $query, Role|int $role): void
    {
        $roleId = $role instanceof Role ? $role->id : $role;

        $query->whereHas('roles', fn (Builder $roles) => $roles->whereKey($roleId));
    }

    /**
     * Only include active accounts whose active role is the root authority.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function withRootAuthority(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('is_root', true)->where('is_active', true));
    }

    /**
     * Only include users whose active role grants the permission.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function withPermission(Builder $query, Permission $permission): void
    {
        $query->whereHas('roles', fn (Builder $roles) => $roles
            ->where('is_active', true)
            ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('name', $permission->value)));
    }

    /**
     * Only include the users the actor is allowed to see according to the
     * data scope of the actor's role.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $actor): void
    {
        match ($actor->dataScope()) {
            DataScope::Global => null,
            DataScope::Departments => $query->where(fn (Builder $visible) => $visible
                ->whereKey($actor->id)
                ->orWhereHas('departments', fn (Builder $departments) => $departments->whereKey($actor->departmentIds()))),
            default => $query->whereKey($actor->id),
        };
    }

    /**
     * The single role assigned to the user, active or not.
     */
    public function role(): ?Role
    {
        $role = $this->roles->first();

        return $role instanceof Role ? $role : null;
    }

    /**
     * The user's role when it is active; an inactive role grants nothing.
     */
    public function activeRole(): ?Role
    {
        $role = $this->role();

        return $role !== null && $role->is_active ? $role : null;
    }

    /**
     * The data the user can act upon, or null when the user cannot act at all.
     */
    public function dataScope(): ?DataScope
    {
        return $this->is_active ? $this->activeRole()?->scope : null;
    }

    /**
     * Whether the user holds the protected root authority.
     */
    public function hasRootAuthority(): bool
    {
        return $this->is_active && ($this->activeRole()->is_root ?? false);
    }

    /**
     * Whether the account is active and its active role grants the permission.
     * Organization-wide permissions additionally require the global scope.
     */
    public function holdsPermission(Permission $permission): bool
    {
        $scope = $this->dataScope();

        if ($scope === null || ($permission->requiresGlobalScope() && $scope !== DataScope::Global)) {
            return false;
        }

        return $this->checkPermissionTo($permission->value);
    }

    /**
     * Names of the permissions the user can currently exercise.
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        if ($this->dataScope() === null) {
            return [];
        }

        return array_values(array_filter(
            Permission::values(),
            fn (string $name): bool => $this->holdsPermission(Permission::from($name)),
        ));
    }

    /**
     * Whether the user's role allows belonging to departments.
     */
    public function canJoinDepartments(): bool
    {
        return $this->role()?->scope->allowsDepartmentMembership() ?? false;
    }

    /**
     * Whether the user is a member of the given department.
     */
    public function belongsToDepartment(Department|int $department): bool
    {
        $departmentId = $department instanceof Department ? $department->id : $department;

        if ($this->relationLoaded('departments')) {
            return $this->departments->contains('id', $departmentId);
        }

        return $this->departments()->whereKey($departmentId)->exists();
    }

    /**
     * Identifiers of the departments the user belongs to.
     *
     * @return list<int>
     */
    public function departmentIds(): array
    {
        $ids = $this->relationLoaded('departments')
            ? $this->departments->modelKeys()
            : $this->departments()->pluck('departments.id')->all();

        return array_values(array_map('intval', $ids));
    }
}
