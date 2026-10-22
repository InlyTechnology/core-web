<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\CoreWebServiceProvider;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use Statamic\Contracts\Assets\Asset;
use Throwable;

/**
 * The asset disks in core-web.media.disks: the bucket in production, the
 * bucket behind a layer elsewhere (LayeredAdapter), the local folder when
 * the environment has no key, and on a checkout that pulls content, the
 * committed folder over the live site's pulled index, behind a local layer.
 * A disk the app defines itself is left alone.
 */
final class MediaDisks
{
    public const LAYERED_DRIVER = 'core-web-layered';

    public const REMOTE_INDEX_DRIVER = 'core-web-remote-index';

    /** @var list<string> */
    private static array $defined = [];

    public static function define(Application $app): void
    {
        $config = $app['config'];

        // A cached config already holds the disks defined here, so it carries the list too.
        if ($app->configurationIsCached()) {
            self::$defined = array_values((array) $config->get('core-web.media.defined_disks', []));

            return;
        }

        self::$defined = [];

        foreach ((array) $config->get('core-web.media.disks', []) as $disk => $folder) {
            $folder = trim((string) $folder, '/');

            // A blank folder would make the bucket's root production's disk, with the staging layer inside it.
            if ($folder === '') {
                throw new InvalidArgumentException("core-web.media.disks: [{$disk}] needs a folder.");
            }

            if (! $config->has("filesystems.disks.{$disk}")) {
                $config->set("filesystems.disks.{$disk}", self::disk((string) $disk, $folder, $app));
                self::$defined[] = (string) $disk;
            }
        }

        $config->set('core-web.media.defined_disks', self::$defined);
    }

    /** Whether core-web defined the disk, rather than the app's own config/filesystems.php. */
    public static function defines(string $disk): bool
    {
        return in_array($disk, self::$defined, true);
    }

