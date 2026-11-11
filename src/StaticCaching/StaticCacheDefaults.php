<?php

namespace Inly\CoreWeb\StaticCaching;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use Statamic\Facades\Site;

/**
 * How a save reaches the static cache, decided once for every site rather than per site's config: pages rendered
 * again in the background in production with a worker taking the renders, and each site's saves kept to its own
 * pages on a multisite install. docs/static-cache.md has what each default does and how a site keeps its own.
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

    /** Once the sites are known: SiteScopedInvalidator for a multisite install on the `all` rule, unless the site names a class. */
    public static function scopeInvalidation(Application $app): void
    {
        $config = $app['config'];

        if ($app->configurationIsCached() || ! self::scopesBySite($config)) {
            return;
        }

        $config->set('statamic.static_caching.invalidation.class', SiteScopedInvalidator::class);
    }

    /** Whether the install is one SiteScopedInvalidator serves with Statamic's rule unchanged: several sites, `all`. */
    public static function scopesBySite(Repository $config): bool
    {
        return $config->get('statamic.static_caching.invalidation.class') === null
            && $config->get('statamic.static_caching.invalidation.rules') === 'all'
            && Site::all()->count() > 1;
    }

    /** The queue connection Statamic sends a page's render to. */
    public static function warmConnection(Repository $config): string
    {
        return (string) ($config->get('statamic.static_caching.warm_queue_connection') ?? $config->get('queue.default'));
    }

    /** Whether the warm connection holds its jobs for a worker, rather than running them in the request or dropping them. */
    public static function warmsOnAWorker(Repository $config): bool
    {
        $driver = $config->get('queue.connections.'.self::warmConnection($config).'.driver');

        return is_string($driver) && ! in_array($driver, self::WORKERLESS_DRIVERS, true);
    }
}
