<?php

namespace Inly\CoreWeb\Forms;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The local form relay sends real entries on the developer's login, so only
 * the developer's own machine reaches it: a loopback peer, with no proxy or
 * tunnel in between (a LAN visitor, a Herd share). Anything else is a 404.
 */
class RestrictToLoopback
{
    private const FORWARDING_HEADERS = ['X-Forwarded-For', 'X-Forwarded-Host', 'X-Real-IP', 'Forwarded', 'Via'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::allows($request)) {
            abort(404);
        }

        return $next($request);
    }

    public static function allows(Request $request): bool
    {
        // The socket's peer, never Request::ip(), which a trusted-proxy setup takes from a header.
        $peer = strtolower((string) $request->server('REMOTE_ADDR'));

        if (str_starts_with($peer, '::ffff:')) {
            $peer = substr($peer, 7);
        }

        if (! IpUtils::checkIp($peer, ['127.0.0.0/8', '::1'])) {
            return false;
        }

        foreach (self::FORWARDING_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        return true;
    }
}