    public static function registerDriver(): void
    {
        Storage::extend(self::LAYERED_DRIVER, function (Application $app, array $config): FilesystemAdapter {
            $upper = self::build($config['upper']);
            $lower = self::build($config['lower']);

            $adapter = new LayeredAdapter(
                $upper->getAdapter(),
                $lower->getAdapter(),
                fn (string $path): string => $upper->url($path),
                fn (string $path): string => $lower->url($path),
                $app['cache']->store(),
                'core-web.media.layer.'.$config['name'],
                containerUrl: $config['container_url'] ?? null,
            );

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });

        Storage::extend(self::REMOTE_INDEX_DRIVER, function (Application $app, array $config): FilesystemAdapter {
            $adapter = new RemoteIndexAdapter(
                RemoteIndex::forDisk($config['name']),
                (string) ($config['url'] ?? ''),
                max(1, (int) $app['config']->get('core-web.content_sync.timeouts.connect', 5)),
                max(1, (int) $app['config']->get('core-web.content_sync.timeouts.request', 30)),
            );

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }

    /**
     * A layer's disk; a lazy local root is built here, since Laravel 12's local driver creates its root eagerly.
     *
     * @param  array<string, mixed>  $config
     */
    private static function build(array $config): FilesystemAdapter
    {
        if (($config['driver'] ?? null) !== 'local' || ! ($config['lazy_root_creation'] ?? false)) {
            return Storage::build($config);
        }

        $adapter = new LocalAdapter(
            $config['root'],
            PortableVisibilityConverter::fromArray($config['permissions'] ?? [], $config['directory_visibility'] ?? $config['visibility'] ?? Visibility::PRIVATE),
            LOCK_EX,
            LocalAdapter::DISALLOW_LINKS,
            lazyRootCreation: true,
        );

        return new LocalFilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    }

    /**
     * Whether a disk without the bucket's key reads the live site's pulled
     * asset index here: on a local checkout pulling content from a live site.
     */
    public static function readsRemoteIndex(Application $app): bool
    {
        return $app->environment('local')
            && ! self::bucketConfigured()
            && CoreWebServiceProvider::enabled('content_sync')
            && filter_var(config('core-web.content_sync.pull_assets', true), FILTER_VALIDATE_BOOLEAN)
            && RemoteSite::configuredUrl() !== '';
    }

    /** Whether the disk is core-web's layer over the live site's pulled index, the one an asset pull fills. */
    public static function isRemoteIndexed(string $disk): bool
    {
        return self::defines($disk) && config("filesystems.disks.{$disk}.remote_index") === true;
    }

    /** The local route a remote-indexed disk's files resolve through (MediaFileController), as a path from the site's root. */
    public static function fileRoute(string $disk): string
    {
        return '/'.trim((string) config('statamic.routes.action', '!'), '/').'/core-web/media/'.$disk;
    }

    /**
     * Whether this server keeps production's files behind a layer. Unset, it is on except on a Laravel Cloud bucket,
     * which Cloud attaches per environment; a server set up before go-live turns it off.
     */
    public static function layered(): bool
    {
        $enabled = config('core-web.media.layer.enabled');

        if ($enabled === null || $enabled === '') {
            return ! self::fromCloud();
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether this environment has the bucket's key. */
    public static function bucketConfigured(): bool
    {
        $bucket = self::settings();

        return filled($bucket['key'] ?? null) && filled($bucket['secret'] ?? null);
    }

    /** Whether the bucket's key comes from Laravel Cloud's attached bucket rather than config. */
    public static function fromCloud(): bool
    {
        return blank(config('core-web.media.bucket.key')) && self::cloudDisk() !== null;
    }

    /**
     * The bucket's settings: config, or with no key there, Laravel Cloud's attached bucket. A Cloud bucket is R2,
     * which refuses object ACLs, and serves its files from its own URL unless the site commits a CDN one.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $bucket = (array) config('core-web.media.bucket', []);
        $cloud = blank($bucket['key'] ?? null) ? self::cloudDisk() : null;

        if ($cloud === null) {
            return $bucket;
        }

        return array_merge($bucket, [
            'key' => (string) ($cloud['access_key_id'] ?? ''),
            'secret' => (string) ($cloud['access_key_secret'] ?? ''),
            'bucket' => (string) ($cloud['bucket'] ?? ''),
            'endpoint' => (string) ($cloud['endpoint'] ?? ''),
            'region' => ($cloud['region'] ?? '') ?: 'auto',
            'url' => self::cloudUrl($bucket, $cloud),
            'acl' => false,
        ]);
    }

    /**
     * The files' base URL on a Cloud bucket: the committed one, else the CDN Core Cloud puts in front of its public
     * bucket when that is the bucket in use, else the bucket's own, which serves files untransformed.
     *
     * @param  array<string, mixed>  $bucket
     * @param  array<string, mixed>  $cloud
     */
    private static function cloudUrl(array $bucket, array $cloud): string
    {
        if (filled($bucket['url'] ?? null)) {
            return (string) $bucket['url'];
        }

        // A config published before this key existed has none, so the env var is read here too.
        $cdn = array_key_exists('cdn_url', $bucket) ? $bucket['cdn_url'] : Env::get('CORE_CDN_BASE_URL');

        return filled($cdn) && ($cloud['named'] ?? false) ? (string) $cdn : (string) ($cloud['url'] ?? '');
    }

    /**
     * Cloud's attached bucket on the public disk (`named` true), else the first that is not a scoped disk (whose
     * `scoped_disk` names its parent): a site with a private bucket beside its public one must not pick the private.
     *
     * @return array<string, mixed>|null
     */
    private static function cloudDisk(): ?array
    {
        $disks = json_decode((string) config('core-web.media.bucket.cloud', ''), true);
        // A config published before this key existed has none, so the env var is read here too.
        $name = (string) (config('core-web.media.bucket.cloud_disk') ?: Env::get('CORE_PUBLIC_DISK', 'public'));
        $first = null;

        foreach (is_array($disks) ? $disks : [] as $disk) {
            if (! is_array($disk) || ! empty($disk['scoped_disk']) || blank($disk['access_key_id'] ?? null)) {
                continue;
            }

            if (($disk['disk'] ?? null) === $name) {
                return [...$disk, 'named' => true];
            }

            $first ??= $disk;
        }

        return $first;
    }

    /**
     * An S3 disk on the bucket, at `root/<prefix>/<folder>`.
     *
     * @return array<string, mixed>
     */
    public static function bucket(string $folder, string $prefix = ''): array
    {
        $bucket = self::settings();

        return array_filter([
            'driver' => 's3',
            'key' => $bucket['key'] ?? '',
            'secret' => $bucket['secret'] ?? '',
            'endpoint' => ($bucket['endpoint'] ?? '') ?: null,
            'region' => ($bucket['region'] ?? '') ?: 'auto',
            'bucket' => $bucket['bucket'] ?? '',
            'root' => implode('/', array_filter([trim((string) ($bucket['root'] ?? ''), '/'), trim($prefix, '/'), $folder])),
            // The bucket's base URL: the S3 driver adds `root` (and so the folder) to every URL itself.
            'url' => rtrim((string) ($bucket['url'] ?? ''), '/') ?: null,
            'visibility' => ($bucket['acl'] ?? true) ? 'public' : null,
            // Not `immutable`: an editor can replace a file at the same path.
            'options' => filled($bucket['cache_control'] ?? null) ? ['CacheControl' => $bucket['cache_control']] : null,
            'throw' => false,
            'report' => false,
            'http' => ['connect_timeout' => 3, 'timeout' => 120],
            'retries' => ['mode' => 'standard', 'max_attempts' => 2],
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function local(string $folder): array
    {
        return [
            'driver' => 'local',
            'root' => public_path($folder),
            'url' => '/'.$folder,
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ];
    }

    /**
     * The file's own URL where Asset::url() would not give it: the layer's for a file the layer holds, and any file's
     * on a remote-indexed disk, whose Asset::url() is the local redirect route the CDN cannot transform. Else null.
     */
    public static function layerUrl(Asset $asset): ?string
    {
        try {
            $handle = (string) $asset->container()->diskHandle();
            $disk = Storage::disk($handle);
            $adapter = $disk instanceof FilesystemAdapter ? $disk->getAdapter() : null;

            if (! $adapter instanceof LayeredAdapter || (! $adapter->inLayer($asset->path()) && ! self::isRemoteIndexed($handle))) {
                return null;
            }

            // Encoded per segment, as Statamic encodes Asset::url(), so a space or comma cannot split a srcset.
            return $adapter->getUrl(implode('/', array_map('rawurlencode', explode('/', $asset->path()))));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The live file's own URL when only the pulled index holds it on a remote-indexed disk, not the layer or the committed folder; else null. */
    public static function indexOnlyUrl(Asset $asset): ?string
    {
        try {
            $handle = (string) $asset->container()->diskHandle();

            if (! self::isRemoteIndexed($handle)) {
                return null;
            }

            $disk = Storage::disk($handle);
            $adapter = $disk instanceof FilesystemAdapter ? $disk->getAdapter() : null;

            if (! $adapter instanceof LayeredAdapter || ! $adapter->onlyAtBottom($asset->path())) {
                return null;
            }

            return $adapter->getUrl(implode('/', array_map('rawurlencode', explode('/', $asset->path()))));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function disk(string $name, string $folder, Application $app): array
    {
        $layer = (array) config('core-web.media.layer', []);
        $localLayer = fn (): array => self::local(trim((string) ($layer['local'] ?? 'media-layer'), '/').'/'.$folder);

        if (! self::bucketConfigured()) {
            if (! self::readsRemoteIndex($app)) {
                return self::local($folder);
            }

            // Lazily: reading the disk (a pull clearing Statamic's caches) creates no folder.
            $upper = [...$localLayer(), 'lazy_root_creation' => true];
            $committed = [...self::local($folder), 'lazy_root_creation' => true];

            // No key: the layer, then the committed folder, then the live site's files as last pulled, read through their public URLs.
            return [
                'driver' => self::LAYERED_DRIVER,
                'name' => $name,
                'upper' => $upper,
                'lower' => [
                    'driver' => self::LAYERED_DRIVER,
                    'name' => $name.'-committed',
                    'upper' => $committed,
                    'lower' => ['driver' => self::REMOTE_INDEX_DRIVER, 'name' => $name, 'url' => $committed['url'], 'throw' => false, 'report' => false],
                    'url' => $committed['url'],
                    'throw' => false,
                    'report' => false,
                ],
                // Statamic builds Asset::url() on the container's URL, so it points at a route that finds the file in whichever layer holds it.
                'url' => self::fileRoute($name),
                'container_url' => self::fileRoute($name),
                'remote_index' => true,
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ];
        }

        if ($app->environment('production') || ! self::layered()) {
            return self::bucket($folder);
        }

        $upper = $app->environment('local') ? $localLayer() : self::bucket($folder, (string) ($layer['prefix'] ?? '_staging'));

        $lower = self::bucket($folder);

        // `url` too: Statamic treats a disk without one as a private container.
        return array_filter([
            'driver' => self::LAYERED_DRIVER,
            'name' => $name,
            'upper' => $upper,
            'lower' => $lower,
            'url' => $lower['url'] ?? null,
            'visibility' => $upper['visibility'] ?? null,
            'throw' => false,
            'report' => false,
        ], fn ($value) => $value !== null);
    }
}
