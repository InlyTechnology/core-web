<?php

namespace Inly\CoreWeb\ContentSync;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In front of every sync endpoint: tokens and content travel over HTTPS only
 * (a `.test` host and a local or testing app excepted), and a site running
 * as APP_ENV=local is nobody's remote, unless it says so
 * (content_sync.allow_local_remote), so one developer's checkout is never
 * pulled from by mistake.
 */
class GuardSyncRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local') && ! config('core-web.content_sync.allow_local_remote', false)) {
            return new JsonResponse(['error' => 'local_remote', 'message' => __('core-web::sync.local_remote')], 403);
        }

        if (! $request->secure() && ! str_ends_with($request->getHost(), '.test') && ! app()->environment(['local', 'testing'])) {
            return new JsonResponse(['error' => 'https_required', 'message' => __('core-web::sync.https_required')], 403);
        }

        return $next($request);
    }
}
