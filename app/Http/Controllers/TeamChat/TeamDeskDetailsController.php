<?php

namespace App\Http\Controllers\TeamChat;

use App\Enums\Permission;
use App\Events\TeamChat\TeamThreadChanged;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\TeamChat\TeamDeskNote;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\ControlDesk;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Follow-up tools of a «Mesa de control» request, like those of a customer
 * conversation: internal notes of the team (anyone who attends the desk
 * writes them; only their author edits or deletes them) and tags of the
 * catalog (with tags.assign). The requester never sees either.
 */
class TeamDeskDetailsController extends Controller
{
    public const NOTE_MAX_LENGTH = 2000;

    public function __construct(private ControlDesk $desk) {}

    public function storeNote(Request $request, TeamThread $thread): RedirectResponse
    {
        Gate::authorize('attendDesk', $thread);

        $validated = $request->validate(['body' => ['required', 'string', 'max:'.self::NOTE_MAX_LENGTH]]);

        /** @var User $actor */
        $actor = $request->user();

        $note = new TeamDeskNote(['body' => trim((string) $validated['body'])]);
        $note->forceFill(['team_thread_id' => $thread->id, 'user_id' => $actor->id])->save();

        $this->changed($thread, $actor);
        SaveToast::flash(true, 'Nota interna guardada.');

        return back();
    }

    public function updateNote(Request $request, TeamThread $thread, TeamDeskNote $note): RedirectResponse
    {
        $this->authorizeNote($request, $thread, $note);

        $validated = $request->validate(['body' => ['required', 'string', 'max:'.self::NOTE_MAX_LENGTH]]);
        $body = trim((string) $validated['body']);

        if ($body !== $note->body) {
            $note->forceFill(['body' => $body, 'edited_at' => now()])->save();
        }

        /** @var User $actor */
        $actor = $request->user();
        $this->changed($thread, $actor);
        SaveToast::flash(true, 'Nota interna actualizada.');

        return back();
    }

    public function destroyNote(Request $request, TeamThread $thread, TeamDeskNote $note): RedirectResponse
    {
        $this->authorizeNote($request, $thread, $note);

        $note->delete();

        /** @var User $actor */
        $actor = $request->user();
        $this->changed($thread, $actor);
        SaveToast::flash(true, 'Nota interna eliminada.');

        return back();
    }

    public function syncTags(Request $request, TeamThread $thread): RedirectResponse
    {
        Gate::authorize('attendDesk', $thread);

        /** @var User $actor */
        $actor = $request->user();

        if (! $actor->holdsPermission(Permission::AssignTags)) {
            abort(403, 'No puedes asignar etiquetas.');
        }

        $validated = $request->validate([
            'tag_ids' => ['present', 'array', 'max:50'],
            'tag_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);

        $wanted = array_values(array_map('intval', $validated['tag_ids']));

        $changed = DB::transaction(function () use ($thread, $wanted, $actor): bool {
            $current = array_map('intval', $thread->tags()->pluck('tags.id')->all());
            $added = array_values(array_diff($wanted, $current));
            $removed = array_values(array_diff($current, $wanted));

            // Only active tags of the catalog can be added; a tag that was
            // deactivated meanwhile stays where it already is.
            if ($added !== [] && Tag::query()->whereKey($added)->where('is_active', true)->count() !== count($added)) {
                throw ValidationException::withMessages(['tag_ids' => 'Alguna etiqueta ya no está disponible. Recarga la página.']);
            }

            if ($removed !== []) {
                $thread->tags()->detach($removed);
            }

            foreach ($added as $tagId) {
                $thread->tags()->attach($tagId, ['tagged_by_user_id' => $actor->id, 'created_at' => now()]);
            }

            return $added !== [] || $removed !== [];
        });

        if ($changed) {
            $this->changed($thread, $actor);
        }

        SaveToast::flash($changed, 'Etiquetas de la solicitud actualizadas.');

        return back();
    }

    private function authorizeNote(Request $request, TeamThread $thread, TeamDeskNote $note): void
    {
        Gate::authorize('attendDesk', $thread);

        if ($note->team_thread_id !== $thread->id) {
            abort(404);
        }

        if ($note->user_id !== $request->user()?->id) {
            abort(403, 'Solo puedes modificar tus propias notas.');
        }
    }

    /**
     * The other people at the desk refresh the request they have open.
     */
    private function changed(TeamThread $thread, User $actor): void
    {
        TeamThreadChanged::dispatch($thread->id, TeamThreadChanged::DESK, $actor->id, $this->desk->auditorIds());
    }
}
