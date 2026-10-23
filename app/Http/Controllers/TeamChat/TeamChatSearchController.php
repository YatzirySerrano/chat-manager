<?php

namespace App\Http\Controllers\TeamChat;

use App\Actions\TeamChat\TeamChatSearch;
use App\Http\Controllers\Controller;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Message search within the viewer's own threads.
 */
class TeamChatSearchController extends Controller
{
    public function __invoke(Request $request, TeamChatSearch $search): JsonResponse
    {
        Gate::authorize('viewAny', TeamThread::class);

        $validated = $request->validate(['q' => ['required', 'string', 'max:80']]);

        /** @var User $viewer */
        $viewer = $request->user();

        $found = $search->messages($viewer, (string) $validated['q']);

        return response()->json(['data' => $found['results'], 'has_more' => $found['has_more']]);
    }
}
