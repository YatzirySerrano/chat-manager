<?php

namespace App\Models;

use App\Concerns\DeletesLogically;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Support\AppColor;
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
 * A group of employees that attends conversations. Deleting it is logical
 * (see DeletesLogically): it stops operating, its memberships are kept
 * (inert while deleted) and the history keeps pointing to it.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property AppColor $color
 * @property bool $is_active
 * @property string|null $welcome_message
 * @property string|null $away_message Sent instead of the welcome outside business hours; overrides the connection's away message.
 * @property DistributionMode $distribution_mode
 * @property int|null $max_open_conversations_per_agent Capacity of each agent for this department's conversations; null inherits the platform limit.
 * @property bool $uses_custom_hours
 * @property array{days: array<string, array{open: bool, start: string, end: string}>}|null $business_hours Only applies while `uses_custom_hours` is true; otherwise the platform hours apply.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'description', 'color'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use DeletesLogically, HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'uses_custom_hours' => false,
        'distribution_mode' => 'shared',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => AppColor::class,
            'is_active' => 'boolean',
            'distribution_mode' => DistributionMode::class,
            'max_open_conversations_per_agent' => 'integer',
            'uses_custom_hours' => 'boolean',
            'business_hours' => 'array',
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
     * Connections whose conversations the department is authorized to receive.
     *
     * @return BelongsToMany<Connection, $this>
     */
    public function connections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class)->withTimestamps();
    }

    /**
     * Non-global quick replies shared with the department.
     *
     * @return BelongsToMany<QuickReply, $this>
     */
    public function quickReplies(): BelongsToMany
    {
        return $this->belongsToMany(QuickReply::class)->withTimestamps();
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
