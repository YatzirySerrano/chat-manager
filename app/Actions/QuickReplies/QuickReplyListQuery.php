<?php

namespace App\Actions\QuickReplies;

use App\Enums\Permission;
use App\Enums\RecordState;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\Rule;

/**
 * The quick replies list as the quick replies screen shows it: managers see
 * every reply within their scope (deleted ones only when they can consult
 * deleted records); users that can only use replies see the ones they can
 * insert. Search over title, shortcut and body, department and
 * availability (global or by department).
 *
 * Shared by the quick replies screen and the quick replies report.
 *
 * @phpstan-type QuickReplyFilters array{search: string, department: int|null, record: RecordState, availability: string|null, manages: bool}
 */
class QuickReplyListQuery
{
    /**
     * Validation rules of the list filters (query string).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'integer'],
            'record' => ['nullable', Rule::enum(RecordState::class)],
            'availability' => ['nullable', Rule::in(['global', 'departments'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return QuickReplyFilters
     */
    public function filters(User $actor, array $validated): array
    {
        $search = $validated['search'] ?? null;
        $availability = $validated['availability'] ?? null;

        return [
            'search' => trim(is_string($search) ? $search : ''),
            'department' => is_numeric($validated['department'] ?? null) ? (int) $validated['department'] : null,
            'record' => RecordState::resolveFor($actor, $validated['record'] ?? null),
            'availability' => is_string($availability) ? $availability : null,
            'manages' => $actor->holdsPermission(Permission::ManageQuickReplies),
        ];
    }

    /**
     * @param  QuickReplyFilters  $filters
     * @return Builder<QuickReply>
     */
    public function handle(User $actor, array $filters): Builder
    {
        $search = $filters['search'];

        return QuickReply::query()
            ->when($filters['manages'], fn (Builder $query) => $query->visibleTo($actor), fn (Builder $query) => $query->usableBy($actor))
            ->inRecordState($filters['record'])
            ->with(['departments' => fn (BelongsToMany $query) => $query
                ->select('departments.id', 'departments.name', 'departments.color', 'departments.is_active')
                ->hidingDeletedFrom($actor)
                ->orderBy('departments.name')])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';
                $shortcut = '%'.addcslashes(ltrim($search, '/'), '\\%_').'%';
                $query->where(fn (Builder $match) => $match
                    ->where('title', 'like', $term)
                    ->orWhere('shortcut', 'like', $shortcut)
                    ->orWhere('body', 'like', $term));
            })
            ->when($filters['department'] !== null, fn (Builder $query) => $query->whereHas(
                'departments',
                fn (Builder $departments) => $departments->whereKey($filters['department']),
            ))
            ->when($filters['availability'] !== null, fn (Builder $query) => $query->where('is_global', $filters['availability'] === 'global'))
            ->orderByDesc('is_active')
            ->orderBy('shortcut')
            ->orderBy('id');
    }
}
