<?php

namespace App\Models;

use App\Concerns\DeletesLogically;
use App\Enums\DataScope;
use Database\Factories\QuickReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Predefined plain-text answer inserted into the composer with "/shortcut".
 * It is available to everyone when global, otherwise to the members of its
 * departments. Deleting it is logical (see DeletesLogically): it can no
 * longer be used and its shortcut stays reserved until it is restored.
 *
 * @property int $id
 * @property string $title
 * @property string $shortcut Lowercase, stored without the leading "/".
 * @property string $body
 * @property bool $is_active
 * @property bool $is_global
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'shortcut', 'body', 'is_global'])]
class QuickReply extends Model
{
    /** @use HasFactory<QuickReplyFactory> */
    use DeletesLogically, HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'is_global' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_global' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @param  Builder<QuickReply>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Replies the user may manage or consult: every reply for global users,
     * otherwise global replies and those of the user's departments.
     *
     * @param  Builder<QuickReply>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->dataScope() !== DataScope::Global) {
            $this->scopeReachableBy($query, $user);
        }
    }

    /**
     * Replies the user may insert in a conversation: active, and either global
     * or shared with an ACTIVE department the user belongs to. Global users
     * never belong to departments but attend every department, so for them
     * any active department counts. A reply whose departments are all
     * inactive is offered to nobody.
     *
     * @param  Builder<QuickReply>  $query
     */
    #[Scope]
    protected function usableBy(Builder $query, User $user): void
    {
        $isGlobalUser = $user->dataScope() === DataScope::Global;

        $query->where('is_active', true)
            ->where(fn (Builder $usable) => $usable
                ->where('is_global', true)
                ->orWhereHas('departments', fn (Builder $departments) => $departments
                    ->where('departments.is_active', true)
                    ->when(! $isGlobalUser, fn (Builder $own) => $own->whereKey($user->departmentIds()))));
    }

    /**
     * @param  Builder<QuickReply>  $query
     */
    private function scopeReachableBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $reachable) => $reachable
            ->where('is_global', true)
            ->orWhereHas('departments', fn (Builder $departments) => $departments->whereKey($user->departmentIds())));
    }
}
