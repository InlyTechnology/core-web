<?php

namespace Inly\CoreWeb\Seo;

use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\Support\Values;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Structures\Page;
use Throwable;

/** The `seo` global as the head, the sitemaps and robots.txt read it, for the current site. */
final class Seo
{
    private const TRACKER_CACHE_KEY = 'core-web.seo.has-tracker.';

    /** A value off the seo global in the given (or current) site, falling back to its origin. */
    public static function get(string $key, mixed $default = null, ?string $site = null): mixed
    {
        $value = self::variables($site)?->value($key);

        return $value ?? $default;
    }

    /** An augmented value off the seo global, for the fields that hold assets or entries. */
    public static function augmented(string $key, ?string $site = null): mixed
    {
        $variables = self::variables($site);

        return $variables === null ? null : Values::unwrap($variables->augmentedValue($key));
    }

    /** Whether this environment may be indexed at all: only production, and only while its noindex_production toggle is off. */
    public static function indexesEnvironment(): bool
    {
        return app()->environment('production') && ! self::get('noindex_production', false);
    }

    /** Whether the Google tags render in this environment: the global's trackers_{environment} toggle. */
    public static function trackersEnabled(): bool
    {
        $environment = (string) app()->environment();

        if (! in_array($environment, ['local', 'staging', 'production'], true)) {
            return false;
        }

        return (bool) self::get("trackers_{$environment}", false);
    }

    /** Whether any site's seo global names a tracker a button's click event reaches (Tag Manager, gtag or Fathom), cached for an hour or until a global is saved, since content also arrives by deploy and import. */
    public static function hasTracker(): bool
    {
        $handle = (string) config('core-web.seo.global', 'seo');

        if ($handle === '') {
            return false;
        }

        try {
            return Cache::remember(self::TRACKER_CACHE_KEY.$handle, now()->addHour(), fn (): bool => (bool) GlobalSet::findByHandle($handle)?->localizations()->contains(
                fn ($variables): bool => in_array($variables->value('tracker_type'), ['gtm', 'sgtm', 'gtag'], true) || (bool) $variables->value('use_fathom'),
            ));
        } catch (Throwable) {
            // Read while the button fieldset is built at boot, before a fresh install has its content store.
            return false;
        }
    }

    public static function forgetTracker(): void
    {
        Cache::forget(self::TRACKER_CACHE_KEY.config('core-web.seo.global', 'seo'));
    }

    /** The entry's or term's own noindex choice. */
    public static function noindex(mixed $content): bool
    {
        $own = self::content($content)?->value('seo_noindex');

        return $own === 'noindex' || $own === true;
    }

    /** A structure page is its entry; anything else that is neither an entry nor a term is no content. */
    public static function content(mixed $content): Entry|Term|null
    {
        $content = Values::unwrap($content);

        if ($content instanceof Page) {
            $content = $content->entry();
        }

        return $content instanceof Entry || $content instanceof Term ? $content : null;
    }

    private static function variables(?string $site): mixed
    {
        $handle = (string) config('core-web.seo.global', 'seo');
        $set = $handle === '' ? null : GlobalSet::findByHandle($handle);

        return $set?->in($site ?? Site::current()->handle());
    }
}
