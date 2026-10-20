<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use LogicException;
use Statamic\Eloquent\ServiceProvider as EloquentDriver;

/**
 * The config a Statamic site needs on Laravel Cloud, whose disk every deploy resets,
 * set at register so a cached config cannot hold the local values. An env var the
 * environment sets keeps its own value.
 */
final class CloudDefaults
{
    /** @var array<string, array{string, mixed}> config key => [env var that overrides it, value] */
    private const DEFAULTS = [
        'statamic.stache.watcher' => ['STATAMIC_STACHE_WATCHER', false],
        'statamic.static_caching.strategy' => ['STATAMIC_STATIC_CACHING_STRATEGY', 'half'],
        // Content lives in the database on Cloud; a CP save has nothing to commit.
        'statamic.git.enabled' => ['STATAMIC_GIT_ENABLED', false],
        'cache.default' => ['CACHE_STORE', 'database'],
        'session.driver' => ['SESSION_DRIVER', 'database'],
        'app.maintenance.driver' => ['APP_MAINTENANCE_DRIVER', 'cache'],
        'app.maintenance.store' => ['APP_MAINTENANCE_STORE', 'database'],
        'core-web.snapshots.store_driver' => ['CORE_SNAPSHOT_STORE', 'database'],
    ];

    /** What editors write, which the eloquent driver moves to the database; blueprints, collections and the rest stay files from git. */
    public const CONTENT = [
        'entries', 'collection_trees', 'navigation_trees', 'global_set_variables',
        'terms', 'assets', 'form_submissions', 'revisions', 'tokens',
    ];

    /** Whether this process runs on Laravel Cloud (core-web.cloud.detected forces an answer, for tests). */
    public static function onCloud(): bool
    {
        $forced = config('core-web.cloud.detected');

        if ($forced !== null) {
            return filter_var($forced, FILTER_VALIDATE_BOOLEAN);
        }

        return ($_ENV['LARAVEL_CLOUD'] ?? $_SERVER['LARAVEL_CLOUD'] ?? null) === '1';
    }

    public static function apply(Application $app): void
    {
        if (! self::onCloud()) {
            return;
        }

        $config = $app['config'];

        foreach (self::DEFAULTS as $key => [$env, $value]) {
            self::set($config, $key, $env, $value);
        }

        // Statamic's licensing ping keeps its marker in an `outpost` store when one exists. The build runs artisan
        // before any migration made the cache table, so on the database store every build command would fail.
        if (! $config->has('cache.stores.outpost')) {
            $config->set('cache.stores.outpost', ['driver' => 'file', 'path' => storage_path('framework/cache/outpost')]);
        }

        // The driver's models come from the site's published config/statamic/eloquent-driver.php; without it there is nothing to switch.
        if (! class_exists(EloquentDriver::class) || ! $config->has('statamic.eloquent-driver.entries.model')) {
            return;
        }

        // The driver binds its repositories in register(); set after, the site would keep reading the reset disk.
        if ($app->providerIsLoaded(EloquentDriver::class)) {
            throw new LogicException('core-web.features.cloud: statamic/eloquent-driver registered before core-web, so its repositories ignore the Cloud defaults. Register Inly\CoreWeb\CoreWebServiceProvider first.');
        }

        foreach (self::CONTENT as $part) {
            self::set($config, "statamic.eloquent-driver.{$part}.driver", 'STATAMIC_CONTENT_DRIVER', 'eloquent');
        }

        self::set($config, 'statamic.users.repository', 'STATAMIC_USERS_REPOSITORY', 'eloquent');
    }

    /** Whether content and users live in the database, as nothing on Cloud's disk outlives a deploy. */
    public static function contentInDatabase(): bool
    {
        return config('statamic.eloquent-driver.entries.driver') === 'eloquent'
            && config('statamic.users.repository') === 'eloquent';
    }

    private static function set(Repository $config, string $key, string $env, mixed $value): void
    {
        if (Env::get($env) === null) {
            $config->set($key, $value);
        }
    }
}
