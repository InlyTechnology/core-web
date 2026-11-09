<?php

namespace Inly\CoreWeb\Preview;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Whether this response shows a page to someone looking at it before or
 * instead of a visit: Core's preview frame, the control panel's Live Preview
 * (a draft's preview link opens it too), or a preview route the site marks
 * itself. No consent is asked and no tracker loads on one. See
 * docs/cookie-consent.md.
 */
final class Preview
{
    private const ATTRIBUTE = 'core-web.preview';

    /**
     * Marks this response as a preview, for a preview route of the site's own (a signed link to an unreleased page),
     * and keeps it out of every cache.
     */
    public static function mark(): void
    {
        request()->attributes->set(self::ATTRIBUTE, true);
    }

    public static function active(): bool
    {
        if (app()->resolved(PreviewContext::class) && PreviewContext::current()->isRendering()) {
            return true;
        }

        $request = request();

        // Statamic reads the Live Preview token from the store, so the answer is kept for the rest of the request,
        // once Statamic has booted the macro that gives it.
        if (! $request->attributes->has(self::ATTRIBUTE) && Request::hasMacro('isLivePreview')) {
            $request->attributes->set(self::ATTRIBUTE, $request->isLivePreview());
        }

        return $request->attributes->get(self::ATTRIBUTE) === true;
    }

    /** A marked response stays out of every cache: a kept copy would outlive the link that opened it. */
    public static function keepOutOfCaches(Request $request, Response $response): Response
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true) {
            return $response;
        }

        $response->headers->set('X-Statamic-Uncacheable', 'true');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
