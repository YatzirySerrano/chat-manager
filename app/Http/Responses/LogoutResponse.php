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
 */
class LogoutResponse extends FortifyLogoutResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        Inertia::clearHistory();

        return parent::toResponse($request);
    }
}
