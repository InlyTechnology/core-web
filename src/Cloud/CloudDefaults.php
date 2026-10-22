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
        // Every Cloud site keeps revisions (stored with the content); a collection still opts in with `revisions: true`, and they need Pro.
        'statamic.revisions.enabled' => ['STATAMIC_REVISIONS_ENABLED', true],
        'statamic.editions.pro' => ['STATAMIC_PRO_ENABLED', true],
        // Statamic's every-minute job would wake a sleeping site each minute; core-web:scheduled-entries covers it on Cloud's wake-up interval.
        'statamic.system.handle_scheduled_entries' => ['STATAMIC_HANDLE_SCHEDULED_ENTRIES', false],
        'cache.default' => ['CACHE_STORE', 'database'],
        'session.driver' => ['SESSION_DRIVER', 'database'],
        'app.maintenance.driver' => ['APP_MAINTENANCE_DRIVER', 'cache'],
        'app.maintenance.store' => ['APP_MAINTENANCE_STORE', 'database'],
        'core-web.snapshots.store_driver' => ['CORE_SNAPSHOT_STORE', 'database'],
        // A token file is gone at the next deploy and seen by one replica only.
        'core-web.content_sync.tokens.driver' => ['CORE_SYNC_TOKENS_DRIVER', 'database'],
    ];

    /** What editors write, which the eloquent driver moves to the database; blueprints, collections and the rest stay files from git. */
    public const CONTENT = [
        'entries', 'collection_trees', 'navigation_trees', 'global_set_variables',
        'terms', 'assets', 'form_submissions', 'revisions', 'tokens',
    ];

    /** Disks Peak's addons write favicons and generated social images to: they hardcode the container handles, not a local path. */
    public const PEAK_DISKS = ['favicons', 'social_images'];

    /** Whether this process runs on Laravel Cloud (core-web.cloud.detected forces an answer, for tests). */
    public static function onCloud(): bool
    {
        $forced = config('core-web.cloud.detected');

        if ($forced !== null) {
            return filter_var($forced, FILTER_VALIDATE_BOOLEAN);
        }

        return in_array(Env::get('LARAVEL_CLOUD'), ['1', true], true);
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

        // Glide's `true` and `hybrid` caches keep renditions in one container's public/img and their keys in a store: on
        // another replica or after a deploy those point at files that container lacks (a 404 on /img/, a 500 on CP
        // thumbnails). Uncached, every URL carries its signed source and each container renders its own copies, keyed in
        // its own file `glide` store (a shared one would point at copies another container holds). Glide's routes depend
        // on the mode, so routes are cached where Cloud is detected, as its build is.
        if (in_array($config->get('statamic.assets.image_manipulation.cache'), [true, 'hybrid'], true)) {
            $config->set('statamic.assets.image_manipulation.cache', false);
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

    /**
     * Peak's local disks become media disks on the bucket, before MediaDisks defines the disks: a file written to Cloud's
     * disk is gone at the next deploy and seen by one replica only. A disk the site points elsewhere is left alone.
     */
    public static function movePeakDisksToBucket(Repository $config): void
    {
        $disks = (array) $config->get('filesystems.disks', []);
        $media = (array) $config->get('core-web.media.disks', []);

        foreach (self::PEAK_DISKS as $disk) {
            if (($disks[$disk]['driver'] ?? null) !== 'local') {
                continue;
            }

            unset($disks[$disk]);
            $media[$disk] ??= $disk;
        }

        $config->set('filesystems.disks', $disks);
        $config->set('core-web.media.disks', $media);
    }

    /** Whether content and users live in the database, as nothing on Cloud's disk outlives a deploy. */
    public static function contentInDatabase(): bool
    {
        return config('statamic.eloquent-driver.entries.driver') === 'eloquent'
            && config('statamic.users.repository') === 'eloquent';
    }

    private static function set(Repository $config, string $key, string $env, mixed $value): void
    {
        // The env value itself, not a skip: the published configs hardcode these keys rather than read the env var.
        $config->set($key, Env::get($env, $value));
    }
}
