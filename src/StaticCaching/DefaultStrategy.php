<?php

namespace Inly\CoreWeb\StaticCaching;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;

/**
 * Static caching on for every site core-web runs on a server, Laravel Cloud or not, without an env line to forget:
 * `half` unless the app runs locally or under tests, where a cached page would hide the change being made.
 */
final class DefaultStrategy
{
    public const STRATEGY = 'half';

    /** The environments that keep the site's own strategy, usually none. */
    public const UNCACHED_ENVIRONMENTS = ['local', 'testing'];

    private const ENV = 'STATAMIC_STATIC_CACHING_STRATEGY';

    public static function apply(Application $app): void
    {
        $config = $app['config'];

        // A cached config already holds what was resolved for this server when it was cached.
        if ($app->configurationIsCached() || $app->environment(self::UNCACHED_ENVIRONMENTS)) {
            return;
        }

        // Named in the env (`null` included, which turns it off) or set in the site's config, the site's choice stands.
        if (Env::getRepository()->get(self::ENV) !== null || $config->get('statamic.static_caching.strategy') !== null) {
            return;
        }

        $config->set('statamic.static_caching.strategy', self::STRATEGY);
    }
}
