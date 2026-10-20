<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use Statamic\Contracts\Assets\Asset;
use Throwable;

/**
 * The asset disks in core-web.media.disks: the bucket in production, the
 * bucket behind a layer elsewhere (LayeredAdapter), the local folder when
 * the environment has no key. A disk the app defines itself is left alone.
 */
final class MediaDisks
{
    public const LAYERED_DRIVER = 'core-web-layered';

    /** @var list<string> */
    private static array $defined = [];

    public static function define(Application $app): void
    {
        $config = $app['config'];
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
    }

    /** Whether core-web defined the disk, rather than the app's own config/filesystems.php. */
    public static function defines(string $disk): bool
    {
        return in_array($disk, self::$defined, true);
    }

    public static function registerDriver(): void
    {
        Storage::extend(self::LAYERED_DRIVER, function (Application $app, array $config): FilesystemAdapter {
            $upper = Storage::build($config['upper']);
            $lower = Storage::build($config['lower']);

            $adapter = new LayeredAdapter(
                $upper->getAdapter(),
                $lower->getAdapter(),
                fn (string $path): string => $upper->url($path),
                fn (string $path): string => $lower->url($path),
                $app['cache']->store(),
                'core-web.media.layer.'.$config['name'],
            );

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
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
            'url' => filled($bucket['url'] ?? null) ? $bucket['url'] : (string) ($cloud['url'] ?? ''),
            'acl' => false,
        ]);
    }

    /** @return array<string, mixed>|null The first of Cloud's attached buckets that is not a scoped disk (whose `scoped_disk` names its parent). */
    private static function cloudDisk(): ?array
    {
        $disks = json_decode((string) config('core-web.media.bucket.cloud', ''), true);

        foreach (is_array($disks) ? $disks : [] as $disk) {
            if (is_array($disk) && empty($disk['scoped_disk']) && filled($disk['access_key_id'] ?? null)) {
                return $disk;
            }
        }

        return null;
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

    /** The layer's URL for an asset whose file the layer holds, or null (no layered disk, or the bucket's file). */
    public static function layerUrl(Asset $asset): ?string
    {
        try {
            $disk = Storage::disk($asset->container()->diskHandle());
            $adapter = $disk instanceof FilesystemAdapter ? $disk->getAdapter() : null;

            if (! $adapter instanceof LayeredAdapter || ! $adapter->inLayer($asset->path())) {
                return null;
            }

            // Encoded per segment, as Statamic encodes Asset::url(), so a space or comma cannot split a srcset.
            return $adapter->getUrl(implode('/', array_map('rawurlencode', explode('/', $asset->path()))));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function disk(string $name, string $folder, Application $app): array
    {
        if (! self::bucketConfigured()) {
            return self::local($folder);
        }

        if ($app->environment('production') || ! self::layered()) {
            return self::bucket($folder);
        }

        $layer = (array) config('core-web.media.layer', []);
        $upper = $app->environment('local')
            ? self::local(trim((string) ($layer['local'] ?? 'media-layer'), '/').'/'.$folder)
            : self::bucket($folder, (string) ($layer['prefix'] ?? '_staging'));

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
