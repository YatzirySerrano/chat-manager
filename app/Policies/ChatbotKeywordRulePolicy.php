<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ChatbotKeywordRule;
use App\Models\User;

/**
 * The chatbot (welcome menus and keyword replies) answers customers on
 * behalf of the whole organization, so consulting and configuring it
 * requires connections.chatbot, a global-only permission. The menus are
 * authorized per connection (ConnectionPolicy::configureChatbot()).
 */
class ChatbotKeywordRulePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageChatbot);
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageChatbot);
    }

    public function update(User $actor, ChatbotKeywordRule $rule): bool
    {
        return $actor->holdsPermission(Permission::ManageChatbot);
    }

    /**
     * Rules are configuration: deleting one removes it for good (the audit
     * log keeps what it said), and the replies it already sent stay in
     * their conversations.
     */
    public function delete(User $actor, ChatbotKeywordRule $rule): bool
    {
        return $actor->holdsPermission(Permission::ManageChatbot);
    }
}
