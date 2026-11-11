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

        $host = self::host($site);
        $neighbours = self::neighbours($host);

        foreach ($this->domains($host) as $domain) {
            $paths = [];

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                if (self::owner($neighbours, (string) $url) === self::handle($site)) {
                    $paths[strtok((string) $url, '?')] = true;
                }
            }

            $this->invalidate(array_keys($paths), $domain);
        }
    }

    /**
     * Renders every cached page of the site again in the background, one
     * queued request per page (Statamic's StaticWarmJob), while visitors
     * keep getting the cached copy until it is replaced. Pages are found on
     * the site's host, under http and https, and owned as flush() owns
     * them; each path is requested once with every query string it was
     * cached under, and one cached as an error response is forgotten
     * instead, as Statamic does. Returns how many paths were handed on.
     */
    public function refresh(Site $site): int
    {
        if (! $this->cacher instanceof AbstractCacher) {
            return 0;
        }

        $host = self::host($site);
        $neighbours = self::neighbours($host);
        $queued = 0;

        foreach ($this->domains($host) as $domain) {
            $paths = [];

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                if (self::owner($neighbours, (string) $url) === self::handle($site)) {
                    $paths[strtok((string) $url, '?')] = true;
                }
            }

            foreach (array_keys($paths) as $path) {
                $this->cacher->refreshUrl($path, $domain);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * refresh() for every cached page on each site's host, under http and
     * https, a page outside every site's path included. Returns how many
     * paths were handed on.
     */
    public function refreshAll(): int
    {
        if (! $this->cacher instanceof AbstractCacher) {
            return 0;
        }

        $queued = 0;
        // Not every host the cache recorded: a request names its own (a forged Host header), and each render carries Statamic's recache token.
        $hosts = array_unique(array_filter(Sites::all()->map(fn (Site $site) => self::host($site))->values()->all()));

        foreach ($hosts as $host) {
            foreach ($this->domains($host) as $domain) {
                $paths = [];

                foreach ($this->cacher->getUrls($domain)->all() as $url) {
                    $paths[strtok((string) $url, '?')] = true;
                }

                foreach (array_keys($paths) as $path) {
                    $this->cacher->refreshUrl($path, $domain);
                    $queued++;
                }
            }
        }

        return $queued;
    }

    /**
     * Forgets the site's cached pages at these absolute URLs (what an
     * invalidator reports; a trailing `*` takes every page under one), as
     * flushPaths() does: under http and https, with any query string, and
     * only the pages the site owns. Returns how many were forgotten.
     *
     * @param  iterable<mixed>  $urls
     */
    public function forget(Site $site, iterable $urls): int
    {
        return $this->forgetPages([[$site, $urls]]);
    }

    /**
     * forget() for several sites at once, keyed by site handle: each host's
     * cached pages are read once for all of them, so sites sharing a host
     * cost one pass. Returns how many were forgotten.
     *
     * @param  array<string, iterable<mixed>>  $urls
     */
    public function forgetMany(array $urls): int
    {
        $pages = [];

        foreach ($urls as $handle => $siteUrls) {
            $site = Sites::get((string) $handle);

            if ($site !== null) {
                $pages[] = [$site, $siteUrls];
            }
        }

        return $this->forgetPages($pages);
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
        $target = ['prefix' => self::prefix($site), 'exact' => [], 'under' => []];

        foreach ($paths as $path) {
            $target = self::addPath($target, (string) $path);
        }

        return $this->forgetOnHost(self::host($site), [self::handle($site) => $target]);
    }

    /** @param  list<array{Site, iterable<mixed>}>  $pages */
    private function forgetPages(array $pages): int
    {
        $targets = [];
        $neighbours = [];

        foreach ($pages as [$site, $urls]) {
            $host = self::host($site);
            $prefix = self::prefix($site);

            foreach ($urls as $url) {
                // An unrouted entry reports a null URL: it has no page to forget.
                if (! is_string($url)) {
                    continue;
                }

                $wildcard = str_ends_with($url, '*');
                $address = $wildcard ? substr($url, 0, -1) : $url;

                if (parse_url($address, PHP_URL_HOST) !== $host) {
                    continue;
                }

                $neighbours[$host] ??= self::neighbours($host);

                if (self::owner($neighbours[$host], $address) !== self::handle($site)) {
                    continue;
                }

                $path = self::onSite($address, $prefix);
                $targets[$host][self::handle($site)] = self::addPath(
                    $targets[$host][self::handle($site)] ?? ['prefix' => $prefix, 'exact' => [], 'under' => []],
                    $wildcard ? rtrim($path, '/').'/*' : $path,
                );
            }
        }

        $forgotten = 0;

        foreach ($targets as $host => $sites) {
            $forgotten += $this->forgetOnHost($host, $sites, $neighbours[$host]);
        }

        return $forgotten;
    }

    /**
     * @param  array{prefix: string, exact: array<string, true>, under: list<string>}  $target
     * @return array{prefix: string, exact: array<string, true>, under: list<string>}
     */
    private static function addPath(array $target, string $path): array
    {
        $path = '/'.trim($path, '/');

        if (str_ends_with($path, '*')) {
            $target['under'][] = rtrim(substr($path, 0, -1), '/').'/';
        } else {
            $target['exact'][$path] = true;
        }

        return $target;
    }

    /**
     * Forgets the cached pages on the host that each site's target names,
     * reading each of the host's domains once.
     *
     * @param  array<string, array{prefix: string, exact: array<string, true>, under: list<string>}>  $targets  keyed by site handle
     * @param  list<array{string, string}>|null  $neighbours
     */
    private function forgetOnHost(?string $host, array $targets, ?array $neighbours = null): int
    {
        if (! $this->cacher instanceof AbstractCacher || $targets === []) {
            return 0;
        }

        $neighbours ??= self::neighbours($host);
        $forgotten = 0;

        foreach ($this->domains($host) as $domain) {
            $matched = [];

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                $target = $targets[self::owner($neighbours, (string) $url) ?? ''] ?? null;

                if ($target === null) {
                    continue;
                }

                $onSite = self::onSite((string) $url, $target['prefix']);

                if (isset($target['exact'][$onSite]) || self::under($onSite, $target['under'])) {
                    $matched[strtok((string) $url, '?')] = true;
                    $forgotten++;
                }
            }

            $this->invalidate(array_keys($matched), $domain);
        }

        return $forgotten;
    }

    /**
     * Forgets each path once with every query string it was cached under:
     * each call reads the domain's whole URL list again.
     *
     * @param  list<string>  $paths
     */
    private function invalidate(array $paths, ?string $domain): void
    {
        foreach ($paths as $path) {
            $this->cacher->invalidateUrl($path, $domain);
        }
    }

    /**
     * The cached domains on the host, under http and https.
     *
     * @return list<string>
     */
    private function domains(?string $host): array
    {
        return array_values(array_filter(
            array_map(strval(...), $this->cacher->getDomains()->all()),
            fn (string $domain) => parse_url($domain, PHP_URL_HOST) === $host,
        ));
    }

    /** @param  list<string>  $prefixes */
    private static function under(string $path, array $prefixes): bool
    {
        $dir = rtrim($path, '/').'/';

        foreach ($prefixes as $prefix) {
            if (str_starts_with($dir, $prefix) && $dir !== $prefix) {
                return true;
            }
        }

        return false;
    }

    /** Whether an absolute URL is on the site: on its host, and on a shared host under its path, as flush() decides. */
    public function owns(Site $site, string $url): bool
    {
        $host = self::host($site);

        if (parse_url($url, PHP_URL_HOST) !== $host) {
            return false;
        }

        return self::owner(self::neighbours($host), $url) === self::handle($site);
    }

    /**
     * The handle and path of each site on the host, longest path first: a
     * site's URL is resolved once per pass rather than once per cached page.
     *
     * @return list<array{string, string}>
     */
    private static function neighbours(?string $host): array
    {
        return Sites::all()
            ->filter(fn (Site $other) => self::host($other) === $host)
            ->map(fn (Site $other) => [self::handle($other), self::prefix($other)])
            ->sortByDesc(fn (array $other) => strlen($other[1]))
            ->values()
            ->all();
    }

    /** A site's handle as a string: a handle of digits (`2026:` in sites.yaml) is read as an int. */
    private static function handle(Site $site): string
    {
        return (string) $site->handle();
    }

    /**
     * The handle of the site a URL's path lies under.
     *
     * @param  list<array{string, string}>  $sites  the handle and path of each site on the URL's host, longest path first
     */
    private static function owner(array $sites, string $url): ?string
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach ($sites as [$handle, $prefix]) {
            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $handle;
            }
        }

        return null;
    }

    /** A URL's path on the site under this path ('/campaigns' for '/sv/campaigns' under '/sv'). */
    private static function onSite(string $url, string $prefix): string
    {
        return '/'.trim(substr('/'.trim((string) parse_url($url, PHP_URL_PATH), '/'), strlen($prefix)), '/');
    }

    private static function host(Site $site): ?string
    {
        return parse_url($site->absoluteUrl(), PHP_URL_HOST) ?: null;
    }

    /** The path a site sits under on its host ('/second'), '' at the root. */
    private static function prefix(Site $site): string
    {
        return rtrim((string) parse_url($site->absoluteUrl(), PHP_URL_PATH), '/');
    }
}
