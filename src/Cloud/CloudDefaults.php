<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\QueueRoutes;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Env;
use Livewire\Livewire;
use LogicException;
use Statamic\Eloquent\ServiceProvider as EloquentDriver;
use Statamic\Search\UpdateItemIndexes;

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

    /**
     * The driver of that disk: S3 under a name of its own, since on an `s3` disk Livewire (3.6 to 4.4) refuses a
     * `multiple` field and skips its own cleanup of waiting uploads.
     */
    public const UPLOADS_DRIVER = 'core-web-bucket';

    /** Marks the static cache store this class wrote, so it is written again for each build. */
    private const STATIC_CACHE_MARKER = 'core_web_release';

    /** The Redis connection the static cache's pages go through on a Redis default store: the cache's own, compressing. */
    public const STATIC_CACHE_CONNECTION = 'core_web_static_cache';

    /** The queue Statamic renders a page again on, where neither the env nor the site's config names one. */
    public const WARM_QUEUE = 'low';

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

            // A save queues a render for each page it reaches, which on the default queue would hold up the site's other jobs (a ping's refresh, form mail).
            if (Env::getRepository()->get('STATAMIC_STATIC_WARM_QUEUE') === null && blank($config->get('statamic.static_caching.warm_queue'))) {
                $config->set('statamic.static_caching.warm_queue', self::WARM_QUEUE);
            }

            // Statamic queues every index build. On a worker a local index is written to the worker's disk, which no web
            // replica reads, so each search queues another build and then finds no index. Built in the request, it is
            // written where it is read.
            if (blank(Env::get('STATAMIC_SEARCH_QUEUE_CONNECTION')) && blank($config->get('statamic.search.queue_connection')) && self::localSearchIndexes($config) !== []) {
                $config->set('statamic.search.queue_connection', 'sync');
            }
        }

        // A save updates the indexes through a queued listener, which on a worker updates the worker's copy, or rebuilds it
        // whole in one job. While builds run in the request, the save updates the copy of the container it was made on.
        if ($config->get('statamic.search.queue_connection') === 'sync' && class_exists(QueueRoutes::class)) {
            $app->extend('queue.routes', function (QueueRoutes $routes): QueueRoutes {
                $routes->set(UpdateItemIndexes::class, connection: 'sync');

                return $routes;
            });
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
        // container's own, so a save would clear only one replica's copy; on the shared database store they are one copy.
        self::shareStaticCache($config);

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
     * The static cache on the shared default store, under a prefix per build: the deploy clears it while the old release
     * still serves, and a page cached then would point new containers at assets their build does not have.
     *
     * On the database store the pages share the cache table. On Redis (a Cloud cache cluster) they go through a copy of
     * the cache's connection that compresses: a page is hundreds of KB of HTML, a few hundred of them fill a small
     * instance, and HTML compresses several times over. Only that connection compresses, since Laravel's counters (rate
     * limits) INCRBY values a compressing client would have stored compressed; its locks stay on the plain connection.
     * Without a store of either kind, or without compression in phpredis, the pages sit in the default store.
     */
    private static function shareStaticCache(Repository $config): void
    {
        $store = $config->get('cache.stores.static_cache');

        if (is_array($store) && ($store['driver'] ?? null) !== 'file' && ! ($store[self::STATIC_CACHE_MARKER] ?? false)) {
            return;
        }

        $default = $config->get('cache.stores.'.$config->get('cache.default'));
        $release = self::release();
        $shared = match (is_array($default) && $release !== null ? ($default['driver'] ?? null) : null) {
            'database' => $default,
            'redis' => self::compressingRedisStore($config, $default),
            default => null,
        };

        if ($shared === null) {
            $config->set('cache.stores', array_diff_key((array) $config->get('cache.stores'), ['static_cache' => true]));

            return;
        }

        $config->set('cache.stores.static_cache', [
            ...$shared,
            'prefix' => ($default['prefix'] ?? $config->get('cache.prefix')).'static_'.$release.'_',
            self::STATIC_CACHE_MARKER => true,
        ]);
    }

    /**
     * A Redis store like the default one, on a compressing copy of its connection, or null when phpredis cannot
     * compress (no zstd, lz4 or lzf built in, or the predis client) or the connection is not a plain one.
     *
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>|null
     */
    private static function compressingRedisStore(Repository $config, array $default): ?array
    {
        $name = (string) ($default['connection'] ?? 'default');
        $connection = $config->get('database.redis.'.$name);
        $compression = self::redisCompression();

        if (! is_array($connection) || $compression === null || $config->get('database.redis.client', 'phpredis') !== 'phpredis') {
            return null;
        }

        // The static cache flushes its whole store (the deploy, the CP's Cache utility): FLUSHDB on that database. A
        // database of its own is set over the connection's URL expanded, as a URL's database wins over the key.
        $database = (string) Env::get('CORE_STATIC_CACHE_REDIS_DB', '');

        if ($database !== '') {
            $connection = [...(new ConfigurationUrlParser)->parseConfiguration($connection), 'database' => $database];
        }

        $config->set('database.redis.'.self::STATIC_CACHE_CONNECTION, [
            ...$connection,
            'options' => [...(array) ($connection['options'] ?? []), 'compression' => $compression],
        ]);

        return [
            ...$default,
            'connection' => self::STATIC_CACHE_CONNECTION,
            'lock_connection' => $default['lock_connection'] ?? $name,
        ];
    }

    /** The best compression this phpredis was built with: zstd, else lz4, else lzf. */
    private static function redisCompression(): ?int
    {
        foreach (['Redis::COMPRESSION_ZSTD', 'Redis::COMPRESSION_LZ4', 'Redis::COMPRESSION_LZF'] as $constant) {
            if (defined($constant)) {
                return constant($constant);
            }
        }

        return null;
    }

    /** The build this container runs, from its Vite manifests: the same in every container of one deploy. */
    private static function release(): ?string
    {
        $manifests = glob(public_path('*/manifest.json')) ?: [];

        if ($manifests === []) {
            return null;
        }

        return substr(md5(implode('|', array_map(fn (string $file): string => $file.':'.filesize($file).':'.filemtime($file), $manifests))), 0, 12);
    }

    /**
     * Livewire's temporary uploads wait in the private bucket: an upload lands on one replica and the submit that reads
     * it on another, and without a disk of their own they go to the default disk, on Cloud the public bucket. Run once
     * every provider has registered, as Livewire merges its config shallowly. A disk the site chose is kept.
     */
    public static function keepUploadsInPrivateBucket(Repository $config): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        $private = $config->get('filesystems.disks.'.$config->get('core-web.cloud.uploads_disk', 'private'));
        $chosen = $config->get('livewire.temporary_file_upload.disk');

        if (! is_array($private) || ($private['driver'] ?? null) !== 's3' || ! in_array($chosen, [null, '', self::UPLOADS_DISK], true)) {
            return;
        }

        $config->set('filesystems.disks.'.self::UPLOADS_DISK, [...$private, 'driver' => self::UPLOADS_DRIVER]);
        $config->set('livewire.temporary_file_upload.disk', self::UPLOADS_DISK);
    }

    /**
     * Draft proposals are kept in the private bucket: on the default disk, the public bucket on Cloud, anyone could
     * read a staged change by its path. A disk the site chose is kept.
     */
    public static function keepProposalsInPrivateBucket(Repository $config): void
    {
        $name = (string) $config->get('core-web.cloud.uploads_disk', 'private');
        $private = $config->get('filesystems.disks.'.$name);

        if (! is_array($private) || ($private['driver'] ?? null) !== 's3' || ! blank($config->get('core-web.drafts.proposals.disk'))) {
            return;
        }

        $config->set('core-web.drafts.proposals.disk', $name);
    }

    /**
     * The driver of each search index, Statamic's own `cp` index included while the config names none.
     *
     * @return array<string, string>
     */
    public static function searchIndexDrivers(Repository $config): array
    {
        $indexes = array_filter((array) $config->get('statamic.search.indexes', []), is_array(...)) + ['cp' => ['driver' => 'local']];

        return array_map(fn (array $index): string => (string) ($index['driver'] ?? 'local'), $indexes);
    }

    /**
     * The search indexes kept in a file on the container's disk.
     *
     * @return list<string>
     */
    public static function localSearchIndexes(Repository $config): array
    {
        return array_keys(array_filter(self::searchIndexDrivers($config), fn (string $driver): bool => $driver === 'local'));
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
