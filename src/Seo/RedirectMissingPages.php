<?php

namespace Inly\CoreWeb\Seo;

use Closure;
use Illuminate\Http\Request;
use Statamic\Statamic;
use Symfony\Component\HttpFoundation\Response;

/** Sends a front-end GET that would 404 to the redirect the editors set for its path, if any. */
final class RedirectMissingPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 404 || ! in_array($request->method(), ['GET', 'HEAD'], true) || Statamic::isCpRoute()) {
            return $response;
        }

        $redirect = Redirects::for($request->getPathInfo());

        if ($redirect === null) {
            return $response;
        }

        $query = $request->getQueryString();

        return redirect()->away($redirect['to'].($query && ! str_contains($redirect['to'], '?') ? '?'.$query : ''), $redirect['status']);
    }
}
