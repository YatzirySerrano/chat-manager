<?php

namespace App\Notifications;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may receive each notification. Every rule goes through the same
 * authorization services the screens use (ConversationAccess, permissions),
 * evaluated on fresh user rows at delivery time.
 */
class NotificationRecipients
{
    /** Upper bound of recipients evaluated for one queue notification. */
    private const MAX_QUEUE_RECIPIENTS = 500;

    public function __construct(private ConversationAccess $access) {}

    /**
     * Active users who could accept the pending conversation right now
     * (active account and role, accept permission, reaching its queue;
     * ConversationAccess::canAccept()). There is no manual availability.
     *
     * @return Collection<int, User>
     */
    public function forPendingConversation(Conversation $conversation, ?int $exceptUserId = null): Collection
    {
        return User::query()
            ->active()
            ->withPermission(Permission::AcceptConversations)
            ->when($exceptUserId !== null, fn (Builder $query) => $query->whereKeyNot($exceptUserId))
            ->where(fn (Builder $reach) => $reach
                ->whereHas('roles', fn (Builder $roles) => $roles->where('scope', DataScope::Global->value))
                ->when($conversation->department_id !== null, fn (Builder $query) => $query
                    ->orWhereHas('departments', fn (Builder $departments) => $departments->whereKey($conversation->department_id))))
            ->with(['roles.permissions', 'departments:id'])
            ->orderBy('id')
            ->limit(self::MAX_QUEUE_RECIPIENTS)
            ->get()
            ->filter(fn (User $user): bool => $this->access->canAccept($user, $conversation))
            ->values();
    }

    /**
     * The user, if they can still see the conversation.
     */
    public function ifCanView(?int $userId, Conversation $conversation): ?User
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->active()->with(['roles.permissions', 'departments:id'])->find($userId);

        return $user !== null && $this->access->canView($user, $conversation) ? $user : null;
    }

    /**
     * Users responsible for watching the WhatsApp connections.
     *
     * @return Collection<int, User>
     */
    public function connectionWatchers(): Collection
    {
        return User::query()
            ->active()
            ->where(fn (Builder $query) => $query
                ->withPermission(Permission::ViewConnections)
                ->orWhere(fn (Builder $manage) => $manage->withPermission(Permission::ManageConnections)))
            ->with(['roles.permissions'])
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => NotificationKind::ConnectionProblem->concerns($user))
            ->values();
    }
}
