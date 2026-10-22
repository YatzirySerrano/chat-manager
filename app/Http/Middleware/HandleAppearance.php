<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Values the appearance cookie may hold. The cookie is not encrypted and
     * is written into an inline script of the root view, so anything else is
     * ignored instead of being echoed into the page.
     */
    public const APPEARANCES = ['light', 'dark', 'system'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appearance = $request->cookie('appearance');

        View::share('appearance', in_array($appearance, self::APPEARANCES, true) ? $appearance : 'system');

        return $next($request);
    }
}
