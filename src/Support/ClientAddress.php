<?php

namespace Inly\CoreWeb\Support;

use Illuminate\Http\Request;

/** What a per-address rate limit counts a client by: an IPv4 address whole, an IPv6 one by its /64, which one subscriber is handed whole. */
final class ClientAddress
{
    private const IPV4_MAPPED = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    public static function key(Request $request): string
    {
        $ip = (string) $request->ip();
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        // ::ffff:a.b.c.d is the IPv4 client it names.
        if (str_starts_with($packed, self::IPV4_MAPPED)) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
