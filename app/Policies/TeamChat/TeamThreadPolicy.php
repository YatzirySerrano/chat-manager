<?php

namespace App\Policies\TeamChat;

use App\Enums\Permission;
use App\Enums\TeamChat\TeamDeskStatus;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\ControlDesk;
use Illuminate\Auth\Access\Response;

/**
 * Internal chats are private to their current participants. Nobody else can
 * open them, list them or read their files, whatever their role: there is
 * deliberately no "view all" ability, not even for the Superadministrador,
 * so there is no invisible surveillance of private messages. For people who
 * do not participate a thread answers exactly as one that does not exist.
 *
 * team-chat.manage-groups lets someone administer the groups they belong
 * to as if they were a group administrator (rename, participants, archive);
 * it never grants access to groups they are not part of.
 *
 * «Mesa de control» (ControlDesk): a requester (team-chat.use without
 * team-chat.control-desk.attend) only reaches her own desk thread; every
 * other thread answers as if it did not exist, and she cannot start direct
 * chats or groups. Desk threads are readable by their requester and by
 * every auditor (the desk is a shared queue, not a private chat); only the
 * requester and the auditor who accepted the open request write in them.
 */
class TeamThreadPolicy
{
    public function __construct(private ControlDesk $desk) {}

    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseTeamChat);
    }

    /**
     * Start a direct chat (the target is checked by TeamChatDirectory).
     */
    public function startDirect(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseTeamChat) && ! $this->desk->isRequester($actor);
    }

    public function createGroup(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseTeamChat)
            && $actor->holdsPermission(Permission::CreateTeamChatGroups)
            && ! $this->desk->isRequester($actor);
    }

    /**
     * Accept or complete «Mesa de control» requests (state, capacity and
     * races are checked under lock by AcceptDeskRequest and
     * CompleteDeskRequest).
     */
    public function attendDesk(User $actor, TeamThread $thread): Response
    {
        return $thread->isDesk() && $this->desk->attends($actor)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function view(User $actor, TeamThread $thread): Response
    {
        if ($thread->isDesk()) {
            return $this->reachesDesk($actor, $thread) ? Response::allow() : Response::denyAsNotFound();
        }

        return $this->participation($actor, $thread) === null
            ? Response::denyAsNotFound()
            : Response::allow();
    }

    /**
     * Send messages: archived groups are read-only, and a direct chat with
     * a deleted account accepts no new messages.
     */
    public function post(User $actor, TeamThread $thread): Response
    {
        if ($thread->isDesk()) {
            return $this->postToDesk($actor, $thread);
        }

        if ($this->participation($actor, $thread) === null) {
            return Response::denyAsNotFound();
        }

        if ($thread->isArchived()) {
            return Response::deny('El grupo está archivado: ya no se pueden enviar mensajes.');
        }

        if ($thread->isDirect() && ! $this->directPartnerIsActive($actor, $thread)) {
            return Response::deny('La otra persona ya no tiene una cuenta activa.');
        }

        return Response::allow();
    }

    /**
     * Rename the group and manage its participants.
     */
    public function manage(User $actor, TeamThread $thread): Response
    {
        $participation = $this->participation($actor, $thread);

        if ($participation === null) {
            return Response::denyAsNotFound();
        }

        if (! $thread->isGroup() || $thread->isArchived() || ! $this->administers($actor, $participation)) {
            return Response::deny('No puedes administrar este grupo.');
        }

        return Response::allow();
    }

    public function archive(User $actor, TeamThread $thread): Response
    {
        return $this->manage($actor, $thread);
    }

    public function unarchive(User $actor, TeamThread $thread): Response
    {
        $participation = $this->participation($actor, $thread);

        if ($participation === null) {
            return Response::denyAsNotFound();
        }

        if (! $thread->isGroup() || ! $thread->isArchived() || ! $this->administers($actor, $participation)) {
            return Response::deny('No puedes reactivar este grupo.');
        }

        return Response::allow();
    }

    /**
     * Only groups can be left; a direct chat always keeps both people.
     */
    public function leave(User $actor, TeamThread $thread): Response
    {
        if ($this->participation($actor, $thread) === null) {
            return Response::denyAsNotFound();
        }

        return $thread->isGroup() ? Response::allow() : Response::deny('No puedes salir de un chat privado.');
    }

    /**
     * Only the author edits or deletes their own messages, while the thread
     * still accepts messages.
     */
    public function updateMessage(User $actor, TeamThread $thread, TeamMessage $message): Response
    {
        $post = $this->post($actor, $thread);

        if ($post->denied()) {
            return $post;
        }

        if ($message->team_thread_id !== $thread->id) {
            return Response::denyAsNotFound();
        }

        if ($message->user_id !== $actor->id || $message->isSystem() || $message->isDeleted()) {
            return Response::deny('Solo puedes modificar tus propios mensajes.');
        }

        return Response::allow();
    }

    public function deleteMessage(User $actor, TeamThread $thread, TeamMessage $message): Response
    {
        return $this->updateMessage($actor, $thread, $message);
    }

    /**
     * React to (or withdraw a reaction from) any visible message of a
     * thread that still accepts messages.
     */
    public function react(User $actor, TeamThread $thread, TeamMessage $message): Response
    {
        $post = $this->post($actor, $thread);

        if ($post->denied()) {
            return $post;
        }

        if ($message->team_thread_id !== $thread->id) {
            return Response::denyAsNotFound();
        }

        if ($message->isSystem() || $message->isDeleted()) {
            return Response::deny('No puedes reaccionar a este mensaje.');
        }

        return Response::allow();
    }

    /**
     * The requester of a desk thread, or anyone who attends the desk.
     */
    private function reachesDesk(User $actor, TeamThread $thread): bool
    {
        if (! $actor->holdsPermission(Permission::UseTeamChat)) {
            return false;
        }

        return $thread->desk_requester_id === $actor->id || $this->desk->attends($actor);
    }

    /**
     * The requester always writes (a message on a completed request opens a
     * new one); an auditor only on the open request she accepted.
     */
    private function postToDesk(User $actor, TeamThread $thread): Response
    {
        if (! $this->reachesDesk($actor, $thread)) {
            return Response::denyAsNotFound();
        }

        if ($thread->desk_requester_id === $actor->id || $thread->isDeskAssignedTo($actor)) {
            return Response::allow();
        }

        return Response::deny(match ($thread->desk_status) {
            TeamDeskStatus::Open => 'Otra persona de la mesa de control atiende esta solicitud.',
            TeamDeskStatus::Closed => 'La solicitud está completa. Se reabre cuando la persona vuelva a escribir.',
            default => 'Acepta la solicitud para responder.',
        });
    }

    /**
     * The actor's current participation when they may use the chat at all.
     * A requester only takes part in her desk thread: her old direct chats
     * and groups answer as if she had left them.
     */
    private function participation(User $actor, TeamThread $thread): ?TeamParticipant
    {
        if (! $actor->holdsPermission(Permission::UseTeamChat)) {
            return null;
        }

        if (! $thread->isDesk() && $this->desk->isRequester($actor)) {
            return null;
        }

        return $thread->participationOf($actor);
    }

    private function administers(User $actor, TeamParticipant $participation): bool
    {
        return $participation->isAdmin() || $actor->holdsPermission(Permission::ManageTeamChatGroups);
    }

    private function directPartnerIsActive(User $actor, TeamThread $thread): bool
    {
        $partner = $thread->relationLoaded('activeParticipations')
            ? $thread->activeParticipations->first(fn (TeamParticipant $participant): bool => $participant->user_id !== $actor->id)
            : $thread->activeParticipations()->where('user_id', '!=', $actor->id)->with('user:id,is_active')->first();

        if ($partner === null) {
            return false;
        }

        return $partner->relationLoaded('user')
            ? $partner->user->is_active
            : (bool) $partner->user()->value('is_active');
    }
}
