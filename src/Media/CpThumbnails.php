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
 * index holds also gets its preview from its public URL, and its thumbnail is
 * the plain public URL where the CDN does not serve it.
 */
final class CpThumbnails
{
    /** @var list<class-string> The CP asset resources that run HasThumbnails' `asset` hook. */
    public const RESOURCES = [AssetResource::class, FolderAsset::class, AssetsFieldtypeAsset::class];

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
    }

    /** The CP thumbnail of a media-disk file from the CDN, or null where the CDN does not serve the file and Glide makes it. */
    public static function url(Asset $asset): ?string
    {
        $url = self::servedUrl($asset);

        return $url === null ? null : self::render($asset, $url);
    }

    private static function servedUrl(Asset $asset): ?string
    {
        try {
            $container = $asset->container();

            // An SVG is its own thumbnail already; a private container has no URL the CDN could fetch.
            if (! MediaDisks::defines((string) $container->diskHandle()) || ! $container->accessible() || $asset->isSvg()) {
                return null;
            }

            $url = MediaDisks::layerUrl($asset) ?? $asset->url();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return is_string($url) && app(UrlScheme::class)->serves($url) ? $url : null;
    }

    private static function render(Asset $asset, string $url): ?string
    {
        $size = (int) (Image::cpManipulationPresets()['cp_thumbnail_small_landscape']['w'] ?? 400);
        $scheme = app(UrlScheme::class);

        if ($asset->isVideo()) {
            return $scheme->frame($url, 0, $size, $size);
        }

        return $scheme->image($url, [
            'width' => $size,
            'height' => $size,
            'fit' => 'contain',
            'format' => config('core-web.media.images.format', 'auto'),
        ]);
    }
}
