<?php

namespace App\Models;

use App\Concerns\DeletesLogically;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person the company talks to. How each provider identifies the person
 * (e.g. the WhatsApp `wa_id`) lives in ContactIdentity, so the same person can
 * be reached through several channels.
 *
 * @property int $id
 * @property string $name Display name chosen by the company.
 * @property string|null $phone_e164 Normalized with App\Support\PhoneNumber; not unique.
 * @property string|null $profile_name Name the person set on their WhatsApp profile.
 * @property string|null $email
 * @property ContactStatus $status
 * @property Carbon|null $archived_at
 * @property ContactSource $source
 * @property int|null $created_by_user_id
 * @property array<string, string>|null $custom_fields Controlled label => value map.
 * @property Carbon|null $first_interaction_at
 * @property Carbon|null $last_interaction_at
 * @property Carbon|null $blocked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'phone_e164', 'profile_name', 'email', 'custom_fields'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use DeletesLogically, HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'source' => 'manual',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'source' => ContactSource::class,
            'custom_fields' => 'array',
            'archived_at' => 'datetime',
            'first_interaction_at' => 'datetime',
            'last_interaction_at' => 'datetime',
            'blocked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<ContactIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    /**
     * Private notes, newest first.
     *
     * @return HasMany<ContactNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ContactNote::class)->latest()->latest('id');
    }

    /**
     * @return HasMany<ContactAttribution, $this>
     */
    public function attributions(): HasMany
    {
        return $this->hasMany(ContactAttribution::class);
    }

    /**
     * @return HasMany<ContactReviewCase, $this>
     */
    public function reviewCases(): HasMany
    {
        return $this->hasMany(ContactReviewCase::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot(['tagged_by_user_id', 'created_at']);
    }

    /**
     * Connections the contact has interacted through.
     *
     * @return BelongsToMany<Connection, $this>
     */
    public function connections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class)
            ->withPivot(['first_interaction_at', 'last_interaction_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * A contact is logically deleted by archiving it (status + archived_at),
     * the mechanism contacts always had: there is no second one.
     */
    public function isDeleted(): bool
    {
        return $this->status === ContactStatus::Archived;
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function constrainDeletion(Builder $query, bool $deleted): void
    {
        $query->where($query->qualifyColumn('status'), $deleted ? ContactStatus::Archived : ContactStatus::Active);
    }

    /**
     * Restrict the query to contacts the user is allowed to see. Deleted
     * (archived) contacts are included; hiding them from people who cannot
     * consult deleted records is done with hidingDeletedFrom().
     *
     * - Global scope: every contact.
     * - Departments scope: contacts with a conversation in one of the user's
     *   departments or assigned to the user, plus those the user created.
     * - Assigned scope: contacts with a conversation assigned to the user,
     *   plus those the user created.
     *
     * @param  Builder<Contact>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->holdsPermission(Permission::ViewContacts)) {
            $query->whereRaw('1 = 0');

            return;
        }

        match ($user->dataScope()) {
            DataScope::Global => null,
            DataScope::Departments => $query->where(fn (Builder $visible) => $visible
                ->where('created_by_user_id', $user->id)
                ->orWhereHas('conversations', fn (Builder $conversations) => $conversations->where(fn (Builder $reachable) => $reachable
                    ->where('assigned_user_id', $user->id)
                    ->orWhereIn('department_id', $user->departmentIds())))),
            default => $query->where(fn (Builder $visible) => $visible
                ->where('created_by_user_id', $user->id)
                ->orWhereHas('conversations', fn (Builder $conversations) => $conversations->where('assigned_user_id', $user->id))),
        };
    }
}
