<?php

namespace Inly\CoreWeb\Favicons;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Site;

final class FaviconController
{
    public function manifest(Request $request): JsonResponse
    {
        $site = Site::current();

        if (trim((string) $request->route('site_path'), '/') !== trim((string) parse_url($site->absoluteUrl(), PHP_URL_PATH), '/')) {
            throw new NotFoundHttpException;
        }

        return response()->json(Favicons::manifest($site->handle()), 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->setCache(['public' => true, 'max_age' => 3600]);
    }

    /** Browsers and crawlers ask for /favicon.ico whatever the head says; a redirect to the tab PNG answers them. */
    public function ico(): RedirectResponse
    {
        $url = Favicons::png(Favicons::TAB, Site::default()->handle());

        if ($url === null) {
            throw new NotFoundHttpException;
        }

        return redirect()->away($url, 302)->setCache(['public' => true, 'max_age' => 3600]);
    }
}
