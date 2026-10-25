<?php

namespace App\Actions\Tags;

use App\Enums\RecordState;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tag catalog as the tags screen lists it: optional search over name
 * and description, the record state (deleted tags only for people who can
 * consult deleted records), usage counted only over the contacts and
 * conversations the actor can see, active tags first by name. Contacts are
 * counted only when saved into the directory: provisional ones (unknown
 * senders registered automatically) are not part of the commercial
 * directory, like in the contacts screen and report.
 *
 * Shared by the tags screen and the tags report, so both always agree.
 */
class TagCatalogQuery
{
    /**
     * `record` is the "Estado del registro" filter of the screen and the
     * report; it is resolved for the actor (deleted tags only for people who
     * can consult deleted records).
     *
     * @param  array{search?: string|null, record?: string|null}  $filters
     * @return Builder<Tag>
     */
    public function handle(User $actor, array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $record = RecordState::resolveFor($actor, $filters['record'] ?? null);

        return Tag::query()
            ->inRecordState($record)
            ->withCount([
                'contacts' => fn (Builder $query) => $this->visibleContacts($query, $actor),
                'conversations' => fn (Builder $query) => $this->visibleConversations($query, $actor),
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function visibleContacts(Builder $query, User $actor): void
    {
        $query->visibleTo($actor)->hidingDeletedFrom($actor)->savedInDirectory();
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    private function visibleConversations(Builder $query, User $actor): void
    {
        $query->visibleTo($actor);
    }
}
