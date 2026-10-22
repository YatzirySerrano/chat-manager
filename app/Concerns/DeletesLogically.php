<?php

namespace App\Concerns;

use App\Enums\RecordState;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Logical deletion of a record that is never physically removed, so its
 * history keeps every reference. By default a record is deleted when it is
 * not active (`is_active`), which makes every existing operational guard
 * apply to deleted records; models storing the deletion elsewhere override
 * isDeleted() and constrainDeletion().
 *
 * Deliberately not Laravel's SoftDeletes: its global scope would hide
 * deleted records from the relations that show history.
 */
trait DeletesLogically
{
    public function isDeleted(): bool
    {
        return ! $this->is_active;
    }

    /**
     * Only include the records in the given state.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inRecordState(Builder $query, RecordState $state): void
    {
        match ($state) {
            RecordState::Active => $this->constrainDeletion($query, false),
            RecordState::Deleted => $this->constrainDeletion($query, true),
            RecordState::All => null,
        };
    }

    /**
     * Only include records that are not deleted.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notDeleted(Builder $query): void
    {
        $this->constrainDeletion($query, false);
    }

    /**
     * Hide deleted records from people who cannot consult them, for
     * counters, searches and selects.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function hidingDeletedFrom(Builder $query, User $actor): void
    {
        if (! $actor->canViewDeletedRecords()) {
            $this->constrainDeletion($query, false);
        }
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function constrainDeletion(Builder $query, bool $deleted): void
    {
        $query->where($query->qualifyColumn('is_active'), ! $deleted);
    }
}
