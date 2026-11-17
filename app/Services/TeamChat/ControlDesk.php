<?php

namespace App\Services\TeamChat;

use App\Enums\Permission;
use App\Enums\TeamChat\TeamDeskStatus;
use App\Enums\TeamChat\TeamParticipantRole;
use App\Enums\TeamChat\TeamThreadType;
use App\Events\TeamChat\TeamThreadChanged;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * «Mesa de control» of the internal chat: a help desk staffed by
 * "auditoras" (team-chat.use + team-chat.control-desk.attend) for the
 * "solicitantes" (team-chat.use without it).
 *
 * - A requester only ever sees one contact, «Mesa de control»: her own desk
 *   thread (type desk, one per person, `desk_requester_id` unique), created
 *   the first time she opens the chat. She never sees direct chats or
 *   groups, cannot start them and nobody can start one with her.
 * - The requester is the only permanent participant. The auditor who
 *   accepts the request joins as a participant while it is open (so she
 *   gets its notifications and unread counter) and leaves when she marks it
 *   complete; every auditor may read any desk thread (TeamThreadPolicy).
 * - A message of the requester on a thread that is not open (new, or
 *   closed) puts it on the waiting list again: pending and unassigned. A
 *   message on an open thread keeps it with the same auditor.
 *
 * The simultaneous-attention limit is the very one of customer
 * conversations (ConversationAccess::attentionLimit(): the person's own
 * limit, else the platform-wide one; there is no department here), counted
 * separately: open desk threads of the auditor against that number,
 * independently of the conversations she attends.
 */
class ControlDesk
{
    /** Name every requester sees for the desk. */
    public const TITLE = 'Mesa de control';

    public function __construct(private ConversationAccess $access) {}

    /**
     * Whether the user staffs the desk (and keeps the regular team chat).
     */
    public function attends(User $user): bool
    {
        return $user->holdsPermission(Permission::UseTeamChat)
            && $user->holdsPermission(Permission::AttendControlDesk);
    }

    /**
     * Whether the user uses the team chat only through the desk.
     */
    public function isRequester(User $user): bool
    {
        return $user->holdsPermission(Permission::UseTeamChat)
            && ! $user->holdsPermission(Permission::AttendControlDesk);
    }

    /**
     * Active accounts that attend the desk (real-time audience).
     *
     * @return list<int>
     */
    public function auditorIds(): array
    {
        return array_values(array_map('intval', User::query()
            ->active()
            ->withPermission(Permission::UseTeamChat)
            ->withPermission(Permission::AttendControlDesk)
            ->pluck('id')
            ->all()));
    }

    /**
     * The threads that make up the person's internal chat: for a requester
     * only her desk thread; for everybody else the threads they take part
     * in (desk threads they attend included).
     *
     * @return Builder<TeamThread>
     */
    public function chatThreads(User $viewer): Builder
    {
        if ($this->isRequester($viewer)) {
            return TeamThread::query()->where('type', TeamThreadType::Desk)->where('desk_requester_id', $viewer->id);
        }

        return TeamThread::query()->withActiveParticipant($viewer);
    }

    /**
     * The requester's desk thread, created (with her as its only
     * participant) the first time. Two tabs opening it at once still end
     * with a single thread (unique `desk_requester_id`).
     */
    public function threadFor(User $requester): TeamThread
    {
        $existing = TeamThread::query()->where('desk_requester_id', $requester->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($requester): TeamThread {
                $thread = new TeamThread(['type' => TeamThreadType::Desk]);
                $thread->forceFill(['desk_requester_id' => $requester->id, 'created_by_user_id' => $requester->id])->save();

                $thread->participations()->create([
                    'user_id' => $requester->id,
                    'role' => TeamParticipantRole::Member,
                    'joined_at' => now(),
                    'added_by_user_id' => $requester->id,
                ]);

                return $thread;
            });
        } catch (UniqueConstraintViolationException) {
            return TeamThread::query()->where('desk_requester_id', $requester->id)->firstOrFail();
        }
    }

    /**
     * Maximum of desk requests the auditor attends at once, or null when
     * unlimited: the same number that limits her customer conversations.
     */
    public function limit(User $auditor): ?int
    {
        return $this->access->attentionLimit($auditor);
    }

    /**
     * Open desk requests the auditor attends. Inside the accept transaction
     * it is read with a locking read, after her user row lock.
     */
    public function load(User $auditor, bool $lockingRead = false): int
    {
        return TeamThread::query()
            ->where('type', TeamThreadType::Desk)
            ->where('desk_status', TeamDeskStatus::Open)
            ->where('desk_assigned_user_id', $auditor->id)
            ->when($lockingRead, fn ($query) => $query->sharedLock())
            ->count();
    }

    public static function capacityMessage(int $limit): string
    {
        return $limit === 1
            ? 'Ya atiendes el máximo de 1 solicitud de la mesa de control. Márcala como completa antes de aceptar otra.'
            : "Ya atiendes el máximo de {$limit} solicitudes de la mesa de control. Marca alguna como completa antes de aceptar otra.";
    }

    /**
     * Called by PostTeamMessage inside its transaction, with the thread row
     * locked: a message of the requester on a desk thread that is not open
     * (new, or completed) puts it back on the waiting list, unassigned.
     * Messages on an open request keep it with its auditor; messages of a
     * pending one keep its place in the list.
     */
    public function messagePosted(TeamThread $locked, User $author, TeamMessage $message): void
    {
        if (! $locked->isDesk() || $locked->desk_requester_id !== $author->id) {
            return;
        }

        if (in_array($locked->desk_status, [TeamDeskStatus::Open, TeamDeskStatus::Pending], true)) {
            return;
        }

        $locked->forceFill([
            'desk_status' => TeamDeskStatus::Pending,
            'desk_assigned_user_id' => null,
            'desk_requested_at' => $message->created_at ?? now(),
            'desk_request_message_id' => $message->id,
            'desk_accepted_at' => null,
        ])->save();

        TeamThreadChanged::dispatch($locked->id, TeamThreadChanged::DESK, $author->id, $this->auditorIds());
    }
}
