<?php

namespace Inly\CoreWeb\StaticCaching;

use Closure;
use Illuminate\Support\Collection;
use Inly\CoreWeb\Sitemaps\SnapshotSitemaps;
use Inly\CoreWeb\Snapshots\SnapshotChange;
use Psr\Log\LoggerInterface;
use Statamic\Facades\Site as Sites;
use Statamic\Facades\URL;
use Statamic\Sites\Site;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\AbstractCacher;

/** The static cache of one site, taking the install's sites to share no cached pages. */
class SiteCache
{
    /** @var array<'app'|'served', array{key: string, sites: Collection<array-key, Site>, placed: list<array{string, ?string, string}>}> */
    private static array $placed = [];

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
     * belongs to the site siteOf() names, so flushing one leaves the others'
     * pages alone.
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

        $this->recordSite($site);
    }

    /**
     * Refreshes the site's cached pages, owned as flush() owns them
     * (docs/static-cache.md). Returns how many paths were handed on.
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
                $this->renderAgain($path, $domain);
                $queued++;
            }
        }

        if (! config('statamic.static_caching.background_recache')) {
            $this->recordSite($site);
        }

        return $queued;
    }

    /**
     * refresh() for every cached page on each site's host
     * (docs/static-cache.md). Returns how many paths were handed on.
     */
    public function refreshAll(): int
    {
        if (! $this->cacher instanceof AbstractCacher) {
            return 0;
        }

        $recache = (bool) config('statamic.static_caching.background_recache');
        $queued = 0;
        $hosts = array_unique(array_filter(Sites::all()->map(fn (Site $site) => self::host($site))->values()->all()));

        foreach (array_map(strval(...), $this->cacher->getDomains()->all()) as $domain) {
            $paths = [];

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                $paths[strtok((string) $url, '?')] = true;
            }

            $onSite = in_array(parse_url($domain, PHP_URL_HOST), $hosts, true);
            $queued += $onSite ? count($paths) : 0;

            if (! $recache) {
                continue;
            }

            // A request names its own host (a forged Host header), so a render goes only to a site's, each carrying Statamic's recache token.
            if (! $onSite) {
                $this->invalidate(array_keys($paths), $domain);
                $this->recordDomain($domain);

                continue;
            }

            foreach (array_keys($paths) as $path) {
                $this->renderAgain($path, $domain);
            }
        }

        // Every page on every host goes, so the whole cache at once: page by page rewrites the URL list for each page.
        if (! $recache) {
            $this->cacher->flush();
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

        $host = self::host($site);
        $neighbours = self::neighbours($host);
        $forgotten = $this->forgetOnHost($host, [self::handle($site) => $target], $neighbours);
        $this->record($site, $target, $neighbours);

        return $forgotten;
    }

    /**
     * refresh() for the site's cached pages at these paths, named as
     * flushPaths() names them (docs/static-cache.md). Returns how many paths
     * were handed on.
     *
     * @param  iterable<string>  $paths
     */
    public function refreshPaths(Site $site, iterable $paths): int
    {
        $target = ['prefix' => self::prefix($site), 'exact' => [], 'under' => []];

        foreach ($paths as $path) {
            $target = self::addPath($target, (string) $path);
        }

        $host = self::host($site);
        $neighbours = self::neighbours($host);
        $queued = 0;

        foreach ($this->matchOnHost($host, [self::handle($site) => $target], $neighbours) as $domain => $matched) {
            foreach (array_keys($matched) as $path) {
                $this->renderAgain($path, $domain);
                $queued++;
            }
        }

        if (! config('statamic.static_caching.background_recache')) {
            $this->record($site, $target, $neighbours);
        }

        return $queued;
    }

    /** @param  list<array{Site, iterable<mixed>}>  $pages */
    private function forgetPages(array $pages): int
    {
        $targets = [];
        $neighbours = [];
        $sites = [];

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

                if (! self::onHost($site, $address)) {
                    continue;
                }

                $neighbours[$host] ??= self::neighbours($host);

                if (self::owner($neighbours[$host], $address) !== self::handle($site)) {
                    continue;
                }

                $path = self::onSite($address, $prefix);
                $sites[self::handle($site)] = $site;
                $targets[$host][self::handle($site)] = self::addPath(
                    $targets[$host][self::handle($site)] ?? ['prefix' => $prefix, 'exact' => [], 'under' => []],
                    $wildcard ? rtrim($path, '/').'/*' : $path,
                );
            }
        }

        $forgotten = 0;

        foreach ($targets as $host => $onHost) {
            $forgotten += $this->forgetOnHost($host, $onHost, $neighbours[$host]);

            foreach ($onHost as $handle => $target) {
                $this->record($sites[$handle], $target, $neighbours[$host]);
            }
        }

        return $forgotten;
    }

    /**
     * Records the clear of the target's pages the site owns for the guarded cacher, under http and https, whether they
     * were listed or not: docs/static-cache.md#pages-cached-while-a-clear-runs.
     *
     * @param  array{prefix: string, exact: array<string, true>, under: list<string>}  $target
     * @param  list<array{string, ?string, string}>  $neighbours
     */
    private function record(Site $site, array $target, array $neighbours): void
    {
        if (! $this->cacher instanceof GuardedApplicationCacher) {
            return;
        }

        $prefix = $target['prefix'];
        $named = [];

        foreach (array_keys($target['exact']) as $path) {
            $onHost = $prefix.((string) $path === '/' && $prefix !== '' ? '' : (string) $path);

            if (self::owner($neighbours, $onHost) === self::handle($site)) {
                $named[$onHost] = true;
                $named[rtrim($onHost, '/').'/'] = true;
            }
        }

        $under = [];
        $whole = false;

        foreach ($target['under'] as $onSite) {
            $onHost = $prefix.$onSite;

            if (self::owner($neighbours, $onHost) !== self::handle($site)) {
                continue;
            }

            // A prefix with another site under it cannot be named without that site's pages: the whole site is.
            if (self::nestsOthers($neighbours, $site, $onHost)) {
                $whole = true;
            } else {
                $under[] = $onHost;
            }
        }

        foreach (['http', 'https'] as $scheme) {
            $this->cacher->recordClears($scheme.'://'.self::host($site), array_keys($named), $under);
        }

        if ($whole) {
            $this->recordSite($site);
        }
    }

    /** Records the clear of every page on the domain for the guarded cacher, listed or not: docs/static-cache.md#pages-cached-while-a-clear-runs. */
    private function recordDomain(string $domain): void
    {
        if ($this->cacher instanceof GuardedApplicationCacher) {
            $this->cacher->recordClears($domain, [], ['/']);
        }
    }

    /** Records the clear of every page the site owns for the guarded cacher: docs/static-cache.md#pages-cached-while-a-clear-runs. */
    private function recordSite(Site $site): void
    {
        if ($this->cacher instanceof GuardedApplicationCacher) {
            $this->cacher->recordSiteClear(self::handle($site));
        }
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
     * reading each of the host's domains once. Returns how many cached
     * pages were forgotten, query strings counted apart.
     *
     * @param  array<string, array{prefix: string, exact: array<string, true>, under: list<string>}>  $targets  keyed by site handle
     * @param  list<array{string, ?string, string}>|null  $neighbours
     */
    private function forgetOnHost(?string $host, array $targets, ?array $neighbours = null): int
    {
        $forgotten = 0;

        foreach ($this->matchOnHost($host, $targets, $neighbours) as $domain => $matched) {
            $this->invalidate(array_keys($matched), $domain);
            $forgotten += array_sum($matched);
        }

        return $forgotten;
    }

    /**
     * The cached paths on each of the host's domains that each site's target
     * names, each with how many cached pages it has (one per query string).
     *
     * @param  array<string, array{prefix: string, exact: array<string, true>, under: list<string>}>  $targets  keyed by site handle
     * @param  list<array{string, ?string, string}>|null  $neighbours
     * @return array<string, array<string, int>>
     */
    private function matchOnHost(?string $host, array $targets, ?array $neighbours = null): array
    {
        if (! $this->cacher instanceof AbstractCacher || $targets === []) {
            return [];
        }

        $neighbours ??= self::neighbours($host);
        $matches = [];

        foreach ($this->domains($host) as $domain) {
            $matched = [];

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                $target = $targets[self::owner($neighbours, (string) $url) ?? ''] ?? null;

                if ($target === null) {
                    continue;
                }

                $onSite = self::onSite((string) $url, $target['prefix']);

                if (isset($target['exact'][$onSite]) || self::under($onSite, $target['under'])) {
                    $path = strtok((string) $url, '?');
                    $matched[$path] = ($matched[$path] ?? 0) + 1;
                }
            }

            $matches[$domain] = $matched;
        }

        return $matches;
    }

    /** Has Statamic render a cached path again in the background, or forgets it while background re-caching is off. */
    private function renderAgain(string $path, ?string $domain): void
    {
        if (config('statamic.static_caching.background_recache')) {
            $this->cacher->refreshUrl($path, $domain);
        } else {
            $this->cacher->invalidateUrl($path, $domain);
        }
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

    /** Whether an absolute URL is on the site: on its host as onHost() takes it, and on a shared host under its path, as flush() decides. */
    public function owns(Site $site, string $url): bool
    {
        if (! self::onHost($site, $url)) {
            return false;
        }

        return self::owner(self::neighbours(self::host($site)), $url) === self::handle($site);
    }

    /**
     * Whether an absolute URL is on the site's host. A site with a relative
     * URL also takes the host Statamic made its URLs on (the request's), as
     * an invalidator reports them there: the page is still forgotten on the
     * site's host, and forgetting sends no request.
     */
    private static function onHost(Site $site, string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === self::host($site)) {
            return true;
        }

        return ! URL::isAbsolute($site->url()) && $host === strtolower((string) parse_url($site->absoluteUrl(), PHP_URL_HOST));
    }

    /** The handle of the site a cached page belongs to: the first site on its host, in placed() order, whose path the page lies under. */
    public static function siteOf(string $url): ?string
    {
        return self::owner(self::neighbours(strtolower((string) parse_url($url, PHP_URL_HOST)) ?: null, served: true), $url);
    }

    /**
     * The handle, host and path of each site on the host, in placed() order.
     *
     * @return list<array{string, ?string, string}>
     */
    private static function neighbours(?string $host, bool $served = false): array
    {
        return array_values(array_filter(self::placed($served), fn (array $placed) => $placed[1] === $host));
    }

    /**
     * The handle, host and path of every site, in the order Statamic's Sites::findByUrl() takes: a site whose URL
     * names its host first, then the longest path. Unlike findByUrl(), a site's scheme is not compared, so a site at an
     * http:// URL comes first for its host's https:// pages too. A relative URL is placed on APP_URL, as host()
     * places it, or with $served on the request's root, the host serving the page, as findByUrl() places it; resolved once,
     * as each site's absoluteUrl() would be most of what a cache hit costs. Kept while that root stays the same and
     * Sites::all() is the same collection, which setSites() replaces; a site changed in place is not seen.
     *
     * @return list<array{string, ?string, string}>
     */
    private static function placed(bool $served = false): array
    {
        $slot = $served ? 'served' : 'app';
        $key = $served ? url()->to('/') : (string) config('app.url');
        $sites = Sites::all();
        $memo = self::$placed[$slot] ?? null;

        if ($memo === null || $memo['key'] !== $key || $memo['sites'] !== $sites) {
            $base = $served ? URL::makeAbsolute('/') : $key;

            $placed = $sites
                ->map(fn (Site $site): array => [self::handle($site), ...self::locate($site->url(), $base), URL::isAbsolute($site->url())])
                ->sortByDesc(fn (array $placed) => [(int) $placed[3], strlen($placed[2])])
                ->map(fn (array $placed) => [$placed[0], $placed[1], $placed[2]])
                ->values()
                ->all();

            self::$placed[$slot] = ['key' => $key, 'sites' => $sites, 'placed' => $placed];
        }

        return self::$placed[$slot]['placed'];
    }

    /**
     * Whether another site on the host sits under this path ('/' beside a site at '/en').
     *
     * @param  list<array{string, ?string, string}>  $neighbours
     */
    private static function nestsOthers(array $neighbours, Site $site, string $under): bool
    {
        foreach ($neighbours as [$handle, , $prefix]) {
            if ($handle !== self::handle($site) && str_starts_with($prefix.'/', $under)) {
                return true;
            }
        }

        return false;
    }

    /** A site's handle as a string: a handle of digits (`2026:` in sites.yaml) is read as an int. */
    private static function handle(Site $site): string
    {
        return (string) $site->handle();
    }

    /**
     * The handle of the site a URL's path lies under.
     *
     * @param  list<array{string, ?string, string}>  $sites  the handle, host and path of each site on the URL's host, in placed() order
     */
    private static function owner(array $sites, string $url): ?string
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach ($sites as [$handle, , $prefix]) {
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

    /**
     * A site URL's host, lowercased, and the path it sits under ('/second', '' at the root), a relative URL on $base.
     *
     * @return array{?string, string}
     */
    private static function locate(string $url, string $base): array
    {
        $absolute = URL::isAbsolute($url) ? $url : rtrim($base, '/').$url;

        return [strtolower((string) parse_url($absolute, PHP_URL_HOST)) ?: null, rtrim((string) parse_url($absolute, PHP_URL_PATH), '/')];
    }

    /** As Statamic's cacher reads its base URL: a relative site is on APP_URL's host, never the one a request names. */
    private static function host(Site $site): ?string
    {
        return self::locate($site->url(), (string) config('app.url'))[0];
    }

    /** The path a site sits under on its host ('/second'), '' at the root, on APP_URL for a relative site as host() places it. */
    private static function prefix(Site $site): string
    {
        return self::locate($site->url(), (string) config('app.url'))[1];
    }
}
