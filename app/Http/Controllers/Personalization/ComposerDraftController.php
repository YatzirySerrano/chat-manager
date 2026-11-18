<?php

namespace App\Http\Controllers\Personalization;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Personalization\ComposerDrafts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The viewer's unsent text of a chat they can see (a customer conversation
 * or an internal thread they participate in), so it follows them from the
 * phone to the computer. A chat out of their reach answers as not found,
 * before any validation.
 */
class ComposerDraftController extends Controller
{
    public function __construct(private ComposerDrafts $drafts) {}

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->read($request, $conversation);
    }

    public function update(Request $request, Conversation $conversation): Response
    {
        return $this->write($request, $conversation);
    }

    public function showThread(Request $request, TeamThread $thread): JsonResponse
    {
        return $this->read($request, $thread);
    }

    public function updateThread(Request $request, TeamThread $thread): Response
    {
        return $this->write($request, $thread);
    }

    private function read(Request $request, Conversation|TeamThread $chat): JsonResponse
    {
        abort_unless(Gate::allows('view', $chat), 404);

        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json(['drafts' => (object) $this->drafts->forViewer($viewer, $chat)]);
    }

    private function write(Request $request, Conversation|TeamThread $chat): Response
    {
        abort_unless(Gate::allows('view', $chat), 404);

        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in($this->drafts->modes($chat))],
            'body' => ['present', 'nullable', 'string', 'max:'.ComposerDrafts::MAX_LENGTH],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();

        $this->drafts->save($viewer, $chat, $validated['mode'], (string) ($validated['body'] ?? ''));

        return response()->noContent();
    }
}
