<?php

namespace Inly\CoreWeb\StaticCaching;

use Closure;
use Illuminate\Support\Collection;
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
    /** @var array{root: string, sites: Collection<array-key, Site>, placed: list<array{site: Site, host: string|false|null, prefix: string}>}|null */
    private static ?array $placed = null;

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
        $neighbours = self::neighbours($site);

        foreach ($this->cacher->getDomains() as $domain) {
            if (parse_url((string) $domain, PHP_URL_HOST) !== $host) {
                continue;
            }

            $paths = [];

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                if (self::owner($neighbours, (string) $url)?->handle() === $site->handle()) {
                    $paths[strtok((string) $url, '?')] = true;
                }
            }

            $this->invalidate(array_keys($paths), $domain);
        }

        $this->recordSite($site);
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

        $host = parse_url($site->absoluteUrl(), PHP_URL_HOST);
        $neighbours = self::neighbours($site);
        $queued = 0;

        foreach ($this->cacher->getDomains() as $domain) {
            if (parse_url((string) $domain, PHP_URL_HOST) !== $host) {
                continue;
            }

            $paths = [];

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                if (self::owner($neighbours, (string) $url)?->handle() === $site->handle()) {
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
     * Forgets the site's cached pages at these absolute URLs (what an
     * invalidator reports; a trailing `*` takes every page under one), as
     * flushPaths() does: under http and https, with any query string, and
     * only the pages the site owns. Returns how many were forgotten.
     *
     * @param  iterable<mixed>  $urls
     */
    public function forget(Site $site, iterable $urls): int
    {
        $prefix = self::prefix($site);
        $paths = [];

        foreach ($urls as $url) {
            // An unrouted entry reports a null URL: it has no page to forget.
            if (! is_string($url)) {
                continue;
            }

            $wildcard = str_ends_with($url, '*');
            $address = $wildcard ? substr($url, 0, -1) : $url;

            if (! $this->owns($site, $address)) {
                continue;
            }

            $path = '/'.trim(substr('/'.trim((string) parse_url($address, PHP_URL_PATH), '/'), strlen($prefix)), '/');
            $paths[] = $wildcard ? rtrim($path, '/').'/*' : $path;
        }

        return $paths === [] ? 0 : $this->flushPaths($site, $paths);
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
        $neighbours = self::neighbours($site);
        $forgotten = 0;

        foreach ($this->cacher->getDomains() as $domain) {
            if (parse_url((string) $domain, PHP_URL_HOST) !== $host) {
                continue;
            }

            $matched = [];

            foreach ($this->cacher->getUrls($domain)->all() as $url) {
                if (self::owner($neighbours, (string) $url)?->handle() !== $site->handle()) {
                    continue;
                }

                $onSite = '/'.trim(substr('/'.trim((string) parse_url((string) $url, PHP_URL_PATH), '/'), strlen($prefix)), '/');

                if (isset($exact[$onSite]) || self::under($onSite, $prefixes)) {
                    $matched[strtok((string) $url, '?')] = true;
                    $forgotten++;
                }
            }

            $this->invalidate(array_keys($matched), $domain);
        }

        $named = [];

        foreach (array_keys($exact) as $path) {
            $onHost = $prefix.($path === '/' && $prefix !== '' ? '' : $path);

            if (self::owner($neighbours, $onHost)?->handle() === $site->handle()) {
                $named[$onHost] = true;
                $named[rtrim($onHost, '/').'/'] = true;
            }
        }

        $under = [];
        $whole = false;

        foreach ($prefixes as $onSite) {
            $onHost = $prefix.$onSite;

            if (self::owner($neighbours, $onHost)?->handle() !== $site->handle()) {
                continue;
            }

            // A prefix with another site under it cannot be named without that site's pages: the whole site is.
            if (self::nestsOthers($neighbours, $site, $onHost)) {
                $whole = true;
            } else {
                $under[] = $onHost;
            }
        }

        $this->record($site, array_keys($named), $under);

        if ($whole) {
            $this->recordSite($site);
        }

        return $forgotten;
    }

    /**
     * Records the clear for the guarded cacher on the site's host, under http and https, whether its pages were
     * listed or not: docs/static-cache.md#pages-cached-while-a-clear-runs.
     *
     * @param  list<string>  $paths  on the host, each with any query string
     * @param  list<string>  $prefixes  on the host, for the pages under each
     */
    private function record(Site $site, array $paths, array $prefixes): void
    {
        if (! $this->cacher instanceof GuardedApplicationCacher) {
            return;
        }

        $host = parse_url($site->absoluteUrl(), PHP_URL_HOST);

        foreach (['http', 'https'] as $scheme) {
            $this->cacher->recordClears("{$scheme}://{$host}", $paths, $prefixes);
        }
    }

    /** Records the clear of every page the site owns for the guarded cacher: docs/static-cache.md#pages-cached-while-a-clear-runs. */
    private function recordSite(Site $site): void
    {
        if ($this->cacher instanceof GuardedApplicationCacher) {
            $this->cacher->recordSiteClear($site->handle());
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
        if (parse_url($url, PHP_URL_HOST) !== parse_url($site->absoluteUrl(), PHP_URL_HOST)) {
            return false;
        }

        return self::owner(self::neighbours($site), $url)?->handle() === $site->handle();
    }

    /** The site a cached page belongs to: the one with the longest path on the page's host that the page lies under. */
    public static function siteOf(string $url): ?Site
    {
        return self::owner(self::sitesOn(parse_url($url, PHP_URL_HOST)), $url);
    }

    /**
     * The sites on the site's host, longest path first.
     *
     * @return list<array{site: Site, host: string|false|null, prefix: string}>
     */
    private static function neighbours(Site $site): array
    {
        return self::sitesOn(parse_url($site->absoluteUrl(), PHP_URL_HOST));
    }

    /** @return list<array{site: Site, host: string|false|null, prefix: string}> the sites on the host, longest path first */
    private static function sitesOn(string|false|null $host): array
    {
        return array_values(array_filter(self::placed(), fn (array $placed) => $placed['host'] === $host));
    }

    /**
     * Every site with its host and path, longest path first, and of two at one address the one whose URL names its
     * host. Kept while the sites and the request's root stay the same, as each site's absoluteUrl() is most of what a
     * cache hit costs otherwise.
     *
     * @return list<array{site: Site, host: string|false|null, prefix: string}>
     */
    private static function placed(): array
    {
        $root = url()->to('/');
        $sites = Sites::all();

        if (self::$placed === null || self::$placed['root'] !== $root || self::$placed['sites'] !== $sites) {
            $placed = $sites
                ->map(function (Site $site): array {
                    $url = $site->absoluteUrl();

                    return [
                        'site' => $site,
                        'host' => parse_url($url, PHP_URL_HOST),
                        'prefix' => rtrim((string) parse_url($url, PHP_URL_PATH), '/'),
                        'named' => str_contains($site->url(), '://'),
                    ];
                })
                ->sortByDesc(fn (array $placed) => [strlen($placed['prefix']), (int) $placed['named']])
                ->map(fn (array $placed) => ['site' => $placed['site'], 'host' => $placed['host'], 'prefix' => $placed['prefix']])
                ->values()
                ->all();

            self::$placed = ['root' => $root, 'sites' => $sites, 'placed' => $placed];
        }

        return self::$placed['placed'];
    }

    /**
     * Whether another site on the host sits under this path ('/' beside a site at '/en').
     *
     * @param  list<array{site: Site, host: string|false|null, prefix: string}>  $neighbours
     */
    private static function nestsOthers(array $neighbours, Site $site, string $under): bool
    {
        foreach ($neighbours as $other) {
            if ($other['site']->handle() !== $site->handle() && str_starts_with($other['prefix'].'/', $under)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array{site: Site, host: string|false|null, prefix: string}>  $sites  the sites on the URL's host, longest path first */
    private static function owner(array $sites, string $url): ?Site
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach ($sites as $placed) {
            $prefix = $placed['prefix'];

            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $placed['site'];
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
