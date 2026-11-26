<?php

namespace App\Actions\Contacts;

use App\Actions\Conversations\AssignAutomatically;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * A contact assigned to a department belongs to it: its conversations
 * (open, pending and closed, whatever connection they came through) move
 * there, so the people of the previous department stop seeing the contact
 * and its messages, and its new messages keep arriving to that department
 * (ConversationRouter). Users of several departments keep seeing all of them.
 *
 * Each move is recorded as a transfer in the conversation's timeline. An
 * agent who does not belong to the new department stops attending it and the
 * conversation waits in the department's queue (distributed automatically
 * when the department does so). No message is sent to the customer, and the
 * read-only Whaticket history is left as it is.
 */
class MoveContactToDepartment
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private ConversationAccess $access,
        private AssignAutomatically $assignAutomatically,
    ) {}

    /**
     * @return int How many conversations were moved.
     */
    public function handle(Contact $contact, User $actor): int
    {
        $department = $contact->department_id === null ? null : Department::query()->active()->find($contact->department_id);

        if ($department === null) {
            return 0;
        }

        $ids = Conversation::query()
            ->where('contact_id', $contact->id)
            ->whereNull('history_only_at')
            ->where(fn ($query) => $query->whereNull('department_id')->orWhere('department_id', '!=', $department->id))
            ->orderBy('id')
            ->pluck('id');

        $moved = 0;

        foreach ($ids as $id) {
            $moved += DB::transaction(fn (): int => $this->move((int) $id, $department, $actor));
        }

        return $moved;
    }

    private function move(int $conversationId, Department $department, User $actor): int
    {
        $conversation = Conversation::query()->find($conversationId);

        if ($conversation === null) {
            return 0;
        }

        $conversation = $this->lifecycle->lock($conversation);

        if ($conversation->department_id === $department->id || $conversation->isHistoryOnly()) {
            return 0;
        }

        $fromDepartment = $conversation->department_id;
        $fromUser = $conversation->assigned_user_id;
        $agent = $fromUser === null ? null : User::query()->find($fromUser);
        $keepsAgent = $agent !== null && $this->access->canAttend($agent, $department);

        if ($conversation->status === ConversationStatus::Closed || $conversation->status === ConversationStatus::Pending || $keepsAgent) {
            $conversation->forceFill([
                'department_id' => $department->id,
                'version' => $conversation->version + 1,
            ])->save();
        } else {
            $this->lifecycle->apply($conversation, ConversationStatus::Pending, [
                ...$this->lifecycle->queued($conversation, $department->id),
                'department_id' => $department->id,
            ]);
        }

        $this->lifecycle->record($conversation, ConversationEventType::Transferred, $actor, [
            'from_user_id' => $fromUser,
            'to_user_id' => $conversation->assigned_user_id,
            'from_department_id' => $fromDepartment,
            'to_department_id' => $department->id,
            'note' => "El contacto se asignó al departamento {$department->name}.",
        ]);

        if ($conversation->status === ConversationStatus::Pending && $conversation->assigned_user_id === null) {
            $this->assignAutomatically->handle($conversation, $fromUser === null ? [] : [$fromUser]);
        }

        return 1;
    }
}
