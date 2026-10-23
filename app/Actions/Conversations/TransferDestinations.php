<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Destinations offered when assigning or transferring a conversation: the
 * active departments that may receive its connection's conversations and,
 * per department, the people who could be responsible for it there (active
 * account, active role that can view and reply, member of the department or
 * with organization-wide scope; ConversationAccess::canAttend()).
 *
 * Choosing a colleague does not require seeing their conversations: each
 * person is described only by id, name, availability and whether they
 * already attend their maximum; never their email, role, load figures or
 * conversations. The engine validates everything again under lock when the
 * operation is sent (TransferConversation, AssignConversation).
 */
class TransferDestinations
{
    public function __construct(private ConversationAccess $access) {}

    /**
     * @return array{allows_exceptions: bool, departments: list<array{id: int, name: string, agents: list<array{id: int, name: string, availability: string, at_capacity: bool}>}>, unrouted_agents: list<array{id: int, name: string, availability: string, at_capacity: bool}>}
     */
    public function for(Conversation $conversation, User $actor): array
    {
        $connection = $conversation->channelConnection()->firstOrFail();
        $authorized = $connection->departments()->pluck('departments.id')->all();

        $departments = Department::query()
            ->active()
            ->when($authorized !== [], fn (Builder $query) => $query->whereKey($authorized))
            ->orderBy('name')
            ->get();

        $candidates = $this->candidates($departments->modelKeys());

        return [
            // Handing it to someone unavailable or at their limit is an
            // exception only users who can assign may make (with confirmation).
            'allows_exceptions' => $actor->holdsPermission(Permission::AssignConversations),
            'departments' => array_values($departments->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'agents' => $this->describe($candidates, $department),
            ])->all()),
            'unrouted_agents' => $this->describe($candidates, null),
        ];
    }

    /**
     * People who may attend at least one of the departments (or, with an
     * organization-wide scope, conversations without department), with the
     * number of conversations they attend in any department.
     *
     * @param  array<int, mixed>  $departmentIds
     * @return Collection<int, User>
     */
    private function candidates(array $departmentIds): Collection
    {
        return User::query()
            ->active()
            ->withPermission(Permission::ViewConversations)
            ->withPermission(Permission::ReplyConversations)
            ->where(fn (Builder $reach) => $reach
                ->whereHas('departments', fn (Builder $departments) => $departments->whereKey($departmentIds))
                ->orWhereHas('roles', fn (Builder $roles) => $roles->where('is_active', true)->where('scope', DataScope::Global)))
            ->with(['roles', 'departments'])
            ->withCount(['assignedConversations as attention_load' => fn (Builder $query) => $query
                ->whereIn('status', [ConversationStatus::Open, ConversationStatus::OnHold])])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @return list<array{id: int, name: string, availability: string, at_capacity: bool}>
     */
    private function describe(Collection $candidates, ?Department $department): array
    {
        return array_values($candidates
            ->filter(fn (User $user): bool => $this->access->canAttend($user, $department))
            ->map(function (User $user) use ($department): array {
                $limit = $this->access->attentionLimit($user, $department);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'availability' => $user->availability->value,
                    'at_capacity' => $limit !== null && (int) $user->getAttribute('attention_load') >= $limit,
                ];
            })
            ->all());
    }
}
