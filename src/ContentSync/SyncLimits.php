<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inly\CoreWeb\Support\ClientAddress;

/**
 * The sync endpoints' rate limits, each a named limiter with a counter of its
 * own: `throttle:60,1` style limits on a group and a route share one key per
 * client, so a login's device request would spend the pull's allowance.
 */
final class SyncLimits
{
    public const REQUESTS = 'core-web-sync';

    public const DEVICE = 'core-web-sync-device';

    /** Entries relayed from a local checkout are real leads, so a test run cannot send a flood of them. */
    public const FORM_ENTRIES = 'core-web-sync-form-entries';

    public static function register(): void
    {
        // A token gets its own allowance, and the address one big enough for a few machines behind one office NAT.
        RateLimiter::for(self::REQUESTS, fn (Request $request) => array_values(array_filter([
            filled($token = $request->bearerToken()) ? Limit::perMinute(120)->by('token:'.SyncTokens::hash($token)) : null,
            Limit::perMinute(600)->by('ip:'.ClientAddress::key($request)),
        ])));

        RateLimiter::for(self::DEVICE, fn (Request $request) => Limit::perMinute(10)->by('device:'.ClientAddress::key($request)));

        RateLimiter::for(self::FORM_ENTRIES, fn (Request $request) => Limit::perMinute(10)->by('form-entries:'.SyncTokens::hash((string) $request->bearerToken())));
    }
}
