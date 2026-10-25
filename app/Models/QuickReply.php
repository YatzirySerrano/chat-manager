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
 * A corporate reply is available to everyone when global, otherwise to the
 * members of its departments. A personal reply (with owner) is only offered
 * to its owner and never shared by department. Deleting it is logical (see DeletesLogically): it can no
 * longer be used and its shortcut stays reserved until it is restored.
 *
 * @property int $id
 * @property string $title
 * @property string $shortcut Lowercase, stored without the leading "/".
 * @property string $body
 * @property bool $is_active
 * @property bool $is_global
 * @property int|null $owner_user_id Owner of a personal reply; null for corporate replies (global or by department).
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
     * Owner of a personal reply (only they and whoever supervises personal
     * items see it; it is never shared by department).
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isPersonal(): bool
    {
        return $this->owner_user_id !== null;
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
     * Personal replies, optionally only those of one owner.
     *
     * @param  Builder<QuickReply>  $query
     */
    #[Scope]
    protected function personal(Builder $query, ?User $owner = null): void
    {
        $query->whereNotNull($query->qualifyColumn('owner_user_id'))
            ->when($owner !== null, fn (Builder $owned) => $owned->where($query->qualifyColumn('owner_user_id'), $owner?->id));
    }

    /**
     * Corporate replies: global or shared by department, without owner.
     *
     * @param  Builder<QuickReply>  $query
     */
    #[Scope]
    protected function corporate(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('owner_user_id'));
    }

    /**
     * Replies the user may manage or consult as their own: corporate replies
     * within their scope (every corporate reply for global users, otherwise
     * global ones and those of the user's departments) plus the user's own
     * personal replies. Personal replies of other users are never included,
     * not even for global users (see supervisedBy).
     *
     * @param  Builder<QuickReply>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $visible) => $visible
            ->where(fn (Builder $corporate) => $corporate
                ->whereNull($query->qualifyColumn('owner_user_id'))
                ->when($user->dataScope() !== DataScope::Global, fn (Builder $reachable) => $this->scopeReachableBy($reachable, $user)))
            ->orWhere($query->qualifyColumn('owner_user_id'), $user->id));
    }

    /**
     * Replies the user may insert in a conversation: active, and either their
     * own personal replies or corporate ones that are global or shared with an
     * ACTIVE department the user belongs to. Global users never belong to
     * departments but attend every department, so for them any active
     * department counts. A corporate reply whose departments are all inactive
     * is offered to nobody; personal replies of other users never are.
     *
     * @param  Builder<QuickReply>  $query
     */
    #[Scope]
    protected function usableBy(Builder $query, User $user): void
    {
        $isGlobalUser = $user->dataScope() === DataScope::Global;

        $query->where('is_active', true)
            ->where(fn (Builder $usable) => $usable
                ->where('owner_user_id', $user->id)
                ->orWhere(fn (Builder $corporate) => $corporate
                    ->whereNull('owner_user_id')
                    ->where(fn (Builder $shared) => $shared
                        ->where('is_global', true)
                        ->orWhereHas('departments', fn (Builder $departments) => $departments
                            ->where('departments.is_active', true)
                            ->when(! $isGlobalUser, fn (Builder $own) => $own->whereKey($user->departmentIds()))))));
    }

    /**
     * Personal replies of OTHER users that the supervisor may consult
     * according to their data scope: any user for global scope, users sharing
     * at least one department for departments scope, nobody otherwise.
     * Replies of a Superadministrador stay out of reach of non-root
     * supervisors (PersonalItemSupervision::canView()). The
     * supervision.view-personal permission is checked by the callers.
     *
     * @param  Builder<QuickReply>  $query
     */
    #[Scope]
    protected function supervisedBy(Builder $query, User $supervisor): void
    {
        $query->whereNotNull($query->qualifyColumn('owner_user_id'))
            ->where($query->qualifyColumn('owner_user_id'), '!=', $supervisor->id)
            ->whereHas('owner', fn (Builder $owners) => $owners->visibleTo($supervisor)
                ->when(! $supervisor->hasRootAuthority(), fn (Builder $nonRoot) => $nonRoot
                    ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('is_root', true))));
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
