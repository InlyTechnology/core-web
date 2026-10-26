<?php

namespace Inly\CoreWeb\Seo;

use Inly\CoreWeb\Support\Values;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Structures\Page;

/** The `seo` global as the head, the sitemaps and robots.txt read it, for the current site. */
final class Seo
{
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

    /** Whether this environment may be indexed at all: the global's noindex_{environment} toggle, and never an environment it names none for. */
    public static function indexesEnvironment(): bool
    {
        $environment = (string) app()->environment();

        if (! in_array($environment, ['local', 'staging', 'production'], true)) {
            return false;
        }

        return ! self::get("noindex_{$environment}", $environment !== 'production');
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

    /** The entry's or term's own noindex choice, else the global's default for its collection or taxonomy. */
    public static function noindex(mixed $content): bool
    {
        $content = self::content($content);

        if ($content === null) {
            return false;
        }

        $own = $content->value('seo_noindex');

        if ($own === 'noindex' || $own === true) {
            return true;
        }

        if ($own === 'index') {
            return false;
        }

        if ($content instanceof Entry) {
            return self::defaultNoindex('collection_indexing_defaults', 'collection', (string) $content->collectionHandle());
        }

        if ($content instanceof Term) {
            return self::defaultNoindex('taxonomy_indexing_defaults', 'taxonomy', (string) $content->taxonomyHandle());
        }

        return false;
    }

    public static function defaultNoindex(string $grid, string $key, string $handle, ?string $site = null): bool
    {
        foreach ((array) self::get($grid, [], $site) as $row) {
            if (in_array($handle, (array) ($row[$key] ?? []), true)) {
                return ($row['noindex'] ?? 'index') === 'noindex';
            }
        }

        return false;
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
