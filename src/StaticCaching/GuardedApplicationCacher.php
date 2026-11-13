<?php

namespace Inly\CoreWeb\StaticCaching;

use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Facades\Event;
use Statamic\StaticCaching\Cachers\ApplicationCacher;
use Statamic\StaticCaching\Page;
use Statamic\Support\Str;

/** Statamic's `half` cacher, serving a cached page only when its request started after the page was last cleared: docs/static-cache.md#pages-cached-while-a-clear-runs. */
class GuardedApplicationCacher extends ApplicationCacher
{
    /** Seconds after a clear in which a request still counts as started before it, as servers' clocks differ slightly. */
    public const CLOCK_MARGIN = 1.0;

    /** Minutes a path's clear outlives the pages' own expiry, for a render that started before it and was stored after. */
    private const RECORD_MARGIN = 60;

    /** Seconds a write to the URL list waits for its lock, as Statamic's own; none is written without it. */
    protected int $lockWait = 10;

    /** @var array{url: string, page: array<string, mixed>}|null */
    private ?array $found = null;

    public function hasCachedPage(Request $request)
    {
        $url = $this->getUrl($request);
        $page = $this->current($url);
        $this->found = $page === null ? null : ['url' => $url, 'page' => $page];

        return $page !== null;
    }

    public function getCachedPage(Request $request)
    {
        $url = $this->getUrl($request);
        $page = $this->found !== null && $this->found['url'] === $url ? $this->found['page'] : $this->current($url);

        return new Page($page['content'], $page['headers'], $page['status'] ?? 200);
    }

    public function cachePage(Request $request, $content)
    {
        $url = $this->getUrl($request);

        if ($this->isExcluded($url)) {
            return;
        }

        $key = $this->makeHash($url);
        $started = $this->startOf($request);

        if (! $this->listUrl($key, ...$this->getPathAndDomain($url))) {
            return;
        }

        $value = $this->normalizeContent($content);
        $handled = false;

        // Statamic's own store, with the start kept in the page's value: no other request's start can vouch for this copy.
        Event::listen(ResponsePrepared::class, function (ResponsePrepared $event) use ($key, $value, $started, &$handled) {
            if ($handled) {
                return;
            }

            $handled = true;

            $headers = collect($event->response->headers->all())
                ->reject(fn ($value, $key) => in_array($key, ['date', 'x-powered-by', 'cache-control', 'expires', 'set-cookie']))
                ->all();

            $values = [
                $this->normalizeKey('responses:'.$key) => [
                    'content' => $value,
                    'headers' => $headers,
                    'status' => $event->response->getStatusCode(),
                    'started' => $started,
                ],
            ];

            // Only a Statamic with getGeneratedAt() reads this key and forgets it with the page.
            if (method_exists(ApplicationCacher::class, 'getGeneratedAt')) {
                $values[$this->normalizeKey('generated-at:'.$key)] = now()->getTimestamp();
            }

            $this->cache->putMany($values, $this->pageExpiry());
        });
    }

    public function flush()
    {
        $domains = $this->getDomains()->all();

        parent::flush();

        $flushed = $this->recordFlush();

        foreach ($domains as $domain) {
            $this->recordUnder((string) $domain, [], $flushed);
        }
    }

    /** Every page cached before now is a miss. */
    public function recordFlush(): float
    {
        $this->cache->forever($this->normalizeKey('core-web:cleared'), $now = $this->now());

        return $now;
    }

    public function invalidateUrl($url, $domain = null)
    {
        if ($domain === null) {
            [$url, $domain] = $this->getPathAndDomain($url);
        }

        parent::invalidateUrl($url, $domain);

        $this->recordClears($domain, [$url]);
    }

    public function invalidateUrls($urls)
    {
        parent::invalidateUrls($urls);

        $clears = [];

        foreach (collect($urls) as $url) {
            $wildcard = Str::contains($url, '*');
            [$path, $domain] = $this->getPathAndDomain($wildcard ? substr($url, 0, -1) : $url);
            $clears[$domain][$wildcard ? 'prefixes' : 'paths'][] = $path;
        }

        foreach ($clears as $domain => $clear) {
            $this->recordClears($domain, $clear['paths'] ?? [], $clear['prefixes'] ?? []);
        }
    }

    /**
     * Records that the pages at these paths on the domain, with any query string, and the pages under these prefixes
     * were cleared now, whether they were on the URL list or not.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $prefixes
     */
    public function recordClears(string $domain, array $paths, array $prefixes = []): void
    {
        $now = $this->now();

        if ($paths !== []) {
            $records = [];

            foreach ($paths as $path) {
                $records[$this->clearedKey($domain, $path)] = $now;
            }

            $this->cache->putMany($records, $this->recordExpiry());
        }

        if ($prefixes !== []) {
            $this->recordUnder($domain, array_fill_keys($prefixes, $now), (float) $this->cache->get($this->normalizeKey('core-web:cleared'), 0));
        }
    }

    /** Every page the site owns (SiteCache::siteOf()) cached before now is a miss. */
    public function recordSiteClear(string $site): void
    {
        $this->cache->forever($this->siteKey($site), $this->now());
    }

    public function cacheUrl($key, $url, $domain = null)
    {
        $this->listUrl($key, $url, $domain);
    }

    public function forgetUrl($key, $domain = null)
    {
        $this->forgetUrls([$key], $domain);
    }

    /** Skipped when the lock times out: the entry left on the list names a page already forgotten. */
    public function forgetUrls($keys, $domain = null)
    {
        if (empty($keys)) {
            return;
        }

        $this->withUrlsLocked(fn () => $this->cache->forever(
            $this->getUrlsCacheKey($domain),
            $this->getUrls($domain)->except($keys)->all(),
        ));
    }

