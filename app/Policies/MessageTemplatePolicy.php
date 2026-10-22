<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Connection;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * WhatsApp templates are read by people with templates.view (or manage)
 * within the connections they can see; synchronizing with Meta uses the
 * connection's credentials, so it requires templates.manage, which is an
 * organization-wide permission. Sending a template in a conversation is
 * governed by the conversation's "reply" ability, not by these permissions.
 */
class MessageTemplatePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewTemplates) || $actor->holdsPermission(Permission::ManageTemplates);
    }

    public function view(User $actor, MessageTemplate $template): Response
    {
        if (! $this->viewAny($actor)) {
            return Response::deny();
        }

        $visible = MessageTemplate::query()->visibleTo($actor)->whereKey($template->id)->exists();

        if (! $visible || ($template->isDeleted() && ! $actor->canViewDeletedRecords())) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function sync(User $actor, Connection $connection): bool
    {
        return ! $connection->isDeleted() && $actor->holdsPermission(Permission::ManageTemplates);
    }
}
