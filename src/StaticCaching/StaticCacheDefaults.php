<?php

namespace Inly\CoreWeb\StaticCaching;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;

/**
 * How a save reaches the static cache, decided once for every site rather than per site's config: pages rendered
 * again in the background in production with a worker taking the renders, and a save under the `all` rule kept to
 * its site's pages. docs/static-cache.md has what each default does and how a site keeps its own.
 */
final class StaticCacheDefaults
{
    /** Queue drivers that run a job without a worker: inside the request that queues it, after it, or never. */
    public const WORKERLESS_DRIVERS = ['sync', 'null', 'deferred', 'background'];

    private const RECACHE_ENV = 'STATAMIC_BACKGROUND_RECACHE';

    /** Before Statamic reads it: background recache on where recachesHere(), unless the env or a config names it. */
    public static function applyRecache(Application $app): void
    {
        // A cached config already holds what was resolved for this server when it was cached.
        if ($app->configurationIsCached()) {
            return;
        }

        $config = $app['config'];

        // Named in the env (`false` included), the environment's choice stands.
        if (Env::getRepository()->get(self::RECACHE_ENV) !== null) {
            return;
        }

        $chosen = $config->get('core-web.static_cache.background_recache');

        if (is_bool($chosen)) {
            $config->set('statamic.static_caching.background_recache', $chosen);

            return;
        }

        // Statamic's own default is false, so only a site's true is a choice.
        if ($config->get('statamic.static_caching.background_recache')) {
            return;
        }

        $config->set('statamic.static_caching.background_recache', self::recachesHere($app));
    }

    /**
     * Production with a worker taking the renders. Each render is a request from the worker to the page's own
     * address, sending no credentials, so a site behind basic auth (a staging) would keep its old copy for good.
     */
    public static function recachesHere(Application $app): bool
    {
        return $app->environment('production') && self::warmsOnAWorker($app['config']);
    }

    /** SiteScopedInvalidator on the `all` rule, unless the site names a class. */
    public static function scopeInvalidation(Application $app): void
    {
        $config = $app['config'];

        if ($app->configurationIsCached() || ! self::scopesBySite($config)) {
            return;
        }

        $config->set('statamic.static_caching.invalidation.class', SiteScopedInvalidator::class);
    }

    /**
     * Whether SiteScopedInvalidator serves the install: the `all` rule and no class of its own. On one site too, since
     * Statamic's rule renders again only the pages cached under the static cache's base URL, which is none when that
     * URL has a path.
     */
    public static function scopesBySite(Repository $config): bool
    {
        return $config->get('statamic.static_caching.invalidation.class') === null
            && $config->get('statamic.static_caching.invalidation.rules') === 'all';
    }

    /** The queue connection Statamic sends a page's render to; an empty name is the default one, as Laravel reads it. */
    public static function warmConnection(Repository $config): string
    {
        return (string) ($config->get('statamic.static_caching.warm_queue_connection') ?: $config->get('queue.default'));
    }

    /** The connection a render's job lands on: the warm connection, or a failover connection's first one, which takes the jobs while it is up. */
    public static function workerConnection(Repository $config): string
    {
        $connection = self::warmConnection($config);

        if ($config->get("queue.connections.{$connection}.driver") !== 'failover') {
            return $connection;
        }

        $first = $config->get("queue.connections.{$connection}.connections")[0] ?? null;

        return is_string($first) ? $first : '';
    }

    /** The queue a worker takes the renders from: `warm_queue`, or the connection's default queue. */
    public static function warmQueue(Repository $config): string
    {
        return (string) ($config->get('statamic.static_caching.warm_queue')
            ?: $config->get('queue.connections.'.self::workerConnection($config).'.queue')
            ?: 'default');
    }

    /** Whether the renders' connection holds its jobs for a worker, rather than running them in the request or dropping them. */
    public static function warmsOnAWorker(Repository $config): bool
    {
        $driver = $config->get('queue.connections.'.self::workerConnection($config).'.driver');

        return is_string($driver) && ! in_array($driver, self::WORKERLESS_DRIVERS, true);
    }
}
