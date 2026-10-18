<?php

namespace App\Models;

use App\Enums\DataScope;
use App\Enums\Permission;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * An authorization role managed from the interface.
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
    use HasFactory;

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
