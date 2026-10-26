<?php

namespace Inly\CoreWeb\Favicons;

use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Media\MediaSource;
use Inly\CoreWeb\Media\UrlScheme;
use Inly\CoreWeb\Seo\Seo;
use Inly\CoreWeb\Support\Values;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\URL;
use Statamic\Statamic;

/**
 * The site's favicons, every size cut from the one PNG on the favicons global:
 * by the CDN when it serves the file, by Glide otherwise. Nothing is generated
 * or stored, so a new upload is the new icon everywhere.
 */
final class Favicons
{
    public const TAB = 32;

    public const APPLE = 180;

    public const MANIFEST = [192, 512];

    /** The PNG at a square size, absolute; the file itself when no rendition can be made. */
    public static function png(int $size, ?string $site = null): ?string
    {
        $source = MediaSource::from(self::augmented('favicon', $site));

        if ($source === null || $source->isVideo() || $source->isSvg()) {
            return null;
        }

        return URL::makeAbsolute(self::rendition($source, $size) ?? $source->url);
    }

    public static function svg(string $field = 'favicon_svg', ?string $site = null): ?string
    {
        $source = MediaSource::from(self::augmented($field, $site));

        return $source?->isSvg() ? URL::makeAbsolute($source->url) : null;
    }

    public static function themeColor(?string $site = null): ?string
    {
        $color = Values::unwrap(self::variables($site)?->value('theme_color'));

        return is_string($color) && preg_match('/^#[0-9a-f]{3,8}$/i', $color) === 1 ? $color : null;
    }

    /** The manifest's address for the current site: under the site's path when it shares a host. */
    public static function manifestUrl(): string
    {
        return rtrim(Site::current()->absoluteUrl(), '/').'/site.webmanifest';
    }

    /** @return array<string, mixed> */
    public static function manifest(?string $site = null): array
    {
        $site ??= Site::current()->handle();
        $icons = [];

        foreach (self::MANIFEST as $size) {
            if ($url = self::png($size, $site)) {
                $icons[] = ['src' => $url, 'sizes' => "{$size}x{$size}", 'type' => 'image/png'];
            }
        }

        $name = CoreWebServiceProvider::enabled('seo') ? Seo::get('site_name', null, $site) : null;

        return array_filter([
            'name' => filled($name) ? (string) $name : (string) config('app.name'),
            'start_url' => Site::get($site)?->url() ?? '/',
            'display' => 'standalone',
            'theme_color' => self::themeColor($site),
            'background_color' => self::themeColor($site),
            'icons' => $icons,
        ], fn ($value) => $value !== null && $value !== []);
    }

    private static function rendition(MediaSource $source, int $size): ?string
    {
        $scheme = CoreWebServiceProvider::enabled('media') ? app(UrlScheme::class) : null;

        if ($scheme !== null && $scheme->serves($source->url)) {
            return $scheme->image($source->url, ['width' => $size, 'height' => $size, 'fit' => 'cover', 'format' => 'png']);
        }

        if ($source->asset === null) {
            return null;
        }

        // The tag catches its own render failures and returns nothing.
        $url = rescue(fn () => Statamic::tag('glide')->params([
            'src' => $source->asset, 'w' => $size, 'h' => $size, 'fit' => 'crop', 'fm' => 'png',
        ])->fetch());

        return is_string($url) && $url !== '' ? $url : null;
    }

    private static function augmented(string $key, ?string $site): mixed
    {
        $variables = self::variables($site);

        return $variables === null ? null : Values::unwrap($variables->augmentedValue($key));
    }

    private static function variables(?string $site): mixed
    {
        $handle = (string) config('core-web.favicons.global', 'favicons');
        $set = $handle === '' ? null : GlobalSet::findByHandle($handle);

        return $set?->in($site ?? Site::current()->handle());
    }
}
