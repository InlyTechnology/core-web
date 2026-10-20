<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\ContentSync\ContentPaths;

/**
 * Where Asset::url() points on a remote-indexed disk: Statamic builds it on the
 * container's one URL, but the file may be in the local layer, the committed
 * folder or only on the live site, so this redirects to whichever holds it.
 */
class MediaFileController
{
    public function __invoke(string $disk, string $path): RedirectResponse|Response
    {
        // A plain 404, not abort(): Statamic would render it through the site's layout.
        if (! MediaDisks::isRemoteIndexed($disk) || ! ContentPaths::safe($path) || ! Storage::disk($disk)->fileExists($path)) {
            return new Response('Not found', 404);
        }

        $url = Storage::disk($disk)->url(implode('/', array_map('rawurlencode', explode('/', $path))));

        return new RedirectResponse($url, 302, ['Cache-Control' => 'no-store']);
    }
}
