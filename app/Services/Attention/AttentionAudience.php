<?php

namespace App\Services\Attention;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The agents a person supervises from «Seguimiento de atención» and may
 * alert: active accounts within their reach (User::visibleTo: everyone with
 * global scope, the members of their departments with departments scope)
 * that attend customers (conversations.reply), never protected accounts the
 * viewer must not see, and never the viewer themselves. The same set is
 * listed on the page and accepted as recipients, so an alert can never
 * reach someone outside the sender's reach.
 */
class AttentionAudience
{
    /**
     * @return Builder<User>
     */
    public function agents(User $viewer): Builder
    {
        return User::query()
            ->visibleTo($viewer)
            ->withoutProtectedFor($viewer)
            // Deleted accounts are inactive (DeletesLogically).
            ->where('users.is_active', true)
            ->whereKeyNot($viewer->id)
            ->withPermission(Permission::ReplyConversations);
    }
}