    /** The clock of every record, the same as a request's start (REQUEST_TIME_FLOAT). */
    protected function now(): float
    {
        return microtime(true);
    }

    /** Whether the URL is on the list, written only under its lock. */
    private function listUrl(string $key, string $url, ?string $domain): bool
    {
        $domain ??= $this->getBaseUrl();
        $url = Str::removeLeft($url, $domain);

        return $this->withUrlsLocked(function () use ($key, $url, $domain) {
            $this->cacheDomain($domain);
            $this->cache->forever($this->getUrlsCacheKey($domain), $this->getUrls($domain)->put($key, $url)->all());
        });
    }

    /**
     * Writes the domain's wildcard records, keeping only those no newer flush or newer, wider prefix covers.
     *
     * @param  array<string, float>  $cleared  prefix => when it was cleared
     */
    private function recordUnder(string $domain, array $cleared, float $flushed): void
    {
        $key = $this->underKey($domain);

        if ($cleared === [] && $this->cache->get($key) === null) {
            return;
        }

        $write = function () use ($key, $cleared, $flushed) {
            $under = (array) $this->cache->get($key, []);

            // The later clear wins: one that waited for the lock can be older than one written while it waited.
            foreach ($cleared as $prefix => $at) {
                $under[$prefix] = max((float) ($under[$prefix] ?? 0), $at);
            }

            foreach ($under as $prefix => $at) {
                $covered = (float) $at <= $flushed;

                foreach ($under as $wider => $later) {
                    $covered = $covered || ($wider !== $prefix && Str::startsWith((string) $prefix, (string) $wider) && $later >= $at);
                }

                if ($covered) {
                    unset($under[$prefix]);
                }
            }

            $under === [] ? $this->cache->forget($key) : $this->cache->forever($key, $under);
        };

        // Listed under the lock, the domain's records go with a flush of the whole cache. Without the lock a record is
        // still written, unlisted, as one left out would let the pages under it be served stale; a prune is skipped.
        $locked = $this->withUrlsLocked(function () use ($domain, $cleared, $write) {
            if ($cleared !== []) {
                $this->cacheDomain($domain);
            }

            $write();
        });

        if (! $locked && $cleared !== []) {
            $write();
        }
    }

    /**
     * The page cached at the URL, or null when there is none, or its request started before a clear that covers it
     * ended: of the whole cache, of the site it belongs to, of its path with any query string, or of a prefix its
     * path and query lie under.
     *
     * @return array<string, mixed>|null
     */
    private function current(string $url): ?array
    {
        [$path, $domain] = $this->getPathAndDomain($url);

        $keys = [
            'page' => $this->normalizeKey('responses:'.$this->makeHash($url)),
            'all' => $this->normalizeKey('core-web:cleared'),
            'path' => $this->clearedKey($domain, $path),
            'under' => $this->underKey($domain),
        ];

        if ($site = SiteCache::siteOf($url)) {
            $keys['site'] = $this->siteKey($site->handle());
        }

        $values = $this->cache->many(array_values($keys));
        $page = $values[$keys['page']] ?? null;

        // A page cached without its start (by Statamic's own cacher, before this one) cannot show when it was rendered.
        if (! is_array($page) || ! is_numeric($page['started'] ?? null)) {
            return null;
        }

        $cleared = (float) collect($keys)->only(['all', 'site', 'path'])->map(fn (string $key) => (float) ($values[$key] ?? 0))->max();

        foreach ((array) ($values[$keys['under']] ?? []) as $prefix => $at) {
            if (Str::startsWith($path, (string) $prefix)) {
                $cleared = max($cleared, (float) $at);
            }
        }

        return (float) $page['started'] >= $cleared + self::CLOCK_MARGIN ? $page : null;
    }

    /** When the request started; when the runtime gives no start, when the app booted, then now. */
    private function startOf(Request $request): float
    {
        $started = $request->server('REQUEST_TIME_FLOAT');

        if (is_numeric($started)) {
            return (float) $started;
        }

        return defined('LARAVEL_START') ? (float) LARAVEL_START : $this->now();
    }

    private function withUrlsLocked(callable $callback): bool
    {
        if (! ($store = $this->cache->getStore()) instanceof LockProvider) {
            $callback();

            return true;
        }

        try {
            $store->lock($this->normalizeKey('urls'), 10)->block($this->lockWait, $callback);

            return true;
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function pageExpiry(): ?DateTimeInterface
    {
        return $this->getDefaultExpiration() ? now()->addMinutes($this->getDefaultExpiration()) : null;
    }

    /** As long as a page started before the clear can be kept: stored after it, then kept for its expiry. */
    private function recordExpiry(): ?DateTimeInterface
    {
        return $this->getDefaultExpiration() ? now()->addMinutes($this->getDefaultExpiration() + self::RECORD_MARGIN) : null;
    }

    /** One record for a path and every query string on it, as invalidateUrl() forgets them all. */
    private function clearedKey(string $domain, string $path): string
    {
        return $this->normalizeKey('core-web:cleared:'.$this->makeHash($domain.(explode('?', $path, 2)[0] ?: '/')));
    }

    private function siteKey(string $site): string
    {
        return $this->normalizeKey('core-web:cleared-site:'.$site);
    }

    private function underKey(string $domain): string
    {
        return $this->normalizeKey('core-web:cleared-under:'.$this->makeHash($domain));
    }
}
