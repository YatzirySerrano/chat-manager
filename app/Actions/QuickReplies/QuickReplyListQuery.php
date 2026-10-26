<?php

namespace App\Actions\QuickReplies;

use App\Enums\Permission;
use App\Enums\RecordState;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The quick replies list as the quick replies screen shows it, split in
 * segments:
 * - corporate: managers see every corporate reply within their scope (deleted
 *   ones only when they can consult deleted records); users that can only use
 *   replies see the ones they can insert. Filters by department of the reply
 *   and availability (global or by department).
 * - mine: the actor's own personal replies.
 * - others: personal replies of other users of the actor's data scope, for
 *   holders of supervision.view-personal. Filters by owner and by department
 *   of the owner.
 * - all (only for the report, never a tab of the screen): the union of the
 *   segments the actor may open, each with its own rule above, so the
 *   general report has every reply the person is authorized to consult.
 *   Filters of a single tab (department, owner, availability) do not apply.
 * Every segment searches over title, shortcut and body and sorts by
 * shortcut, title or last update.
 *
 * Shared by the quick replies screen and the quick replies report.
 *
 * @phpstan-type QuickReplyFilters array{segment: string, search: string, department: int|null, owner: int|null, record: RecordState, availability: string|null, sort: string, manages: bool}
 */
class QuickReplyListQuery
{
    public const SEGMENT_CORPORATE = 'corporate';

    public const SEGMENT_MINE = 'mine';

    public const SEGMENT_OTHERS = 'others';

    public const SEGMENT_ALL = 'all';

    public const SORTS = ['shortcut', 'title', 'recent'];

    /**
     * Validation rules of the list filters (query string).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'segment' => ['nullable', Rule::in([self::SEGMENT_CORPORATE, self::SEGMENT_MINE, self::SEGMENT_OTHERS, self::SEGMENT_ALL])],
            'search' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'integer'],
            'owner' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'record' => ['nullable', Rule::enum(RecordState::class)],
            'availability' => ['nullable', Rule::in(['global', 'departments'])],
        ];
    }

    /**
     * Segments the actor may open, in display order: corporate replies for
     * those who use or manage them, their own personal replies when they may
     * create them or still have some, and other users' personal replies for
     * supervisors.
     *
     * @return list<string>
     */
    public function segmentsFor(User $actor): array
    {
        $segments = [];

        if ($actor->holdsPermission(Permission::UseQuickReplies) || $actor->holdsPermission(Permission::ManageQuickReplies)) {
            $segments[] = self::SEGMENT_CORPORATE;
        }

        if ($actor->holdsPermission(Permission::CreatePersonalQuickReplies) || QuickReply::query()->personal($actor)->exists()) {
            $segments[] = self::SEGMENT_MINE;
        }

        if (Gate::forUser($actor)->allows('superviseAny', QuickReply::class)) {
            $segments[] = self::SEGMENT_OTHERS;
        }

        return $segments;
    }

    /**
     * An unknown or forbidden segment falls back to the first one the actor
     * may open.
     *
     * @param  array<string, mixed>  $validated
     * @return QuickReplyFilters
     */
    public function filters(User $actor, array $validated): array
    {
        $search = $validated['search'] ?? null;
        $availability = $validated['availability'] ?? null;
        $segments = $this->segmentsFor($actor);
        $segment = $validated['segment'] ?? null;
        $sort = $validated['sort'] ?? null;

        return [
            'segment' => in_array($segment, $segments, true) ? $segment : ($segments[0] ?? self::SEGMENT_CORPORATE),
            'search' => trim(is_string($search) ? $search : ''),
            'department' => is_numeric($validated['department'] ?? null) ? (int) $validated['department'] : null,
            'owner' => is_numeric($validated['owner'] ?? null) ? (int) $validated['owner'] : null,
            'record' => RecordState::resolveFor($actor, $validated['record'] ?? null),
            'availability' => is_string($availability) ? $availability : null,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'shortcut',
            'manages' => $actor->holdsPermission(Permission::ManageQuickReplies),
        ];
    }

    /**
     * Filters of the report: without a segment, or with "all", it is the
     * general report of every reply the actor may consult; with the segment
     * of a tab it keeps that tab and its filters.
     *
     * @param  array<string, mixed>  $validated
     * @return QuickReplyFilters
     */
    public function reportFilters(User $actor, array $validated): array
    {
        $requested = $validated['segment'] ?? null;
        $filters = $this->filters($actor, $validated);

        if ($requested === null || $requested === '' || $requested === self::SEGMENT_ALL) {
            return [...$filters, 'segment' => self::SEGMENT_ALL, 'department' => null, 'owner' => null, 'availability' => null];
        }

        return $filters;
    }

