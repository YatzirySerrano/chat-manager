<?php

namespace App\Http\Controllers\TeamChat;

use App\Actions\TeamChat\DeleteTeamMessage;
use App\Actions\TeamChat\EditTeamMessage;
use App\Actions\TeamChat\PostTeamMessage;
use App\Actions\TeamChat\TeamMessageHistory;
use App\Actions\TeamChat\ToggleTeamMessageReaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\TeamChat\StoreTeamMessageRequest;
use App\Http\Requests\TeamChat\UpdateTeamMessageRequest;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamChatPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * JSON endpoints of a thread's messages, used by the chat window: history
 * pages, sending, editing, deleting and reactions. Every call authorizes the viewer as
 * a current participant again.
 */
class TeamMessageController extends Controller
{
    public function __construct(
        private TeamMessageHistory $history,
        private TeamChatPresenter $presenter,
    ) {}

    /**
     * A page of history: `before` (older), `after` (newer) or `ids`
     * (specific messages to refresh after a real-time signal).
     */
    public function index(Request $request, TeamThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);

        $validated = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
            'ids' => ['nullable', 'array', 'max:50'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $canPost = Gate::allows('post', $thread);

        if (isset($validated['ids'])) {
            return response()->json([
                'data' => $this->presenter->messages($this->history->only($thread, array_values(array_map('intval', $validated['ids']))), $viewer, $canPost),
            ]);
        }

        $page = match (true) {
            isset($validated['before']) => $this->history->before($thread, (int) $validated['before']),
            isset($validated['after']) => $this->history->after($thread, (int) $validated['after']),
            default => $this->history->latest($thread),
        };

        return response()->json([
            'data' => $this->presenter->messages($page['messages'], $viewer, $canPost),
            'has_more_before' => $page['has_more_before'],
            'has_more_after' => $page['has_more_after'],
        ]);
    }

    public function store(StoreTeamMessageRequest $request, TeamThread $thread, PostTeamMessage $postTeamMessage): JsonResponse
    {
        /** @var User $author */
        $author = $request->user();
        $message = $postTeamMessage->handle($thread, $author, $request->body(), $request->replyToMessageId(), $request->attachments(), $request->mentions());

        return response()->json(['data' => $this->present($thread, $message, $author)], 201);
    }

    public function update(UpdateTeamMessageRequest $request, TeamThread $thread, TeamMessage $message, EditTeamMessage $editTeamMessage): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $changed = $editTeamMessage->handle($message, $actor, $request->body());

        return response()->json(['data' => $this->present($thread, $message, $actor), 'changed' => $changed]);
    }

    public function destroy(Request $request, TeamThread $thread, TeamMessage $message, DeleteTeamMessage $deleteTeamMessage): JsonResponse
    {
        Gate::authorize('deleteMessage', [$thread, $message]);

        /** @var User $actor */
        $actor = $request->user();
        $deleteTeamMessage->handle($message, $actor);

        return response()->json(['data' => $this->present($thread, $message, $actor)]);
    }

    /**
     * Add the viewer's reaction (idempotent). Answers the message as shown
     * now, with every participant's reactions.
     */
    public function react(Request $request, TeamThread $thread, TeamMessage $message, ToggleTeamMessageReaction $reactions): JsonResponse
    {
        Gate::authorize('react', [$thread, $message]);

        /** @var User $actor */
        $actor = $request->user();
        $reactions->add($message, $actor, $this->validatedEmoji($request));

        return response()->json(['data' => $this->present($thread, $message, $actor)]);
    }

    /**
     * Withdraw the viewer's reaction (idempotent).
     */
    public function unreact(Request $request, TeamThread $thread, TeamMessage $message, ToggleTeamMessageReaction $reactions): JsonResponse
    {
        Gate::authorize('react', [$thread, $message]);

        /** @var User $actor */
        $actor = $request->user();
        $reactions->remove($message, $actor, $this->validatedEmoji($request));

        return response()->json(['data' => $this->present($thread, $message, $actor)]);
    }

    private function validatedEmoji(Request $request): string
    {
        return (string) $request->validate(
            ['emoji' => ['required', 'string', Rule::in((array) config('team-chat.reactions'))]],
            ['emoji.in' => 'Esa reacción no está disponible.'],
        )['emoji'];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TeamThread $thread, TeamMessage $message, User $viewer): array
    {
        return $this->presenter->messages($this->history->only($thread, [$message->id]), $viewer, Gate::allows('post', $thread))[0];
    }
}
