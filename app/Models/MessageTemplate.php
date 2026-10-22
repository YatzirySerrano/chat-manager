<?php

namespace App\Models;

use App\Concerns\DeletesLogically;
use App\Enums\DataScope;
use App\Enums\MessageTemplateCategory;
use App\Enums\MessageTemplateStatus;
use App\Services\Meta\TemplateStructure;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A WhatsApp message template of a connection's business account, as Meta
 * reports it. Its status and components are only ever written from Meta's
 * answers (synchronization or status webhooks); a template Meta stops
 * returning is logically deleted (`deleted_at`).
 *
 * @property int $id
 * @property int $connection_id
 * @property string $business_account_id
 * @property string $meta_template_id
 * @property string $name
 * @property string $language
 * @property string|null $category
 * @property MessageTemplateStatus $status
 * @property string|null $quality_score
 * @property string|null $parameter_format
 * @property list<array<string, mixed>>|null $components
 * @property string|null $rejected_reason
 * @property Carbon|null $status_updated_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MessageTemplate extends Model
{
    use DeletesLogically;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MessageTemplateStatus::class,
            'components' => 'array',
            'status_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function channelConnection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function categoryEnum(): ?MessageTemplateCategory
    {
        return $this->category === null ? null : MessageTemplateCategory::tryFrom($this->category);
    }

    public function structure(): TemplateStructure
    {
        return TemplateStructure::fromTemplate($this);
    }

    /**
     * Approved by Meta, still returned by Meta and sendable by this application.
     */
    public function isUsable(): bool
    {
        return ! $this->isDeleted() && $this->status->isSendable() && $this->structure()->isSendable();
    }

    /**
     * @param  Builder<MessageTemplate>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', MessageTemplateStatus::Approved)->whereNull('deleted_at');
    }

    /**
     * Templates of the connections the user can see (same rule as connections).
     *
     * @param  Builder<MessageTemplate>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->dataScope() !== DataScope::Global) {
            $query->whereHas('channelConnection', fn (Builder $connections) => $connections->visibleTo($user));
        }
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function constrainDeletion(Builder $query, bool $deleted): void
    {
        $deleted
            ? $query->whereNotNull($query->qualifyColumn('deleted_at'))
            : $query->whereNull($query->qualifyColumn('deleted_at'));
    }
}
