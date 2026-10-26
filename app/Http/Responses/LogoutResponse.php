<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Http\Responses\LogoutResponse as FortifyLogoutResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing out also empties the browser history Inertia kept: the next page
 * tells the client to drop its history encryption key, so the back button
 * cannot show the props of pages from the closed session.
 *
 * No Clear-Site-Data header is sent. Chrome does not deliver a response
 * carrying Clear-Site-Data: "cache" until it has walked its whole HTTP cache
 * (every site, not only this one), which takes seconds to minutes on a
 * profile with a large cache and left the sign out "loading" until a manual
 * refresh. It is not needed for privacy either: pages and Inertia responses
 * go out as "private, no-cache" (the framework default) and conversation
 * attachments as "private, no-cache" with an ETag (MessageAttachmentController),
 * so the browser revalidates each of them with the server, which authorizes
 * again, before showing it (never served stale after the session ends or an
 * access is revoked); the service worker only stores public static files and
 * the client empties that cache before signing out (LogoutConfirmDialog).
 */
class LogoutResponse extends FortifyLogoutResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        Inertia::clearHistory();

        $response = parent::toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
