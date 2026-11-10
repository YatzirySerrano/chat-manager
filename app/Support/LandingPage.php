<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

/**
 * Where a signed-in person lands («/», after signing in, the logo of the
 * sidebar): the main panel when their role allows it, otherwise the first
 * module they may use, and their own profile as a last resort (always
 * available), so nobody lands on a page they are not allowed to see.
 */
class LandingPage
{
    /**
     * Candidates in order of preference: the permission and the route name.
     *
     * @var list<array{Permission, string}>
     */
    private const CANDIDATES = [
        [Permission::ViewDashboard, 'dashboard'],
        [Permission::ViewConversations, 'conversations.index'],
        [Permission::UseTeamChat, 'team-chat.index'],
    ];

    public function routeFor(User $user): string
    {
        foreach (self::CANDIDATES as [$permission, $route]) {
            if ($user->holdsPermission($permission)) {
                return $route;
            }
        }

        return 'profile.edit';
    }

    public function urlFor(User $user): string
    {
        return route($this->routeFor($user), absolute: false);
    }
}
