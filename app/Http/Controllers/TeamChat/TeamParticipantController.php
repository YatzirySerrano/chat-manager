<?php

namespace App\Http\Controllers\TeamChat;

use App\Actions\TeamChat\AddGroupParticipants;
use App\Actions\TeamChat\ChangeParticipantRole;
use App\Actions\TeamChat\RemoveGroupParticipant;
use App\Enums\TeamChat\TeamParticipantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\TeamChat\AddGroupParticipantsRequest;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamChatPresenter;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Participants of a group, managed by its administrators (or by people
 * holding team-chat.manage-groups who participate in it).
 */
class TeamParticipantController extends Controller
{
    public function store(AddGroupParticipantsRequest $request, TeamThread $thread, AddGroupParticipants $addGroupParticipants): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $added = $addGroupParticipants->handle($thread, $actor, $request->userIds());

        if ($added === 0) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Esas personas ya participaban en el grupo.']);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => $added === 1 ? 'Se agregó 1 participante.' : "Se agregaron {$added} participantes."]);
        }

        return back();
    }

    public function update(Request $request, TeamThread $thread, User $user, ChangeParticipantRole $changeParticipantRole, TeamChatPresenter $presenter): RedirectResponse
    {
        Gate::authorize('manage', $thread);

        $validated = $request->validate(['role' => ['required', Rule::enum(TeamParticipantRole::class)]], [], ['role' => 'rol']);
        $role = TeamParticipantRole::from((string) $validated['role']);

        /** @var User $actor */
        $actor = $request->user();
        $changed = $changeParticipantRole->handle($thread, $actor, $user, $role);

        SaveToast::flash($changed, $role === TeamParticipantRole::Admin
            ? "{$presenter->userName($user)} ahora administra el grupo."
            : "{$presenter->userName($user)} ya no administra el grupo.");

        return back();
    }

    public function destroy(Request $request, TeamThread $thread, User $user, RemoveGroupParticipant $removeGroupParticipant, TeamChatPresenter $presenter): RedirectResponse
    {
        Gate::authorize('manage', $thread);

        /** @var User $actor */
        $actor = $request->user();
        $removeGroupParticipant->handle($thread, $actor, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$presenter->userName($user)} ya no participa en el grupo."]);

        return back();
    }
}
