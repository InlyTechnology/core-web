<?php

namespace Inly\CoreWeb\Media;

use Closure;
use Illuminate\Support\Fluent;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Image;
use Statamic\Hooks\Payload;
use Statamic\Http\Resources\CP\Assets\Asset as AssetResource;
use Statamic\Http\Resources\CP\Assets\AssetsFieldtypeAsset;
use Statamic\Http\Resources\CP\Assets\FolderAsset;
use Throwable;

/**
 * The CP's thumbnail of a media-disk file the CDN serves, rendered by the CDN at
 * the CP's size: Glide would decode the original inside the app, which for a
 * large photo takes more memory than a small server has. A file only the pulled
 * index holds also gets its preview from its public URL, and an image of it its
 * plain public URL as thumbnail where the CDN does not serve it.
 */
final class CpThumbnails
{
    /** @var list<class-string> The CP asset resources that run HasThumbnails' `asset` hook. */
    public const RESOURCES = [AssetResource::class, FolderAsset::class, AssetsFieldtypeAsset::class];

    /** The CDN makes frames of MP4 only, from a source under this size. */
    private const FRAME_EXTENSIONS = ['mp4', 'm4v'];

    private const FRAME_MAX_BYTES = 100_000_000;

    public function register(): void
    {
        foreach (self::RESOURCES as $resource) {
            // Not static: Statamic binds a hook to the resource, whose `resource` is the asset.
            $resource::hook('asset', function (Payload $payload, Closure $next) {
                CpThumbnails::apply($payload->data, $this->resource);

                return $next($payload);
            });
        }
    }

    public static function apply(Fluent $data, mixed $asset): void
    {
        if (! $asset instanceof Asset || ! ($data->get('is_image') || $asset->isVideo())) {
            return;
        }

        try {
            if ($url = MediaDisks::indexOnlyUrl($asset)) {
                // Null without a CDN frame: Glide's extractor would download the whole live video for one thumbnail.
                $data->thumbnail = self::render($asset, $url);

                if (! $asset->isVideo()) {
                    $data->preview = $url;
                }

                return;
            }

            if ($thumbnail = self::url($asset)) {
                $data->thumbnail = $thumbnail;
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The CP thumbnail of a media-disk file from the CDN, at a CP preset's size, or null where the CDN does not
     * serve the file and Glide makes it.
     */
    public static function url(Asset $asset, string $size = 'small'): ?string
    {
        try {
            $url = self::servedUrl($asset);

            return $url === null ? null : self::render($asset, $url, $size);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function servedUrl(Asset $asset): ?string
    {
        $container = $asset->container();

        // An SVG is its own thumbnail already; a private container has no URL the CDN could fetch.
        if (! MediaDisks::defines((string) $container->diskHandle()) || ! $container->accessible() || $asset->isSvg()) {
            return null;
        }

        $url = MediaDisks::layerUrl($asset) ?? $asset->url();

        return is_string($url) && app(UrlScheme::class)->serves($url) ? $url : null;
    }

    private static function render(Asset $asset, string $url, string $size = 'small'): ?string
    {
        $presets = Image::cpManipulationPresets();
        $pixels = (int) ($presets["cp_thumbnail_{$size}_landscape"]['w'] ?? $presets['cp_thumbnail_small_landscape']['w'] ?? 400);
        $scheme = app(UrlScheme::class);

        if ($asset->isVideo()) {
            return self::framable($asset) ? $scheme->frame($url, 0, $pixels, $pixels) : null;
        }

        return $scheme->image($url, [
            'width' => $pixels,
            'height' => $pixels,
            'fit' => 'contain',
            'format' => config('core-web.media.images.format', 'auto'),
        ]);
    }

    /** Any other video would get a frame URL the CDN answers with an error, a broken image in the CP. */
    private static function framable(Asset $asset): bool
    {
        return in_array(strtolower((string) $asset->extension()), self::FRAME_EXTENSIONS, true)
            && (int) $asset->size() < self::FRAME_MAX_BYTES;
    }
}
