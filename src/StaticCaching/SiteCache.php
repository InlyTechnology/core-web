<?php

namespace Inly\CoreWeb\StaticCaching;

use Closure;
use Inly\CoreWeb\Sitemaps\SnapshotSitemaps;
use Inly\CoreWeb\Snapshots\SnapshotChange;
use Psr\Log\LoggerInterface;
use Statamic\Facades\Site as Sites;
use Statamic\Sites\Site;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\AbstractCacher;

/** The static cache of one site of a multisite install, whose sites share no cached pages. */
class SiteCache
{
    public function __construct(private Cacher $cacher) {}

    /**
     * A snapshot change listener (Snapshot::onChange): a Core snapshot moving
     * saves no entry, so the pages showing it are flushed here. A partition
     * names a site handle; an unpartitioned snapshot flushes the default site.
     *
     * Only the pages the changed documents appear on are flushed when the
     * snapshot names them (Snapshot::dependents()), with the snapshot's own
     * sitemap; the whole site is flushed when it does not, when what changed
     * is not known, when more documents changed than
     * core-web.snapshots.targeted_flush.max_documents, or when naming the
     * pages fails. A page left cached with old data is worse than one
     * rendered again.
     *
     * @return Closure(?string, array<string, array<string, mixed>>=, ?SnapshotChange=): void
     */
    public static function flushOnChange(): Closure
    {
        return function (?string $partition, array $documents = [], ?SnapshotChange $change = null): void {
            $site = $partition === null ? Sites::default() : Sites::get($partition);

            if ($site === null) {
                return;
            }

            $cache = app(self::class);
            $paths = self::targets($change, $site);

            if ($paths === null) {
                $cache->flush($site);

                return;
            }

            $flushed = $cache->flushPaths($site, $paths);

            app(LoggerInterface::class)->debug('Core snapshot change flushed its pages', [
                'snapshot' => $change?->snapshot->type,
                'site' => $site->handle(),
                'documents' => $change?->count(),
                'pages' => $flushed,
            ]);
        };
    }

    /**
     * The paths a change flushes on the site, or null for the whole site.
     *
     * @return list<string>|null
     */
    private static function targets(?SnapshotChange $change, Site $site): ?array
    {
        $config = (array) config('core-web.snapshots.targeted_flush', []);

        if ($change === null || ! ($config['enabled'] ?? true) || $change->count() > (int) ($config['max_documents'] ?? 20)) {
            return null;
        }

        $paths = $change->dependents();

        if ($paths === null) {
            return null;
        }

        if ($change->snapshot->hasSitemap($site->handle())) {
            $paths[] = '/'.SnapshotSitemaps::path($change->snapshot->type);
        }

        return array_values(array_unique($paths));
    }

    /**
     * A snapshot change listener for data every site shows (a staff
     * register, say): flushes each site's cached pages.
     *
     * @return Closure(?string): void
     */
    public static function flushEverySiteOnChange(): Closure
    {
        return function (): void {
            foreach (Sites::all() as $site) {
                app(self::class)->flush($site);
            }
        };
    }

    /**
     * Forgets every cached page of the site, on whichever of its hosts it
     * was cached. Sites may share a host, each under its own path
     * (https://example.com and https://example.com/second): a cached page
     * belongs to the site with the longest path it lies under, so flushing
     * one leaves the others' pages alone.
     */
    public function flush(Site $site): void
    {
        // No static caching (local, tests): nothing is cached, nothing to forget.
        if (! $this->cacher instanceof AbstractCacher) {
            return;
        }

        $host = parse_url($site->absoluteUrl(), PHP_URL_HOST);
        $neighbours = Sites::all()
            ->filter(fn (Site $other) => parse_url($other->absoluteUrl(), PHP_URL_HOST) === $host)
            ->sortByDesc(fn (Site $other) => strlen(self::prefix($other)));

        foreach ($this->cacher->getDomains() as $domain) {
            if (parse_url((string) $domain, PHP_URL_HOST) !== $host) {
                continue;
            }

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                if (self::owner($neighbours, (string) $url)?->handle() === $site->handle()) {
                    $this->cacher->invalidateUrl($url, $domain);
                }
            }
        }
    }

    /**
     * Forgets the site's cached pages at these paths, each with any query
     * string it was cached under. A path is on the site ('/campaigns', under
     * the site's own path on a shared host); a trailing `*` takes every page
     * under it ('/brands/*', not '/brands' itself). Returns how many cached
     * pages were forgotten.
     *
     * @param  iterable<string>  $paths
     */
    public function flushPaths(Site $site, iterable $paths): int
    {
        if (! $this->cacher instanceof AbstractCacher) {
            return 0;
        }

        $exact = [];
        $prefixes = [];

        foreach ($paths as $path) {
            $path = '/'.trim((string) $path, '/');

            if (str_ends_with($path, '*')) {
                $prefixes[] = rtrim(substr($path, 0, -1), '/').'/';
            } else {
                $exact[$path] = true;
            }
        }

        $host = parse_url($site->absoluteUrl(), PHP_URL_HOST);
        $prefix = self::prefix($site);
        $neighbours = Sites::all()
            ->filter(fn (Site $other) => parse_url($other->absoluteUrl(), PHP_URL_HOST) === $host)
            ->sortByDesc(fn (Site $other) => strlen(self::prefix($other)));
        $forgotten = 0;

        foreach ($this->cacher->getDomains() as $domain) {
            if (parse_url((string) $domain, PHP_URL_HOST) !== $host) {
                continue;
            }

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                if (self::owner($neighbours, (string) $url)?->handle() !== $site->handle()) {
                    continue;
                }

                $onSite = '/'.trim(substr('/'.trim((string) parse_url((string) $url, PHP_URL_PATH), '/'), strlen($prefix)), '/');

                if (isset($exact[$onSite]) || self::under($onSite, $prefixes)) {
                    $this->cacher->invalidateUrl($url, $domain);
                    $forgotten++;
                }
            }
        }

        return $forgotten;
    }

    /** @param  list<string>  $prefixes */
    private static function under(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path.'/', $prefix) && $path.'/' !== $prefix) {
                return true;
            }
        }

        return false;
    }

    /** @param  iterable<Site>  $sites  the sites on the URL's host, longest path first */
    private static function owner(iterable $sites, string $url): ?Site
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach ($sites as $site) {
            $prefix = self::prefix($site);

            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $site;
            }
        }

        return null;
    }

    /** The path a site sits under on its host ('/second'), '' at the root. */
    private static function prefix(Site $site): string
    {
        return rtrim((string) parse_url($site->absoluteUrl(), PHP_URL_PATH), '/');
    }
}
