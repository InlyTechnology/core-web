<?php

namespace Inly\CoreWeb\ContentSync;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In front of every sync endpoint: tokens and content travel over HTTPS only
 * (a local or testing app excepted), and a site running
 * as APP_ENV=local is nobody's remote, unless it says so
 * (content_sync.allow_local_remote), so one developer's checkout is never
 * pulled from by mistake.
 */
class GuardSyncRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::mayActAsRemote()) {
            return new JsonResponse(['error' => 'local_remote', 'message' => __('core-web::sync.local_remote')], 403);
        }

        // The exception is the app's own environment, never the Host header, which the client chooses.
        if (! $request->secure() && ! app()->environment(['local', 'testing'])) {
            return new JsonResponse(['error' => 'https_required', 'message' => __('core-web::sync.https_required')], 403);
        }

        return $next($request);
    }

    /** Whether this site answers sync calls at all, and so whether its CP offers the sync pages. */
    public static function mayActAsRemote(): bool
    {
        return ! app()->environment('local') || (bool) config('core-web.content_sync.allow_local_remote', false);
    }
}
