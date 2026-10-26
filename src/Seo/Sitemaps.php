<?php

namespace Inly\CoreWeb\Seo;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term as Terms;

/**
 * The pages /sitemap.xml lists for a site: the published entries of the global's sitemap collections, the terms of
 * its taxonomies and of its collection taxonomies, each unless it is noindexed. Built once and cached until content
 * the list reads is saved (the provider flushes it).
 */
final class Sitemaps
{
    private const CACHE_KEY = 'core-web.seo.sitemap.';

    public static function enabled(string $site): bool
    {
        return (bool) config('core-web.seo.sitemap.enabled', true) && (bool) Seo::get('use_sitemap', true, $site);
    }

    /** @return list<array{loc: string, lastmod: ?string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}> */
    public static function entries(string $site): array
    {
        $minutes = (int) config('core-web.seo.sitemap.cache_minutes', 1440);

        if ($minutes <= 0) {
            return self::build($site);
        }

        return Cache::remember(self::CACHE_KEY.$site, now()->addMinutes($minutes), fn () => self::build($site));
    }

    public static function flush(): void
    {
        foreach (Site::all() as $site) {
            Cache::forget(self::CACHE_KEY.$site->handle());
        }
    }

    /** @return list<array<string, mixed>> */
    private static function build(string $site): array
    {
        $urls = [];

        foreach (self::handles(Seo::get('sitemap_collections', ['pages'], $site)) as $collection) {
            $entries = Entries::query()->where('collection', $collection)->where('site', $site)->whereStatus('published')->get();

            foreach ($entries as $entry) {
                if ($entry->url() !== null && ! Seo::noindex($entry)) {
                    $urls[] = self::url($entry, self::alternates($entry));
                }
            }
        }

        foreach (self::handles(Seo::get('sitemap_taxonomies', [], $site)) as $taxonomy) {
            foreach (self::terms($taxonomy, $site) as $term) {
                $urls[] = self::url($term);
            }
        }

        foreach ((array) Seo::get('sitemap_collection_taxonomies', [], $site) as $row) {
            foreach (self::handles($row['taxonomy'] ?? []) as $taxonomy) {
                foreach (self::handles($row['collections'] ?? []) as $collection) {
                    foreach (self::terms($taxonomy, $site, $collection) as $term) {
                        $urls[] = self::url($term);
                    }
                }
            }
        }

        return array_values(collect($urls)->unique('loc')->all());
    }

    /** @return iterable<Term> */
    private static function terms(string $handle, string $site, ?string $collection = null): iterable
    {
        $context = $collection === null ? null : Collections::findByHandle($collection);

        if (Taxonomy::findByHandle($handle) === null || ($collection !== null && $context === null)) {
            return [];
        }

        return Terms::query()->where('taxonomy', $handle)->where('site', $site)->get()
            ->map(fn ($term) => $context === null ? $term : $term->collection($context))
            ->filter(fn ($term) => $term->url() !== null && ($collection === null || $term->entriesCount() > 0) && ! Seo::noindex($term));
    }

    /**
     * @param  list<array{hreflang: string, href: string}>  $alternates
     * @return array<string, mixed>
     */
    private static function url(Entry|Term $content, array $alternates = []): array
    {
        $updated = $content->lastModified();

        return [
            'loc' => (string) $content->absoluteUrl(),
            'lastmod' => $updated instanceof CarbonInterface ? $updated->toDateString() : null,
            'changefreq' => (string) ($content->value('sitemap_change_frequency') ?: 'weekly'),
            'priority' => (string) ($content->value('sitemap_priority') ?: '0.5'),
            'alternates' => $alternates,
        ];
    }

    /** @return list<array{hreflang: string, href: string}> */
    private static function alternates(Entry $entry): array
    {
        if (Site::all()->count() < 2) {
            return [];
        }

        $identifier = $entry->site()->attribute('identifier');
        $origin = $entry->root();

        $alternates = Site::all()
            ->filter(fn ($site) => $site->attribute('identifier') === $identifier)
            ->map(fn ($site) => $origin->in($site->handle()))
            ->filter(fn ($entry) => $entry !== null && $entry->published() && $entry->url() !== null && ! Seo::noindex($entry))
            ->map(fn (Entry $entry) => ['hreflang' => str_replace('_', '-', (string) $entry->site()->locale()), 'href' => (string) $entry->absoluteUrl()])
            ->values()
            ->all();

        return count($alternates) > 1 ? $alternates : [];
    }

    /** @return list<string> */
    private static function handles(mixed $value): array
    {
        return array_values(array_filter(array_map('strval', (array) $value)));
    }
}
