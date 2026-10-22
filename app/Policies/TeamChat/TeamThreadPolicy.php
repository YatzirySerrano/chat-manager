<?php

namespace App\Policies\TeamChat;

use App\Enums\Permission;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
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
 */
class TeamThreadPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseTeamChat);
    }

    /**
     * Start a direct chat (the target is checked by TeamChatDirectory).
     */
    public function startDirect(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseTeamChat);
    }

    public function createGroup(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseTeamChat)
            && $actor->holdsPermission(Permission::CreateTeamChatGroups);
    }

    public function view(User $actor, TeamThread $thread): Response
    {
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
     * The actor's current participation when they may use the chat at all.
     */
    private function participation(User $actor, TeamThread $thread): ?TeamParticipant
    {
        if (! $actor->holdsPermission(Permission::UseTeamChat)) {
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
