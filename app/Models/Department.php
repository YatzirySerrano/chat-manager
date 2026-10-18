<?php

namespace App\Models;

use App\Enums\ColorSwatch;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ColorSwatch $color
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'description', 'color'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

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
            'color' => ColorSwatch::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * All members of the department (supervisors and agents).
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Members whose role lets them manage the department ("responsables").
     *
     * @return BelongsToMany<User, $this>
     */
    public function leaders(): BelongsToMany
    {
        return $this->members()->withPermission(Permission::ManageDepartmentMembers);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Whether the department still has conversations waiting or being attended.
     */
    public function hasUnclosedConversations(): bool
    {
        return $this->conversations()->where('status', '!=', ConversationStatus::Closed)->exists();
    }

    /**
     * @param  Builder<Department>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Only include the departments the user reaches with the scope of their role.
     *
     * @param  Builder<Department>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->dataScope() !== DataScope::Global) {
            $query->whereKey($user->departmentIds());
        }
    }
}
