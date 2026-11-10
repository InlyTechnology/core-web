<?php

namespace Inly\CoreWeb\Media;

use Statamic\Facades\Asset;
use Statamic\Http\Controllers\CP\Assets\ThumbnailController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Statamic's thumbnail route, sent to the CDN's thumbnail where CpThumbnails has one. Listings build this route
 * themselves (an entry listing's assets column) without the hook CpThumbnails rewrites, so without this every
 * row would have Glide decode its originals.
 */
final class CpThumbnailController extends ThumbnailController
{
    /**
     * @param  string  $asset
     * @param  string|null  $size
     * @param  string|null  $orientation
     */
    public function show($asset, $size = null, $orientation = null): Response
    {
        $found = Asset::find(base64_decode((string) $asset));

        if ($found !== null) {
            $this->authorize('view', $found);

            if ($url = CpThumbnails::url($found, (string) ($size ?: 'small'))) {
                return redirect()->away($url);
            }
        }

        return parent::show($asset, $size, $orientation);
    }
}
