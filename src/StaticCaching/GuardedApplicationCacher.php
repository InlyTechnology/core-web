<?php

namespace Inly\CoreWeb\StaticCaching;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Statamic\StaticCaching\Cachers\ApplicationCacher;
use Statamic\StaticCaching\Page;
use Statamic\Support\Str;

/**
 * Statamic's `half` cacher, serving a cached page only when its request started after the page was last cleared.
 *
 * Statamic clears pages through its URL list, and a page cached while a clear runs can be stored without a place on
 * it, out of every later clear's reach. Each clear here records when it ended and each page when its request started.
 * See docs/static-cache.md#pages-cached-while-a-clear-runs.
 */
class GuardedApplicationCacher extends ApplicationCacher
{
    /** Seconds after a clear in which a request still counts as started before it, as servers' clocks differ slightly. */
    public const CLOCK_MARGIN = 1.0;

    /** Seconds a write to the URL list waits for its lock, as Statamic's own; none is written without it. */
    protected int $lockWait = 10;

    /** @var array{url: string, page: array<string, mixed>}|null */
    private ?array $found = null;

    /** The page key the cachePage() under way could not list. */
    private ?string $unlisted = null;

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
        $started = (float) ($request->server('REQUEST_TIME_FLOAT') ?: self::now());

        $this->unlisted = null;
        parent::cachePage($request, $content);
        $unlisted = $this->unlisted === $key;
        $this->unlisted = null;

        // After Statamic's own listener, which stores the page: a time written first could vouch for an older copy.
        $handled = false;

        Event::listen(ResponsePrepared::class, function () use ($key, $started, $unlisted, &$handled) {
            if ($handled) {
                return;
            }

            $handled = true;

            if ($unlisted) {
                $this->cache->forget($this->normalizeKey('responses:'.$key));

                return;
            }

            $this->getDefaultExpiration()
                ? $this->cache->put($this->startedKey($key), $started, now()->addMinutes($this->getDefaultExpiration()))
                : $this->cache->forever($this->startedKey($key), $started);
        });
    }

    public function flush()
    {
        parent::flush();

        $this->recordFlush();
    }

    /** Every page cached before now is a miss: for a clear of the whole store, which takes these records with it. */
    public function recordFlush(): void
    {
        $this->cache->forever($this->normalizeKey('core-web:cleared'), self::now());
    }

    public function invalidateUrl($url, $domain = null)
    {
        if ($domain === null) {
            [$url, $domain] = $this->getPathAndDomain($url);
        }

        parent::invalidateUrl($url, $domain);

        $this->cache->forever($this->clearedKey($domain, $url), self::now());
    }

    public function invalidateUrls($urls)
    {
        parent::invalidateUrls($urls);

        $now = self::now();
        $paths = [];
        $prefixes = [];

        foreach (collect($urls) as $url) {
            if (Str::contains($url, '*')) {
                [$prefix, $domain] = $this->getPathAndDomain(substr($url, 0, -1));
                $prefixes[$domain][$prefix] = $now;

                continue;
            }

            [$path, $domain] = $this->getPathAndDomain($url);
            $paths[$this->clearedKey($domain, $path)] = $now;
        }

        if ($paths !== []) {
            $this->cache->putMany($paths);
        }

        foreach ($prefixes as $domain => $cleared) {
            $key = $this->normalizeKey('core-web:cleared-under:'.$this->makeHash($domain));
            $write = fn () => $this->cache->forever($key, [...(array) $this->cache->get($key, []), ...$cleared]);

            // Unlocked rather than lost: a record left out would let the pages under it be served stale.
            $this->withUrlsLocked($write) || $write();
        }
    }

    public function cacheUrl($key, $url, $domain = null)
    {
        $domain ??= $this->getBaseUrl();
        $url = Str::removeLeft($url, $domain);

        $listed = $this->withUrlsLocked(function () use ($key, $url, $domain) {
            $this->cacheDomain($domain);
            $this->cache->forever($this->getUrlsCacheKey($domain), $this->getUrls($domain)->put($key, $url)->all());
        });

        if (! $listed) {
            $this->unlisted = $key;
        }
    }

    public function forgetUrl($key, $domain = null)
    {
        $this->forgetUrls([$key], $domain);
    }

    /** A list left as it was keeps a URL whose page is already gone, which costs one render. */
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

    /**
     * The page cached at the URL, or null when there is none, or its request started before a clear that covers it
     * ended: of the whole cache, of its path with any query string, or of a wildcard it lies under.
     *
     * @return array<string, mixed>|null
     */
    private function current(string $url): ?array
    {
        $key = $this->makeHash($url);
        [$path, $domain] = $this->getPathAndDomain($url);
        $path = self::withoutQuery($path);

        $keys = [
            'page' => $this->normalizeKey('responses:'.$key),
            'started' => $this->startedKey($key),
            'all' => $this->normalizeKey('core-web:cleared'),
            'path' => $this->clearedKey($domain, $path),
            'under' => $this->normalizeKey('core-web:cleared-under:'.$this->makeHash($domain)),
        ];

        $values = $this->cache->many(array_values($keys));
        $page = $values[$keys['page']] ?? null;
        $started = $values[$keys['started']] ?? null;

        // A page cached without its start (by Statamic's own cacher, before this one) cannot show when it was rendered.
        if (! is_array($page) || ! is_numeric($started)) {
            return null;
        }

        $cleared = max((float) ($values[$keys['all']] ?? 0), (float) ($values[$keys['path']] ?? 0));

        foreach ((array) ($values[$keys['under']] ?? []) as $prefix => $at) {
            if (Str::startsWith($path, (string) $prefix)) {
                $cleared = max($cleared, (float) $at);
            }
        }

        return (float) $started >= $cleared + self::CLOCK_MARGIN ? $page : null;
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

    private function startedKey(string $key): string
    {
        return $this->normalizeKey('core-web:started:'.$key);
    }

    /** One record for a path and every query string on it, as invalidateUrl() forgets them all. */
    private function clearedKey(string $domain, string $path): string
    {
        return $this->normalizeKey('core-web:cleared:'.$this->makeHash($domain.self::withoutQuery($path)));
    }

    private static function withoutQuery(string $path): string
    {
        return explode('?', $path, 2)[0] ?: '/';
    }

    private static function now(): float
    {
        return Date::now()->getPreciseTimestamp(6) / 1_000_000;
    }
}
