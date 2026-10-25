<?php

namespace App\Actions\Contacts;

use App\Enums\ContactOriginType;
use App\Enums\ContactSource;
use App\Enums\RecordState;
use App\Models\Contact;
use App\Models\User;
use App\Services\ResolveContactOrigin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The contacts list as the contacts screen shows it: within the actor's
 * data scope, the record state (deleted contacts only for people who can
 * consult deleted records), search over name, profile name, email and
 * phone (ContactSearch), tags, source, origin and the chosen order, always
 * ending in the id so pages never repeat or skip a contact.
 *
 * Only contacts saved into the directory are listed by default: provisional
 * ones (unknown senders registered automatically by an inbound message) are
 * technical records of the conversations and are included only when asked
 * for ("Incluir no guardados"). Inbox search, duplicate detection and reviews
 * do not use this query and keep finding them.
 *
 * Shared by the contacts screen and the contacts report, so both always
 * agree on what "these contacts" means.
 *
 * @phpstan-type ContactFilters array{search: string, record: RecordState, tags: list<int>, source: string|null, origin: string|null, sort: string, include_provisional: bool}
 */
class ContactListQuery
{
    /**
     * Sort options exposed in the interface.
     *
     * @var list<string>
     */
    public const SORTS = ['recent', 'name', 'newest'];

    /**
     * Filter value meaning "no identified origin".
     */
    public const UNIDENTIFIED_ORIGIN = 'unknown';

    public function __construct(private ResolveContactOrigin $resolveOrigin, private ContactSearch $search) {}

    /**
     * Validation rules of the list filters (query string).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'record' => ['nullable', Rule::enum(RecordState::class)],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['integer'],
            'source' => ['nullable', Rule::enum(ContactSource::class)],
            'origin' => ['nullable', Rule::enum(ContactOriginType::class)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'include_provisional' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The filters the list applies for the actor, from validated input.
     *
     * @param  array<string, mixed>  $validated
     * @return ContactFilters
     */
    public function filters(User $actor, array $validated): array
    {
        $tags = is_array($validated['tags'] ?? null) ? $validated['tags'] : [];
        $source = $validated['source'] ?? null;
        $origin = $validated['origin'] ?? null;
        $sort = $validated['sort'] ?? null;

        return [
            'search' => trim(is_string($validated['search'] ?? null) ? $validated['search'] : ''),
            'record' => RecordState::resolveFor($actor, $validated['record'] ?? null),
            'tags' => array_values(array_unique(array_map('intval', $tags))),
            'source' => is_string($source) ? $source : null,
            'origin' => is_string($origin) ? $origin : null,
            'sort' => is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : 'recent',
            'include_provisional' => filter_var($validated['include_provisional'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  ContactFilters  $filters
     * @return Builder<Contact>
     */
    public function handle(User $actor, array $filters): Builder
    {
        $search = $filters['search'];
        $sort = $filters['sort'];

        return Contact::query()
            ->visibleTo($actor)
            ->inRecordState($filters['record'])
            ->when(! $filters['include_provisional'], fn (Builder $query) => $query->savedInDirectory())
            ->when($search !== '', fn (Builder $query) => $this->search->constrain($query, $search))
            ->when($filters['tags'] !== [], fn (Builder $query) => $query->whereHas(
                'tags',
                fn (Builder $tags) => $tags->whereIn('tags.id', $filters['tags']),
            ))
            ->when($filters['source'] !== null, fn (Builder $query) => $query->where('source', $filters['source']))
            ->when($filters['origin'] !== null, fn (Builder $query) => $this->resolveOrigin->constrainToOrigin(
                $query,
                $filters['origin'] === self::UNIDENTIFIED_ORIGIN ? null : $filters['origin'],
            ))
            // Without interaction last (MariaDB sorts NULL last in descending
            // order), backed by the (last_interaction_at, id) index.
            ->when($sort === 'recent', fn (Builder $query) => $query
                ->orderByDesc('last_interaction_at')
                ->orderByDesc('id'))
            ->when($sort === 'name', fn (Builder $query) => $query->orderBy('name')->orderBy('id'))
            ->when($sort === 'newest', fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'));
    }
}