    /**
     * @param  QuickReplyFilters  $filters
     * @return Builder<QuickReply>
     */
    public function handle(User $actor, array $filters): Builder
    {
        $search = $filters['search'];
        $segment = $filters['segment'];

        $query = match ($segment) {
            self::SEGMENT_ALL => $this->everySegment($actor, $filters),
            self::SEGMENT_MINE => QuickReply::query()
                ->personal($actor)
                ->inRecordState($filters['record'])
                // The report names the owner's departments (within the actor's scope).
                ->with(['owner:id,name', 'owner.departments' => fn (BelongsToMany $query) => $this->visibleDepartments($query, $actor)]),
            self::SEGMENT_OTHERS => QuickReply::query()
                ->supervisedBy($actor)
                ->inRecordState($filters['record'])
                // The owner's role answers QuickReplyPolicy (root owners stay out of reach).
                ->with(['owner:id,name', 'owner.roles', 'owner.departments' => fn (BelongsToMany $query) => $this->visibleDepartments($query, $actor)])
                ->when($filters['owner'] !== null, fn (Builder $query) => $query->where('owner_user_id', $filters['owner']))
                ->when($filters['department'] !== null, fn (Builder $query) => $query->whereHas(
                    'owner.departments',
                    fn (Builder $departments) => $departments->whereKey($filters['department']),
                )),
            default => QuickReply::query()
                ->tap(fn (Builder $query) => $this->restrictToCorporate($query, $actor, $filters))
                ->inRecordState($filters['record'])
                ->with(['departments' => fn (BelongsToMany $query) => $this->visibleDepartments($query, $actor)])
                ->when($filters['department'] !== null, fn (Builder $query) => $query->whereHas(
                    'departments',
                    fn (Builder $departments) => $departments->whereKey($filters['department']),
                ))
                ->when($filters['availability'] !== null, fn (Builder $query) => $query->where('is_global', $filters['availability'] === 'global')),
        };

        return $query
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';
                $shortcut = '%'.addcslashes(ltrim($search, '/'), '\\%_').'%';
                $query->where(fn (Builder $match) => $match
                    ->where('title', 'like', $term)
                    ->orWhere('shortcut', 'like', $shortcut)
                    ->orWhere('body', 'like', $term));
            })
            ->orderByDesc('is_active')
            ->when($filters['sort'] === 'title', fn (Builder $query) => $query->orderBy('title'))
            ->when($filters['sort'] === 'recent', fn (Builder $query) => $query->orderByDesc('updated_at'))
            ->orderBy('shortcut')
            ->orderBy('id');
    }

    /**
     * Replies of every segment the actor may open (the general report).
     *
     * @param  QuickReplyFilters  $filters
     * @return Builder<QuickReply>
     */
    private function everySegment(User $actor, array $filters): Builder
    {
        $segments = $this->segmentsFor($actor);

        return QuickReply::query()
            ->where(function (Builder $authorized) use ($actor, $filters, $segments): void {
                if ($segments === []) {
                    $authorized->whereRaw('1 = 0');

                    return;
                }

                if (in_array(self::SEGMENT_CORPORATE, $segments, true)) {
                    $authorized->orWhere(fn (Builder $corporate) => $this->restrictToCorporate($corporate, $actor, $filters));
                }

                if (in_array(self::SEGMENT_MINE, $segments, true)) {
                    $authorized->orWhere(fn (Builder $mine) => $mine->personal($actor));
                }

                if (in_array(self::SEGMENT_OTHERS, $segments, true)) {
                    $authorized->orWhere(fn (Builder $others) => $others->supervisedBy($actor));
                }
            })
            ->inRecordState($filters['record'])
            ->with([
                'departments' => fn (BelongsToMany $query) => $this->visibleDepartments($query, $actor),
                'owner:id,name',
                'owner.departments' => fn (BelongsToMany $query) => $this->visibleDepartments($query, $actor),
            ]);
    }

    /**
     * Corporate replies of the actor: every one within their scope for
     * managers, the ones they can insert otherwise.
     *
     * @param  Builder<QuickReply>  $query
     * @param  QuickReplyFilters  $filters
     */
    private function restrictToCorporate(Builder $query, User $actor, array $filters): void
    {
        $query->corporate()
            ->when($filters['manages'], fn (Builder $query) => $query->visibleTo($actor), fn (Builder $query) => $query->usableBy($actor));
    }

    /**
     * Departments (of a reply or of an owner) as the actor may see them, for
     * eager loading.
     *
     * @template TDeclaringModel of QuickReply|User
     *
     * @param  BelongsToMany<Department, TDeclaringModel>  $query
     */
    private function visibleDepartments(BelongsToMany $query, User $actor): void
    {
        $query->select('departments.id', 'departments.name', 'departments.color', 'departments.is_active')
            ->hidingDeletedFrom($actor)
            ->orderBy('departments.name');
    }
}
