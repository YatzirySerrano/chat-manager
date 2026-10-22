<?php

namespace App\Http\Controllers\Tags;

use App\Actions\Tags\CreateTag;
use App\Actions\Tags\DeleteTag;
use App\Actions\Tags\RestoreTag;
use App\Actions\Tags\TagCatalogQuery;
use App\Actions\Tags\UpdateTag;
use App\Enums\ColorSwatch;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tags\TagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Models\User;
use App\Services\Exports\ReportExporter;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    /**
     * Tag catalog with how many contacts and conversations use each tag
     * (the same query as the tags report).
     */
    public function index(Request $request, TagCatalogQuery $catalog, ReportExporter $exporter): Response
    {
        Gate::authorize('viewAny', Tag::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'record' => ['nullable', Rule::enum(RecordState::class)],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $record = RecordState::resolveFor($actor, $filters['record'] ?? null);

        $tags = $catalog->handle($actor, ['search' => $search, 'record' => $record->value])
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('tags/Index', [
            'tags' => TagResource::collection($tags),
            'filters' => [
                'search' => $search,
                'record' => $record->value,
            ],
            'recordStates' => RecordState::optionsFor($actor),
            'colors' => ColorSwatch::values(),
            'can' => [
                'manage' => $actor->can('create', Tag::class),
            ],
            'canExport' => $exporter->allowsReport('tags', $actor),
        ]);
    }

    public function store(TagRequest $request, CreateTag $createTag): RedirectResponse
    {
        $tag = $createTag->handle($request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Etiqueta {$tag->name} creada."]);

        return back();
    }

    public function update(TagRequest $request, Tag $tag, UpdateTag $updateTag): RedirectResponse
    {
        $changed = $updateTag->handle($tag, $request->payload(), $request->user());

        SaveToast::flash($changed, 'Etiqueta actualizada.');

        return back();
    }

    /**
     * Logical deletion: the tag can no longer be assigned and stays where it
     * was applied.
     */
    public function destroy(Request $request, Tag $tag, DeleteTag $deleteTag): RedirectResponse
    {
        Gate::authorize('delete', $tag);

        $deleteTag->handle($tag, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Etiqueta {$tag->name} eliminada. Se conserva donde ya estaba aplicada."]);

        return back();
    }

    public function restore(Request $request, Tag $tag, RestoreTag $restoreTag): RedirectResponse
    {
        Gate::authorize('restore', $tag);

        $restoreTag->handle($tag, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Etiqueta {$tag->name} restaurada."]);

        return back();
    }
}
