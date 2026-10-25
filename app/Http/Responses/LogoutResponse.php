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
 * Clear-Site-Data "cache" makes the browser drop its HTTP cache of this
 * origin as well. "storage" is deliberately not sent: it would also erase
 * localStorage (the person's appearance choice) and unregister the service
 * worker; the worker's own cache is emptied by the client before logging out
 * and it never stores private data.
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
        $response->headers->set('Clear-Site-Data', '"cache"');

        return $response;
    }
}
