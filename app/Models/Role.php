<?php

namespace App\Models;

use App\Concerns\DeletesLogically;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * An authorization role managed from the interface. Deleting it is logical
 * (see DeletesLogically): an inactive role grants nothing and is no longer
 * offered, while the history keeps referencing it.
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property string|null $description
 * @property string $guard_name
 * @property DataScope $scope
 * @property bool $is_active
 * @property bool $is_root
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $users_count
 */
class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use DeletesLogically, HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'guard_name' => 'web',
        'scope' => 'assigned',
        'is_active' => true,
        'is_root' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => DataScope::class,
            'is_active' => 'boolean',
            'is_root' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Role>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Leave out the root role unless the viewer is the root authority.
     *
     * @param  Builder<Role>  $query
     */
    #[Scope]
    protected function withoutProtectedFor(Builder $query, User $viewer): void
    {
        if (! $viewer->hasRootAuthority()) {
            $query->where('is_root', false);
        }
    }

    /**
     * Whether the role must stay invisible to the viewer (the root role for
     * anyone but the root authority).
     */
    public function isHiddenFrom(User $viewer): bool
    {
        return $this->is_root && ! $viewer->hasRootAuthority();
    }

    /**
     * The description shown in the interface and exports. A default role
     * still storing a wording that was replaced shows the current one, so
     * stored texts never reference accounts the reader may not know about.
     */
    public function presentedDescription(): ?string
    {
        return DefaultRole::tryFrom($this->name)?->presentDescription($this->description) ?? $this->description;
    }

    /**
     * Names of the permissions granted to the role.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return array_values($this->permissions->pluck('name')->all());
    }

    /**
     * Whether the role grants the permission (regardless of being active).
     */
    public function grants(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissionNames(), true);
    }
}
