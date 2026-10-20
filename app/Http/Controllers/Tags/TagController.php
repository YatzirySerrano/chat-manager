<?php

namespace App\Http\Controllers\Tags;

use App\Actions\Tags\CreateTag;
use App\Actions\Tags\UpdateTag;
use App\Enums\ColorSwatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tags\TagRequest;
use App\Http\Resources\TagResource;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    /**
     * Tag catalog with how many contacts and conversations use each tag.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Tag::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;

        $tags = Tag::query()
            // Usage is counted only over the records the actor is allowed to see.
            ->withCount([
                'contacts' => fn (Builder $query) => $this->visibleContacts($query, $actor),
                'conversations' => fn (Builder $query) => $this->visibleConversations($query, $actor),
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when($status !== null, fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('tags/Index', [
            'tags' => TagResource::collection($tags),
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'colors' => ColorSwatch::values(),
            'can' => [
                'manage' => $actor->can('create', Tag::class),
            ],
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
        $updateTag->handle($tag, $request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Etiqueta actualizada.']);

        return back();
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function visibleContacts(Builder $query, User $actor): void
    {
        $query->visibleTo($actor);
    }

    /**
     * @param  Builder<Conversation>  $query
     */
    private function visibleConversations(Builder $query, User $actor): void
    {
        $query->visibleTo($actor);
    }
}
