<?php

namespace App\Http\Controllers\QuickReplies;

use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\PrivilegeGuard;
use App\Services\QuickReplyRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AvailableQuickReplyController extends Controller
{
    /**
     * Maximum number of replies offered by the "/" picker at once.
     */
    private const LIMIT = 20;

    /**
     * Active replies the actor can insert, filtered by what was typed after
     * "/" and rendered for the optional contact and department. A contact or
     * department outside the actor's scope is ignored, never revealed.
     *
     * With a department the list is limited to global replies and those of
     * that department, so a shortcut repeated in other departments of the
     * agent is never offered twice. Every reply names the departments it
     * belongs to (only those the actor reaches) to tell apart same-shortcut
     * replies when no department is given.
     *
     * The actor's own personal replies are always offered (never anybody
     * else's) and listed first, flagged with `is_personal` so the picker
     * groups them under «Mis respuestas».
     */
    public function __invoke(Request $request, QuickReplyRenderer $renderer, PrivilegeGuard $guard): JsonResponse
    {
        Gate::authorize('use', QuickReply::class);

        /** @var User $actor */
        $actor = $request->user();

        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'contact_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $search = mb_strtolower(ltrim(trim((string) ($input['q'] ?? '')), '/'));

        $contact = isset($input['contact_id'])
            ? Contact::query()->visibleTo($actor)->find((int) $input['contact_id'])
            : null;

        $department = isset($input['department_id']) && $guard->reachesDepartment($actor, (int) $input['department_id'])
            ? Department::query()->find((int) $input['department_id'])
            : null;

        $context = $renderer->context($actor, $contact, $department);

        $quickReplies = QuickReply::query()
            ->usableBy($actor)
            ->when($department !== null, fn (Builder $query) => $query->where(fn (Builder $inDepartment) => $inDepartment
                ->where('owner_user_id', $actor->id)
                ->orWhere('is_global', true)
                ->orWhereHas('departments', fn (Builder $departments) => $departments->whereKey($department?->id)->where('departments.is_active', true))))
            ->with(['departments' => fn (BelongsToMany $departments) => $departments
                ->select('departments.id', 'departments.name')
                ->where('departments.is_active', true)
                ->orderBy('departments.name')])
            ->orderByRaw('CASE WHEN owner_user_id IS NULL THEN 1 ELSE 0 END')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $prefix = addcslashes($search, '\\%_').'%';
                $term = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match) => $match->where('shortcut', 'like', $prefix)->orWhere('title', 'like', $term))
                    ->orderByRaw('CASE WHEN shortcut LIKE ? THEN 0 ELSE 1 END', [$prefix]);
            })
            ->orderBy('shortcut')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'title', 'shortcut', 'body', 'is_global', 'owner_user_id']);

        $reachableDepartmentIds = $actor->dataScope() === DataScope::Global ? null : $actor->departmentIds();

        return response()->json([
            'data' => $quickReplies->map(function (QuickReply $quickReply) use ($renderer, $context, $reachableDepartmentIds): array {
                $rendered = $renderer->render($quickReply, $context);

                return [
                    'id' => $quickReply->id,
                    'title' => $quickReply->title,
                    'shortcut' => $quickReply->shortcut,
                    'body' => $quickReply->body,
                    'is_global' => $quickReply->is_global,
                    'is_personal' => $quickReply->isPersonal(),
                    'departments' => array_values($quickReply->departments
                        ->filter(fn (Department $department): bool => $reachableDepartmentIds === null || in_array($department->id, $reachableDepartmentIds, true))
                        ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])
                        ->all()),
                    'text' => $rendered['text'],
                    'missing' => $rendered['missing'],
                ];
            })->values()->all(),
        ]);
    }
}
