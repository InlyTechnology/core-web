<?php

namespace Inly\CoreWeb\Favicons;

use GdImage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Inly\CoreWeb\Support\Values;
use Statamic\Contracts\Assets\Asset;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Glide;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Statamic;

/**
 * The control panel's tab icon: the site's PNG with a dot in its corner, so the CMS and the site tell apart side by
 * side. Statamic renders the CP favicon through Glide from statamic.cp.custom_favicon_url, which only reads a file,
 * so the icon is written into the favicons container whenever the global is saved. Statamic shows it on Pro only.
 */
final class CpFavicon
{
    public const PATH = 'cp-favicon.png';

    private const SIZE = 64;

    /** GD holds the whole image in memory, and running out of it is a fatal error no rescue catches. */
    private const MAX_SOURCE = 4096;

    public static function register(): void
    {
        Event::listen(GlobalVariablesSaved::class, function (GlobalVariablesSaved $event): void {
            if ($event->variables->globalSet()->handle() === config('core-web.favicons.global') && $event->variables->locale() === Site::default()->handle()) {
                self::generate($event->variables);
            }
        });

        // Only once the file exists: Glide fails the whole CP head on a favicon it cannot find.
        View::composer('statamic::partials.head', function (): void {
            if (Statamic::pro() && blank(config('statamic.cp.custom_favicon_url')) && ($asset = self::asset()) !== null) {
                config(['statamic.cp.custom_favicon_url' => $asset->id()]);
            }
        });
    }

    /**
     * Writes the marked icon from the default site's PNG (the fields are not localizable, so only its save
     * regenerates), and removes it when the PNG is gone; false when there is nothing to write.
     */
    public static function generate(mixed $variables = null): bool
    {
        $container = AssetContainer::findByHandle(self::containerHandle());

        if ($container === null || ! function_exists('imagecreatefromstring')) {
            return false;
        }

        $source = self::source($variables);
        $png = $source === null ? null : rescue(fn () => self::mark((string) $source->contents()), report: false);

        if (! is_string($png)) {
            if ($source === null && ($old = $container->asset(self::PATH)) !== null) {
                Glide::clearAsset($old);
                $old->delete();
            }

            return false;
        }

        // Unchanged bytes are not written again, so a save of the global commits nothing new under git automation.
        if ($container->disk()->exists(self::PATH) && $container->disk()->get(self::PATH) === $png) {
            return true;
        }

        $container->disk()->put(self::PATH, $png);
        $asset = $container->makeAsset(self::PATH);
        $asset->save();
        Glide::clearAsset($asset);

        return true;
    }

    public static function asset(): ?Asset
    {
        return AssetContainer::findByHandle(self::containerHandle())?->asset(self::PATH);
    }

    private static function mark(string $bytes): ?string
    {
        $dimensions = @getimagesizefromstring($bytes);

        if ($dimensions === false || max($dimensions[0], $dimensions[1]) > self::MAX_SOURCE) {
            return null;
        }

        $original = @imagecreatefromstring($bytes);

        if (! $original instanceof GdImage) {
            return null;
        }

        // A centred square, as the site's own icons are cropped.
        $side = min(imagesx($original), imagesy($original));
        $x = intdiv(imagesx($original) - $side, 2);
        $y = intdiv(imagesy($original) - $side, 2);

        $size = self::SIZE;
        $icon = imagecreatetruecolor($size, $size);
        imagealphablending($icon, false);
        imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
        imagealphablending($icon, true);
        imagecopyresampled($icon, $original, 0, 0, $x, $y, $size, $size, $side, $side);

        [$r, $g, $b] = self::rgb((string) config('core-web.favicons.cp_mark.color', '#ff269e'));
        $dot = (int) round($size * 0.42);
        $centre = $size - (int) ceil(($dot + 8) / 2);
        imagefilledellipse($icon, $centre, $centre, $dot + 8, $dot + 8, imagecolorallocate($icon, 255, 255, 255));
        imagefilledellipse($icon, $centre, $centre, $dot, $dot, imagecolorallocate($icon, $r, $g, $b));

        imagesavealpha($icon, true);
        ob_start();
        imagepng($icon);

        return (string) ob_get_clean();
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $hex = strlen($hex) === 3 ? preg_replace('/(.)/', '$1$1', $hex) : $hex;

        if (preg_match('/^[0-9a-f]{6}$/i', (string) $hex) !== 1) {
            return [255, 38, 158];
        }

        return array_map('hexdec', str_split((string) $hex, 2));
    }

    private static function source(mixed $variables): ?Asset
    {
        $handle = (string) config('core-web.favicons.global', 'favicons');
        $variables ??= $handle === '' ? null : GlobalSet::findByHandle($handle)?->in(Site::default()->handle());
        $asset = $variables === null ? null : Values::first($variables->augmentedValue('favicon'));

        return $asset instanceof Asset ? $asset : null;
    }

    private static function containerHandle(): string
    {
        return (string) config('core-web.favicons.cp_mark.container', 'favicons');
    }
}
