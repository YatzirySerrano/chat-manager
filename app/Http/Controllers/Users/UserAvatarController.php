<?php

namespace App\Http\Controllers\Users;

use App\Concerns\ServesUserAvatars;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The profile picture a person chose (UserAvatars), served to any
 * signed-in person from the disk it was saved on, never a redirect, with
 * private caching in the viewer's browser only. A protected account's
 * picture is a 404 for whoever may not see that account. Web chat visitors
 * get the pictures of the agents who attend them through
 * WidgetAgentAvatarController instead.
 */
class UserAvatarController extends Controller
{
    use ServesUserAvatars;

    /** The URL carries the version, so the browser may keep it long. */
    public const BROWSER_CACHE_SECONDS = 604800;

    public function show(Request $request, User $user, ProtectedAccounts $protected): Response
    {
        $viewer = $request->user();

        abort_if($protected->hides($user, $viewer instanceof User ? $viewer : null), 404);

        return $this->avatarResponse($request, $user, self::BROWSER_CACHE_SECONDS);
    }
}
