<?php

namespace Inly\CoreWeb\Preview;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Entry;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Core's preview frame of one of the site's own pages (Statamic connector API v1.7): the link Core asks for, sealed for
 * reading, and the page that link renders for Core's frame alone. See docs/object-preview.md#a-page-of-the-site.
 */
class PagePreviewController
{
    public function link(string $id): JsonResponse
    {
        $entry = Entry::find($id);
        $url = $entry === null ? null : PagePreview::link($entry);

        if ($url === null) {
            return response()->json(['error' => 'not_found', 'message' => __('core-web::drafts.no_page')], 404);
        }

        return response()->json(['data' => ['entry_id' => (string) $entry->id(), 'url' => $url]]);
    }

    /** Behind the link's signature, so only a link the site handed Core opens it. */
    public function frame(Request $request, string $id, PreviewFrame $frame): SymfonyResponse
    {
        $core = PreviewFrame::coreOrigin();
        $entry = Entry::find($id);
        $url = $entry === null ? null : PagePreview::address($entry);

        if ($core === null || $url === null) {
            throw new NotFoundHttpException;
        }

        return PreviewFrame::answer($frame->render($request, $url), $core);
    }
}
