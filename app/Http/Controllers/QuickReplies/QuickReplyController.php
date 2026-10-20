<?php

namespace App\Http\Controllers\QuickReplies;

use App\Actions\QuickReplies\CreateQuickReply;
use App\Actions\QuickReplies\DeleteQuickReply;
use App\Actions\QuickReplies\UpdateQuickReply;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\QuickReplyVariable;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuickReplies\QuickReplyRequest;
use App\Http\Resources\QuickReplyResource;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\QuickReplyRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuickReplyController extends Controller
{
    /**
     * Managers see every reply within their scope (inactive included); users
     * that can only use replies see the ones they can insert.
     */
    public function index(Request $request, QuickReplyRenderer $renderer): Response
    {
        Gate::authorize('viewAny', QuickReply::class);

        /** @var User $actor */
        $actor = $request->user();
        $actor->loadMissing(['roles.permissions', 'departments:id']);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'availability' => ['nullable', Rule::in(['global', 'departments'])],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $departmentId = isset($filters['department']) ? (int) $filters['department'] : null;
        $status = $filters['status'] ?? null;
        $availability = $filters['availability'] ?? null;
        $canManage = $actor->holdsPermission(Permission::ManageQuickReplies);

        $quickReplies = QuickReply::query()
            ->when($canManage, fn (Builder $query) => $query->visibleTo($actor), fn (Builder $query) => $query->usableBy($actor))
            ->with(['departments' => fn (BelongsToMany $query) => $query
                ->select('departments.id', 'departments.name', 'departments.color', 'departments.is_active')
                ->orderBy('departments.name')])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';
                $shortcut = '%'.addcslashes(ltrim($search, '/'), '\\%_').'%';
                $query->where(fn (Builder $match) => $match
                    ->where('title', 'like', $term)
                    ->orWhere('shortcut', 'like', $shortcut)
                    ->orWhere('body', 'like', $term));
            })
            ->when($departmentId !== null, fn (Builder $query) => $query->whereHas(
                'departments',
                fn (Builder $departments) => $departments->whereKey($departmentId),
            ))
            ->when($status !== null, fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->when($availability !== null, fn (Builder $query) => $query->where('is_global', $availability === 'global'))
            ->orderByDesc('is_active')
            ->orderBy('shortcut')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('quick-replies/Index', [
            'quickReplies' => QuickReplyResource::collection($quickReplies),
            'filters' => [
                'search' => $search,
                'department' => $departmentId,
                'status' => $status,
                'availability' => $availability,
            ],
            'departments' => $this->departmentOptions(Department::query()->visibleTo($actor)),
            'assignableDepartments' => $canManage ? $this->departmentOptions($this->assignableDepartments($actor)) : [],
            'variables' => QuickReplyVariable::options(),
            'sampleValues' => $renderer->sampleContext(),
            'bodyMaxLength' => QuickReplyRequest::BODY_MAX_LENGTH,
            'can' => [
                'create' => $actor->can('create', QuickReply::class),
                'manageGlobal' => $actor->can('manageGlobal', QuickReply::class),
                'use' => $actor->can('use', QuickReply::class),
            ],
        ]);
    }

    public function store(QuickReplyRequest $request, CreateQuickReply $createQuickReply): RedirectResponse
    {
        $quickReply = $createQuickReply->handle($request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Respuesta rápida /{$quickReply->shortcut} creada."]);

        return back();
    }

    public function update(QuickReplyRequest $request, QuickReply $quickReply, UpdateQuickReply $updateQuickReply): RedirectResponse
    {
        $updateQuickReply->handle($quickReply, $request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Respuesta rápida actualizada.']);

        return back();
    }

    public function destroy(Request $request, QuickReply $quickReply, DeleteQuickReply $deleteQuickReply): RedirectResponse
    {
        Gate::authorize('delete', $quickReply);

        $deleteQuickReply->handle($quickReply, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Respuesta rápida /{$quickReply->shortcut} eliminada."]);

        return back();
    }

    /**
     * Departments the actor can make replies available to.
     *
     * @return Builder<Department>
     */
    private function assignableDepartments(User $actor): Builder
    {
        return Department::query()
            ->when($actor->dataScope() !== DataScope::Global, fn (Builder $query) => $query->whereKey($actor->departmentIds()));
    }

    /**
     * @param  Builder<Department>  $query
     * @return list<array{id: int, name: string, color: string, is_active: bool}>
     */
    private function departmentOptions(Builder $query): array
    {
        return array_values($query
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'is_active'])
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'color' => $department->color->value,
                'is_active' => $department->is_active,
            ])
            ->all());
    }
}
