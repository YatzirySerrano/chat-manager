<?php

namespace App\Http\Controllers\Categories;

use App\Actions\Categories\CreateConversationCategory;
use App\Actions\Categories\DeleteConversationCategory;
use App\Actions\Categories\RestoreConversationCategory;
use App\Actions\Categories\UpdateConversationCategory;
use App\Enums\ColorSwatch;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\ConversationCategoryRequest;
use App\Http\Resources\ConversationCategoryResource;
use App\Models\ConversationCategory;
use App\Models\User;
use App\Support\SaveToast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConversationCategoryController extends Controller
{
    /**
     * Catalog of request categories with how many conversations carry each
     * one and the service rules it overrides. Deleted categories are only
     * listed for people who can consult deleted records.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ConversationCategory::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'record' => ['nullable', Rule::enum(RecordState::class)],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $record = RecordState::resolveFor($actor, $filters['record'] ?? null);

        $categories = ConversationCategory::query()
            ->inRecordState($record)
            ->withCount('conversations')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('categories/Index', [
            'categories' => ConversationCategoryResource::collection($categories),
            'filters' => [
                'search' => $search,
                'record' => $record->value,
            ],
            'recordStates' => RecordState::optionsFor($actor),
            'colors' => ColorSwatch::values(),
            'can' => [
                'manage' => $actor->can('create', ConversationCategory::class),
            ],
        ]);
    }

    public function store(ConversationCategoryRequest $request, CreateConversationCategory $createCategory): RedirectResponse
    {
        $category = $createCategory->handle($request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Categoría {$category->name} creada."]);

        return back();
    }

    public function update(ConversationCategoryRequest $request, ConversationCategory $category, UpdateConversationCategory $updateCategory): RedirectResponse
    {
        $changed = $updateCategory->handle($category, $request->payload(), $request->user());

        SaveToast::flash($changed, 'Categoría actualizada.');

        return back();
    }

    /**
     * Logical deletion: the category can no longer be chosen and stays on
     * the conversations that carry it.
     */
    public function destroy(Request $request, ConversationCategory $category, DeleteConversationCategory $deleteCategory): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $deleteCategory->handle($category, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Categoría {$category->name} eliminada. Se conserva en las conversaciones que ya la tenían."]);

        return back();
    }

    public function restore(Request $request, ConversationCategory $category, RestoreConversationCategory $restoreCategory): RedirectResponse
    {
        Gate::authorize('restore', $category);

        $restoreCategory->handle($category, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Categoría {$category->name} restaurada."]);

        return back();
    }
}
