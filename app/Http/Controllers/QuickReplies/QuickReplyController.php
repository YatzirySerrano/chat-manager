<?php

namespace App\Http\Controllers\QuickReplies;

use App\Actions\QuickReplies\CreateQuickReply;
use App\Actions\QuickReplies\DeleteQuickReply;
use App\Actions\QuickReplies\QuickReplyListQuery;
use App\Actions\QuickReplies\RestoreQuickReply;
use App\Actions\QuickReplies\UpdateQuickReply;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\QuickReplyVariable;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuickReplies\QuickReplyRequest;
use App\Http\Resources\QuickReplyResource;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\Exports\ReportExporter;
use App\Services\Personalization\PersonalItemSupervision;
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
     * Segments: corporate replies (managers see every reply within their
     * scope, deleted ones only when they can consult deleted records; users
     * that can only use replies see the ones they can insert), the actor's
     * own personal replies, and other users' personal replies for
     * supervisors.
     */
    public function index(Request $request, QuickReplyRenderer $renderer, QuickReplyListQuery $list, ReportExporter $exporter): Response
    {
        Gate::authorize('viewAny', QuickReply::class);

        /** @var User $actor */
        $actor = $request->user();
        $actor->loadMissing(['roles.permissions', 'permissions', 'departments:id']);

        $filters = $list->filters($actor, $request->validate(QuickReplyListQuery::rules()));
        $segment = $filters['segment'];
        $canManage = $filters['manages'];
        $segments = $list->segmentsFor($actor);

        $quickReplies = $list->handle($actor, $filters)
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('quick-replies/Index', [
            'quickReplies' => QuickReplyResource::collection($quickReplies),
            'filters' => [
                'segment' => $segment,
                'search' => $filters['search'],
                'department' => $filters['department'],
                'owner' => $filters['owner'],
                'record' => $filters['record']->value,
                'availability' => $filters['availability'],
                'sort' => $filters['sort'],
            ],
            'segments' => $this->segmentOptions($actor, $segments),
            'recordStates' => $canManage || $segment !== QuickReplyListQuery::SEGMENT_CORPORATE ? RecordState::optionsFor($actor) : [],
            'departments' => $this->departmentOptions(Department::query()->visibleTo($actor)->notDeleted()),
            'assignableDepartments' => $canManage ? $this->departmentOptions($this->assignableDepartments($actor)->notDeleted()) : [],
            'owners' => in_array(QuickReplyListQuery::SEGMENT_OTHERS, $segments, true) ? $this->ownerOptions($actor) : [],
            'variables' => QuickReplyVariable::options(),
            'sampleValues' => $renderer->sampleContext(),
            'bodyMaxLength' => QuickReplyRequest::BODY_MAX_LENGTH,
            'can' => [
                'create' => $actor->can('create', QuickReply::class),
                'createPersonal' => $actor->can('createPersonal', QuickReply::class),
                'manageGlobal' => $actor->can('manageGlobal', QuickReply::class),
                'use' => $actor->can('use', QuickReply::class),
            ],
            'canExport' => $exporter->allowsReport('quick-replies', $actor),
        ]);
    }

    public function store(QuickReplyRequest $request, CreateQuickReply $createQuickReply): RedirectResponse
    {
        $quickReply = $createQuickReply->handle($request->payload(), $request->user());

        $message = $quickReply->isPersonal()
            ? "Respuesta personal /{$quickReply->shortcut} creada. Solo tú la verás al escribir «/»."
            : "Respuesta rápida /{$quickReply->shortcut} creada.";

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function update(QuickReplyRequest $request, QuickReply $quickReply, UpdateQuickReply $updateQuickReply): RedirectResponse
    {
        $changed = $updateQuickReply->handle($quickReply, $request->payload(), $request->user());

        SaveToast::flash($changed, 'Respuesta rápida actualizada.');

        return back();
    }

    /**
     * Logical deletion: the reply can no longer be used. The shortcut of a
     * corporate reply stays reserved; that of a personal reply is freed for
     * its owner.
     */
    public function destroy(Request $request, QuickReply $quickReply, DeleteQuickReply $deleteQuickReply): RedirectResponse
    {
        Gate::authorize('delete', $quickReply);

        $deleteQuickReply->handle($quickReply, $request->user());

        $message = $quickReply->isPersonal()
            ? "Respuesta personal /{$quickReply->shortcut} eliminada. Ya no aparecerá al escribir «/»."
            : "Respuesta rápida /{$quickReply->shortcut} eliminada. Su atajo queda reservado.";

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

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
     * @param  list<string>  $segments
     * @return list<array{value: string, label: string, count: int}>
     */
    private function segmentOptions(User $actor, array $segments): array
    {
        return array_map(fn (string $segment): array => [
            'value' => $segment,
            'label' => match ($segment) {
                QuickReplyListQuery::SEGMENT_MINE => 'Mis respuestas',
                QuickReplyListQuery::SEGMENT_OTHERS => 'De otros usuarios',
                default => 'Corporativas',
            },
            'count' => match ($segment) {
                QuickReplyListQuery::SEGMENT_MINE => QuickReply::query()->personal($actor)->notDeleted()->count(),
                QuickReplyListQuery::SEGMENT_OTHERS => QuickReply::query()->supervisedBy($actor)->notDeleted()->count(),
                default => $actor->holdsPermission(Permission::ManageQuickReplies)
                    ? QuickReply::query()->corporate()->visibleTo($actor)->notDeleted()->count()
                    : QuickReply::query()->corporate()->usableBy($actor)->count(),
            },
        ], $segments);
    }

    /**
     * Users of the actor's scope that have personal replies, for the owner
     * filter of the supervision segment.
     *
     * @return list<array{id: int, name: string}>
     */
    private function ownerOptions(User $actor): array
    {
        return array_values(app(PersonalItemSupervision::class)->owners($actor)
            ->whereHas('personalQuickReplies')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all());
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
