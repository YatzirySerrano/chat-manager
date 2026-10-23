<?php

namespace App\Http\Controllers\TeamChat;

use App\Actions\TeamChat\ArchiveGroupThread;
use App\Actions\TeamChat\CreateGroupThread;
use App\Actions\TeamChat\LeaveGroupThread;
use App\Actions\TeamChat\MarkThreadRead;
use App\Actions\TeamChat\StartDirectThread;
use App\Actions\TeamChat\TeamUnreadCounter;
use App\Actions\TeamChat\UpdateGroupThread;
use App\Events\Broadcasts\RealtimeSignal;
use App\Http\Controllers\Controller;
use App\Http\Requests\TeamChat\StoreGroupThreadRequest;
use App\Http\Requests\TeamChat\UpdateGroupThreadRequest;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Support\SaveToast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamThreadController extends Controller
{
    /**
     * Open (or create the first time) the direct chat with a person.
     */
    public function storeDirect(Request $request, StartDirectThread $startDirectThread): RedirectResponse
    {
        Gate::authorize('startDirect', TeamThread::class);

        $validated = $request->validate(['user_id' => ['required', 'integer']], [], ['user_id' => 'persona']);

        /** @var User $actor */
        $actor = $request->user();
        $target = User::query()->find((int) $validated['user_id']);

        if ($target === null) {
            return back()->withErrors(['user_id' => 'No puedes iniciar un chat con esta persona.']);
        }

        $thread = $startDirectThread->handle($actor, $target);

        return to_route('team-chat.threads.show', $thread);
    }

    public function storeGroup(StoreGroupThreadRequest $request, CreateGroupThread $createGroupThread): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $thread = $createGroupThread->handle($actor, $request->payload());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Grupo «{$thread->name}» creado."]);

        return to_route('team-chat.threads.show', $thread);
    }

    public function update(UpdateGroupThreadRequest $request, TeamThread $thread, UpdateGroupThread $updateGroupThread): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        SaveToast::flash($updateGroupThread->handle($thread, $actor, $request->payload()), 'Grupo actualizado.');

        return back();
    }

    public function archive(Request $request, TeamThread $thread, ArchiveGroupThread $archiveGroupThread): RedirectResponse
    {
        Gate::authorize('archive', $thread);

        /** @var User $actor */
        $actor = $request->user();
        $archiveGroupThread->archive($thread, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grupo archivado. Su historial sigue disponible para sus participantes.']);

        return back();
    }

    public function unarchive(Request $request, TeamThread $thread, ArchiveGroupThread $archiveGroupThread): RedirectResponse
    {
        Gate::authorize('unarchive', $thread);

        /** @var User $actor */
        $actor = $request->user();
        $archiveGroupThread->unarchive($thread, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grupo reactivado.']);

        return back();
    }

    public function leave(Request $request, TeamThread $thread, LeaveGroupThread $leaveGroupThread): RedirectResponse
    {
        Gate::authorize('leave', $thread);

        /** @var User $actor */
        $actor = $request->user();
        $leaveGroupThread->handle($thread, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saliste del grupo «{$thread->name}»."]);

        return to_route('team-chat.index');
    }

    /**
     * Mute a thread for oneself: it no longer counts in the sidebar badge.
     */
    public function mute(Request $request, TeamThread $thread): RedirectResponse
    {
        Gate::authorize('view', $thread);

        $muted = (bool) $request->validate(['muted' => ['required', 'boolean']])['muted'];
        $participation = $thread->participationOf($request->user()->id);

        if ($participation === null || $participation->muted === $muted) {
            SaveToast::flash(false, '');

            return back();
        }

        $participation->forceFill(['muted' => $muted])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $muted ? 'Silenciaste este chat.' : 'Este chat vuelve a notificarte.']);

        return back();
    }

    /**
     * "Typing…" indicator: an ephemeral signal on the thread channel with
     * the author set by the server (never by the client), authorized again
     * on every call so someone removed stops emitting at once. Nothing is
     * stored; clients forget it after a few seconds. The signal is sent
     * inside the closure (event(), not broadcast(), whose PendingBroadcast
     * would only send it on destruction, outside rescue), so an unreachable
     * Reverb never turns the request into an error.
     */
    public function typing(Request $request, TeamThread $thread): Response
    {
        Gate::authorize('post', $thread);

        /** @var User $author */
        $author = $request->user();

        rescue(function () use ($thread, $author): void {
            event(new RealtimeSignal(["team-chat.threads.{$thread->id}"], 'typing', ['user_id' => $author->id]));
        }, report: false);

        return response()->noContent();
    }

    /**
     * Move the viewer's read marker forward; answers the updated badge.
     */
    public function read(Request $request, TeamThread $thread, MarkThreadRead $markThreadRead, TeamUnreadCounter $unread): JsonResponse
    {
        Gate::authorize('view', $thread);

        $validated = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);

        /** @var User $viewer */
        $viewer = $request->user();
        $participation = $thread->participationOf($viewer);
        abort_if($participation === null, 404);

        $markThreadRead->handle($thread, $participation, (int) $validated['message_id']);

        return response()->json([
            'last_read_message_id' => $participation->last_read_message_id,
            'unread_total' => $unread->total($viewer),
        ]);
    }
}
