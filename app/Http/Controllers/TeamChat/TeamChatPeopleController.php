<?php

namespace App\Http\Controllers\TeamChat;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamChatDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * People the viewer may start a chat with or add to a group, for the
 * pickers. Only the minimum is exposed: name, departments, availability.
 */
class TeamChatPeopleController extends Controller
{
    public const LIMIT = 30;

    public function __invoke(Request $request, TeamChatDirectory $directory): JsonResponse
    {
        Gate::authorize('viewAny', TeamThread::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'exclude_thread' => ['nullable', 'integer'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $search = trim((string) ($validated['search'] ?? ''));

        $query = $directory->contactableUsers($viewer)
            ->with(['departments' => fn ($departments) => $departments->notDeleted()->orderBy('name')->select(['departments.id', 'departments.name', 'departments.color'])])
            ->orderBy('name')
            ->limit(self::LIMIT);

        if ($search !== '') {
            $term = '%'.addcslashes($search, '\\%_').'%';
            $query->where('name', 'like', $term);
        }

        $alreadyIn = [];

        if (isset($validated['exclude_thread'])) {
            $thread = TeamThread::query()->find((int) $validated['exclude_thread']);

            if ($thread !== null && Gate::allows('view', $thread)) {
                $alreadyIn = array_map('intval', $thread->activeParticipations()->pluck('user_id')->all());
            }
        }

        return response()->json([
            'data' => array_values($query->get()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'availability' => $user->availability->value,
                'departments' => array_values($user->departments->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'color' => $department->color->value,
                ])->all()),
                'already_participant' => in_array($user->id, $alreadyIn, true),
            ])->all()),
            'reaches_everyone' => $directory->reachesEveryone($viewer),
        ]);
    }
}
