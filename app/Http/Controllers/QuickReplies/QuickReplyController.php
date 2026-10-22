<?php

namespace App\Http\Controllers\QuickReplies;

use App\Actions\QuickReplies\CreateQuickReply;
use App\Actions\QuickReplies\DeleteQuickReply;
use App\Actions\QuickReplies\QuickReplyListQuery;
use App\Actions\QuickReplies\RestoreQuickReply;
use App\Actions\QuickReplies\UpdateQuickReply;
use App\Enums\DataScope;
use App\Enums\QuickReplyVariable;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuickReplies\QuickReplyRequest;
use App\Http\Resources\QuickReplyResource;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\Exports\ReportExporter;
use App\Services\QuickReplyRenderer;
use App\Support\SaveToast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class QuickReplyController extends Controller
{
    /**
     * Managers see every reply within their scope (deleted ones only when they
     * can consult deleted records); users that can only use replies see the
     * ones they can insert.
     */
    public function index(Request $request, QuickReplyRenderer $renderer, QuickReplyListQuery $list, ReportExporter $exporter): Response
    {
        Gate::authorize('viewAny', QuickReply::class);

        /** @var User $actor */
        $actor = $request->user();
        $actor->loadMissing(['roles.permissions', 'departments:id']);

        $filters = $list->filters($actor, $request->validate(QuickReplyListQuery::rules()));
        $search = $filters['search'];
        $departmentId = $filters['department'];
        $record = $filters['record'];
        $availability = $filters['availability'];
        $canManage = $filters['manages'];

        $quickReplies = $list->handle($actor, $filters)
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('quick-replies/Index', [
            'quickReplies' => QuickReplyResource::collection($quickReplies),
            'filters' => [
                'search' => $search,
                'department' => $departmentId,
                'record' => $record->value,
                'availability' => $availability,
            ],
            'recordStates' => $canManage ? RecordState::optionsFor($actor) : [],
            'departments' => $this->departmentOptions(Department::query()->visibleTo($actor)->notDeleted()),
            'assignableDepartments' => $canManage ? $this->departmentOptions($this->assignableDepartments($actor)->notDeleted()) : [],
            'variables' => QuickReplyVariable::options(),
            'sampleValues' => $renderer->sampleContext(),
            'bodyMaxLength' => QuickReplyRequest::BODY_MAX_LENGTH,
            'can' => [
                'create' => $actor->can('create', QuickReply::class),
                'manageGlobal' => $actor->can('manageGlobal', QuickReply::class),
                'use' => $actor->can('use', QuickReply::class),
            ],
            'canExport' => $exporter->allowsReport('quick-replies', $actor),
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
        $changed = $updateQuickReply->handle($quickReply, $request->payload(), $request->user());

        SaveToast::flash($changed, 'Respuesta rápida actualizada.');

        return back();
    }

    /**
     * Logical deletion: the reply can no longer be used and its shortcut
     * stays reserved.
     */
    public function destroy(Request $request, QuickReply $quickReply, DeleteQuickReply $deleteQuickReply): RedirectResponse
    {
        Gate::authorize('delete', $quickReply);

        $deleteQuickReply->handle($quickReply, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Respuesta rápida /{$quickReply->shortcut} eliminada. Su atajo queda reservado."]);

        return back();
    }

    public function restore(Request $request, QuickReply $quickReply, RestoreQuickReply $restoreQuickReply): RedirectResponse
    {
        Gate::authorize('restore', $quickReply);

        $restoreQuickReply->handle($quickReply, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Respuesta rápida /{$quickReply->shortcut} restaurada."]);

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
