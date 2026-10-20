<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Support\Arr;
use Statamic\Statamic;

/**
 * The srcset of an image the CDN does not serve, from the site's `w<px>`
 * Glide presets: the only sizes rendered without a CDN, since presets are
 * generated on upload while arbitrary widths would be rendered on request.
 */
final class GlidePresets
{
    private const RASTER = '/\.(jpe?g|png|webp)$/i';

    /** Presets narrower than the original, then the original at its own width; null when there is nothing to offer. */
    public static function srcset(MediaSource $source): ?string
    {
        $asset = $source->asset;
        $presets = self::widths();

        if (! $asset || $source->isSvg() || $presets === [] || preg_match(self::RASTER, $asset->path()) !== 1) {
            return null;
        }

        // A failing Glide render (or a storage outage measuring the original) leaves the plain image.
        return rescue(function () use ($source, $asset, $presets): ?string {
            // Meta read whole, never width(): that re-measures the file on every call while the width is missing.
            $original = $source->width ?? (int) Arr::get($asset->meta(), 'width');
            $candidates = [];

            foreach ($presets as $preset => $width) {
                if (! $original || $width < $original) {
                    $url = Statamic::tag('glide')->params(['src' => $asset, 'preset' => $preset])->fetch();

                    // The tag catches its own render failures and returns nothing.
                    if (blank($url)) {
                        return null;
                    }

                    $candidates[] = $url.' '.$width.'w';
                }
            }

            if ($original) {
                $candidates[] = $source->url.' '.$original.'w';
            }

            return $candidates === [] ? null : implode(', ', $candidates);
        });
    }

    /** @return array<string, int> preset name => width, smallest first */
    private static function widths(): array
    {
        $widths = [];

        foreach (array_keys((array) config('statamic.assets.image_manipulation.presets', [])) as $name) {
            if (preg_match('/^w(\d+)$/', (string) $name, $m) === 1 && (int) $m[1] > 0) {
                $widths[(string) $name] = (int) $m[1];
            }
        }

        asort($widths);

        return $widths;
    }
}
