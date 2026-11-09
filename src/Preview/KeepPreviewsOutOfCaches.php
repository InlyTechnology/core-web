<?php

namespace Inly\CoreWeb\Preview;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inside Statamic's static cache, for the error page a marked route answers with (an expired link's 404): the router
 * prepares a rendered exception only after it has passed the route's middleware.
 */
final class KeepPreviewsOutOfCaches
{
    public function handle(Request $request, Closure $next): Response
    {
        return Preview::keepOutOfCaches($request, $next($request));
    }
}
