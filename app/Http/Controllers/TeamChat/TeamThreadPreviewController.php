<?php

namespace App\Http\Controllers\TeamChat;

use App\Actions\TeamChat\TeamMessageHistory;
use App\Http\Controllers\Controller;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Personalization\ChatAppearances;
use App\Services\TeamChat\TeamChatPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * The preview of an internal chat from the thread list (long press, right
 * click or «Vista previa»): its latest messages without opening it.
 *
 * It only reads, like the inbox preview: the viewer's read position
 * (team-chat.threads.read is a separate route it never calls), unread
 * counters, notifications, typing indicators and the thread stay as they
 * were. Only current participants preview a thread (404 otherwise), as
 * with every route of the internal chat. It is shown with the viewer's own
 * effective chat colors for the thread, as the chat shows them, only read
 * (no appearance row is created or changed).
 */
class TeamThreadPreviewController extends Controller
{
    /** Messages of the preview. */
    public const PREVIEW_SIZE = 20;

    public function __construct(
        private TeamMessageHistory $history,
        private TeamChatPresenter $presenter,
        private ChatAppearances $appearances,
    ) {}

    public function show(Request $request, TeamThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);

        /** @var User $viewer */
        $viewer = $request->user();
        $page = $this->history->latest($thread, self::PREVIEW_SIZE);

        return response()->json([
            // Presented as for someone who cannot post: no action is offered.
            'data' => $this->presenter->messages($page['messages'], $viewer, false),
            'has_more' => $page['has_more_before'],
            // Only what the bubbles need, not the editor's choices and presets.
            'appearance' => Arr::only($this->appearances->forViewer($viewer, $thread), ['source', 'colors']),
        ]);
    }
}
