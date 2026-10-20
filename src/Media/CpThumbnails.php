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

/**
 * The CP's thumbnail and preview of a file only the pulled index holds: Glide
 * cannot read its bytes here, so they point at the live file instead, through
 * the CDN at the CP's thumbnail size where the CDN serves it.
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
        if (! $asset instanceof Asset || ! $data->get('is_image') || ! $url = MediaDisks::indexOnlyUrl($asset)) {
            return;
        }

        $size = (int) (Image::cpManipulationPresets()['cp_thumbnail_small_landscape']['w'] ?? 400);

        $data->thumbnail = app(UrlScheme::class)->image($url, [
            'width' => $size,
            'height' => $size,
            'fit' => 'contain',
            'format' => config('core-web.media.images.format', 'auto'),
        ]);
        $data->preview = $url;
    }
}
