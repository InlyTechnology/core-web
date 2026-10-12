<?php

namespace Inly\CoreWeb\Snapshots;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page rendered while a snapshot it read had nothing to serve (a cold
 * cache and Core unreachable) shows an empty slider, rail or list: Statamic's
 * static cache must not keep it. Runs inside the static cache middleware.
 */
class UncacheableWhileDegraded
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (app()->resolved(SnapshotStore::class) && app(SnapshotStore::class)->servedDegraded()) {
            $response->headers->set('X-Statamic-Uncacheable', 'true');
        }

        return $response;
    }
}
