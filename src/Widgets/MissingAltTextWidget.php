<?php

namespace Inly\CoreWeb\Widgets;

use Illuminate\Contracts\View\View;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\Media\MissingAltText;
use Inly\CoreWeb\Media\ScanMissingAltTextJob;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Asset as Assets;
use Statamic\Facades\AssetContainer;
use Statamic\Widgets\Widget;

/**
 * The `missing_alt_text` dashboard widget: how many images in a container
 * have no alt text, the newest few to open and fix. The count comes from a
 * queued scan (MissingAltText), which a visit only starts.
 *
 *     ['type' => 'missing_alt_text', 'container' => 'assets', 'limit' => 5, 'width' => 50]
 */
class MissingAltTextWidget extends Widget
{
    protected static $handle = 'missing_alt_text';

    public function html(): View|string
    {
        $handle = (string) $this->config('container', 'assets');
        $container = AssetContainer::find($handle);

        if ($container === null) {
            return '<ui-widget><div class="px-4 py-3 text-sm text-red-600" v-pre>'.e("Asset container [{$handle}] does not exist.").'</div></ui-widget>';
        }

        $field = (string) $this->config('field', 'alt');
        $scan = MissingAltText::cached($handle, $field);

        if ($scan === null) {
            $job = dispatch(new ScanMissingAltTextJob($handle, $field));

            // On a sync queue the scan would run inside this request; after the response it costs the editor nothing.
            if (Queue::connection() instanceof SyncQueue) {
                $job->afterResponse();
            }
        }

        $limit = max(1, min(MissingAltText::KEEP, (int) $this->config('limit', 5)));
        $assets = collect($scan['ids'] ?? [])
            ->map(fn (string $id) => Assets::find($id))
            ->filter(fn ($asset) => $asset instanceof Asset)
            ->take($limit)
            ->map(fn (Asset $asset) => [
                'name' => $asset->basename(),
                'folder' => $asset->folder() === '/' ? null : $asset->folder(),
                'thumbnail' => $asset->thumbnailUrl('small'),
                'edit_url' => $asset->editUrl(),
            ])
            ->values()
            ->all();

        return view('core-web::widgets.missing-alt-text', [
            'title' => __((string) $this->config('title', 'Images missing alt text')),
            'container' => $container->title(),
            'scanned' => $scan !== null,
            'count' => $scan['count'] ?? 0,
            'assets' => $assets,
        ]);
    }
}
