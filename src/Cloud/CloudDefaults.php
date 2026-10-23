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
 * environment sets keeps its own value, and a config cached here keeps what it resolved.
 */
final class CloudDefaults
{
    /** @var array<string, array{string, mixed}> config key => [env var that overrides it, value] */
    private const DEFAULTS = [
        'statamic.stache.watcher' => ['STATAMIC_STACHE_WATCHER', false],
        // A Stache built from git is the same in every container, so each keeps its own; on the database store every read is a query.
        'statamic.stache.cache_store' => ['STATAMIC_STACHE_CACHE_STORE', 'file'],
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

    /** The disk name Livewire's temporary uploads get on Cloud, on the private bucket. */
    public const UPLOADS_DISK = 'livewire-tmp';

    /** The driver of that disk: S3 under a name of its own, since on an `s3` disk Livewire uploads one file at a time through a signed URL. */
    public const UPLOADS_DRIVER = 'core-web-bucket';

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

        // A config cached on Cloud was resolved here with the build's env, which a request does not see: Cloud's env is in
        // .env, and Laravel skips .env once the config is cached, so reading it again would put back every default.
        $resolved = $app->configurationIsCached() && $config->get('core-web.cloud.resolved') === true;

        if (! $resolved) {
            foreach (self::DEFAULTS as $key => [$env, $value]) {
                self::set($config, $key, $env, $value);
            }
        }

        // Revisions need Pro, so a Solo site (STATAMIC_PRO_ENABLED=false) has them off unless its env says otherwise.
        if (! $resolved && Env::get('STATAMIC_REVISIONS_ENABLED') === null && ! $config->get('statamic.editions.pro')) {
            $config->set('statamic.revisions.enabled', false);
        }

        $config->set('core-web.cloud.resolved', true);

        // Statamic's licensing ping keeps its marker in an `outpost` store when one exists. The build runs artisan
        // before any migration made the cache table, so on the database store every build command would fail.
        if (! $config->has('cache.stores.outpost')) {
            $config->set('cache.stores.outpost', ['driver' => 'file', 'path' => storage_path('framework/cache/outpost')]);
        }

        // Statamic keeps the static cache's pages and nocache regions in this store when it exists. A file store is each
        // container's own, so a save would clear only one replica's copy; without it they share the default store.
        if ($config->get('cache.stores.static_cache.driver') === 'file') {
            $config->set('cache.stores', array_diff_key((array) $config->get('cache.stores'), ['static_cache' => true]));
        }

        // Glide's `true` and `hybrid` caches keep renditions in one container's public/img and their keys in a store: on
        // another replica or after a deploy those point at files that container lacks (a 404 on /img/, a 500 on CP
        // thumbnails). Uncached, every URL carries its signed source and each container renders its own copies, keyed in
        // its own file `glide` store (a shared one would point at copies another container holds). Glide's routes depend
        // on the mode, so routes are cached where Cloud is detected, as its build is.
        if (in_array($config->get('statamic.assets.image_manipulation.cache'), [true, 'hybrid'], true)) {
            $config->set('statamic.assets.image_manipulation.cache', false);
            // Presets made at upload would render inside the request into a disk the next deploy drops.
            $config->set('statamic.assets.image_manipulation.generate_presets_on_upload', false);
        }

        // The driver's models come from the site's published config/statamic/eloquent-driver.php; without it there is nothing to switch.
        if (! class_exists(EloquentDriver::class) || ! $config->has('statamic.eloquent-driver.entries.model')) {
            return;
        }

        // The driver binds its repositories in register(); set after, the site would keep reading the reset disk.
        if ($app->providerIsLoaded(EloquentDriver::class)) {
            throw new LogicException('core-web.features.cloud: statamic/eloquent-driver registered before core-web, so its repositories ignore the Cloud defaults. Register Inly\CoreWeb\CoreWebServiceProvider first.');
        }

        if ($resolved) {
            return;
        }

        foreach (self::CONTENT as $part) {
            self::set($config, "statamic.eloquent-driver.{$part}.driver", 'STATAMIC_CONTENT_DRIVER', 'eloquent');
        }

        self::set($config, 'statamic.users.repository', 'STATAMIC_USERS_REPOSITORY', 'eloquent');
    }

    /**
     * Peak's local disks, and a media disk the site keeps local for another host, become media disks on the bucket
     * before MediaDisks defines the disks: a file written to Cloud's disk is gone at the next deploy and seen by one
     * replica only. A disk the site points elsewhere is left alone.
     */
    public static function moveLocalDisksToBucket(Repository $config): void
    {
        $disks = (array) $config->get('filesystems.disks', []);
        $media = (array) $config->get('core-web.media.disks', []);

        foreach ([...self::PEAK_DISKS, ...array_keys($media)] as $disk) {
            if (($disks[$disk]['driver'] ?? null) !== 'local') {
                continue;
            }

            unset($disks[$disk]);
            $media[$disk] ??= $disk;
        }

        $config->set('filesystems.disks', $disks);
        $config->set('core-web.media.disks', $media);
    }

    /**
     * Livewire's temporary uploads wait in the private bucket: an upload lands on one replica and the submit that reads
     * it on another, and without a disk of their own they go to the default disk, on Cloud the public bucket. Livewire's
     * cleanup lists every waiting upload on each new one, so it is off; the bucket's lifecycle rule deletes them. Run
     * once every provider has registered, as Livewire merges its config shallowly. A disk the site chose is kept.
     */
    public static function keepUploadsInPrivateBucket(Repository $config): void
    {
        $private = $config->get('filesystems.disks.'.$config->get('core-web.cloud.uploads_disk', 'private'));
        $chosen = $config->get('livewire.temporary_file_upload.disk');

        if (! is_array($private) || ($private['driver'] ?? null) !== 's3' || ! in_array($chosen, [null, '', self::UPLOADS_DISK], true)) {
            return;
        }

        $config->set('filesystems.disks.'.self::UPLOADS_DISK, [...$private, 'driver' => self::UPLOADS_DRIVER]);
        $config->set('livewire.temporary_file_upload.disk', self::UPLOADS_DISK);
        $config->set('livewire.temporary_file_upload.cleanup', false);
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
