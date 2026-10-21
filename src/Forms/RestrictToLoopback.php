<?php

namespace Inly\CoreWeb\Forms;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The local form relay sends real entries on the developer's login, so only
 * the developer's own machine reaches it: a loopback peer, with no proxy or
 * tunnel in between (a LAN visitor, a Herd share). Anything else is a 404.
 */
class RestrictToLoopback
{
    private const LOOPBACK = ['127.0.0.1', '::1'];

    private const FORWARDING_HEADERS = ['X-Forwarded-For', 'X-Forwarded-Host', 'X-Real-IP', 'Forwarded', 'Via'];

    public function handle(Request $request, Closure $next): Response
    {
        // The socket's peer, never Request::ip(), which a trusted-proxy setup takes from a header.
        if (! in_array((string) $request->server('REMOTE_ADDR'), self::LOOPBACK, true)) {
            abort(404);
        }

        foreach (self::FORWARDING_HEADERS as $header) {
            if ($request->headers->has($header)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
