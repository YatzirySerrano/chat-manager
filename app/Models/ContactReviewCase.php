<?php

namespace App\Models;

use App\Enums\ContactReviewReason;
use App\Enums\ContactReviewResolution;
use App\Enums\ContactReviewStatus;
use Database\Factories\ContactReviewCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A possible duplicate waiting for an administrator. The contact under review
 * is compared with the candidates; only administrators with organization-wide
 * scope see it, so nothing is revealed across departments.
 *
 * @property int $id
 * @property int $contact_id
 * @property ContactReviewReason $reason
 * @property ContactReviewStatus $status
 * @property list<int> $candidate_contact_ids
 * @property array<string, mixed>|null $evidence
 * @property ContactReviewResolution|null $resolution
 * @property int|null $resolved_contact_id
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['contact_id', 'reason', 'candidate_contact_ids', 'evidence'])]
class ContactReviewCase extends Model
{
    /** @use HasFactory<ContactReviewCaseFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ContactReviewReason::class,
            'status' => ContactReviewStatus::class,
            'resolution' => ContactReviewResolution::class,
            'candidate_contact_ids' => 'array',
            'evidence' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === ContactReviewStatus::Open;
    }

    /**
     * @param  Builder<ContactReviewCase>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', ContactReviewStatus::Open);
    }
}
