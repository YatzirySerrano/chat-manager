<?php

namespace App\Services\WebChat;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\WebChatAvailabilityMode;
use App\Models\Department;
use App\Models\User;
use App\Models\WebChatWidget;
use App\Services\BusinessHours;
use App\Services\Conversations\ConversationAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether the widget shows the team as available (online). It is when the
 * connection operates and is configured, the schedule allows it ("always"
 * mode, or "business hours" mode with the default department's schedule
 * open, see BusinessHours) and somebody could attend the conversation: the
 * default department is active and has at least one active member able to
 * attend it (ConversationAccess::canAttend()); without a default department,
 * someone with organization-wide scope able to attend. There is no manual
 * availability nor online presence involved.
 *
 * Offline visitors can still write when the connection operates: their
 * message waits in the queue like any other.
 */
class WebChatAvailability
{
    /** Candidates evaluated at most when looking for someone who can attend. */
    private const MAX_CANDIDATES = 50;

    public function __construct(
        private BusinessHours $hours,
        private ConversationAccess $access,
    ) {}

    /**
     * @return array{online: bool, message: string|null}
     */
    public function for(WebChatWidget $widget): array
    {
        $connection = $widget->connection;

        $online = $connection !== null
            && $connection->isOperational()
            && $connection->isConfigured()
            && ($widget->availability_mode !== WebChatAvailabilityMode::BusinessHours
                || $this->hours->isOpenAt($connection->defaultDepartment, now()))
            && $this->someoneCanAttend($connection->defaultDepartment);

        return [
            'online' => $online,
            'message' => $online ? null : ($widget->offline_text ?? 'En este momento no estamos disponibles. Déjanos tu mensaje y te responderemos lo antes posible.'),
        ];
    }

    /**
     * Whether at least one active person could be responsible for a
     * conversation of the department (null meaning "no department").
     */
    private function someoneCanAttend(?Department $department): bool
    {
        if ($department !== null && ! $department->is_active) {
            return false;
        }

        return User::query()
            ->active()
            ->withPermission(Permission::ViewConversations)
            ->withPermission(Permission::ReplyConversations)
            ->when(
                $department !== null,
                fn (Builder $query) => $query->whereHas('departments', fn (Builder $departments) => $departments->whereKey($department?->id)),
                fn (Builder $query) => $query->whereHas('roles', fn (Builder $roles) => $roles->where('is_active', true)->where('scope', DataScope::Global)),
            )
            ->with(['roles', 'departments'])
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->contains(fn (User $user): bool => $this->access->canAttend($user, $department));
    }
}
