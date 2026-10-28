<?php

namespace Inly\CoreWeb\Seo;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Statamic;

/**
 * Answers Statamic's 404 with the redirect the editors set for its path, before the 404 page renders or a cached one
 * is served: any route that throws Statamic's NotFoundHttpException, the front end and a site's own controllers.
 */
final class RedirectMissingPages
{
    public static function register(): void
    {
        NotFoundHttpException::renderUsing(fn (Request $request) => RedirectMissingPages::respond($request));
    }

    public static function respond(Request $request): ?RedirectResponse
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true) || Statamic::isCpRoute() || Statamic::isApiRoute()) {
            return null;
        }

        $redirect = Redirects::for($request->getPathInfo());

        if ($redirect === null) {
            return null;
        }

        $response = redirect()->away(self::withQuery($redirect['to'], (string) $request->getQueryString()), $redirect['status']);

        // A 301 without Cache-Control is kept by browsers indefinitely, so an editor's corrected target would never reach them.
        if ((int) $redirect['status'] === 301) {
            $response->setPublic()->setMaxAge((int) config('core-web.seo.redirects_max_age', 86400));
        }

        return $response;
    }

    private static function withQuery(string $to, string $query): string
    {
        if ($query === '') {
            return $to;
        }

        [$url, $fragment] = array_pad(explode('#', $to, 2), 2, null);

        return $url.(str_contains($url, '?') ? '&' : '?').$query.($fragment === null ? '' : '#'.$fragment);
    }
}
