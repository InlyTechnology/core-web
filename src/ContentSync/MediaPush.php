<?php

namespace Inly\CoreWeb\ContentSync;

use Facades\Statamic\Fields\Validator as FieldValidator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use League\Flysystem\FileAttributes;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as Container;
use Statamic\Contracts\Assets\AssetFolder;
use Statamic\Contracts\Auth\User;
use Statamic\Eloquent\Assets\Asset as EloquentAsset;
use Statamic\Facades\AssetContainer;
use Throwable;

/**
 * The site's half of `media:push` (docs/media.md, Copying files into a container): which of a checkout's files
 * the container lacks, a signed upload for each straight to the bucket, and the asset saved once it is there.
 */
class MediaPush
{
    /** The file is in the container with that size, but the site holds no meta for it: an upload that never finished. */
    public const UNFINISHED = 'unfinished';

    /** Upload the file to `url` with `headers`, then finish it with `ticket`. */
    public const UPLOAD = 'upload';

    /** The file is there already: finish it with `ticket`. */
    public const FINISH = 'finish';

    /** Send the file through the site (media/file): its disk takes no signed upload, or the site must process it (an SVG). */
    public const DIRECT = 'direct';

    public const STORED = 'stored';

    /** The most files one check request names. */
    public const CHECK_FILES = 500;

    /** The most files one uploads request names: signing is cheap, but each signed address lasts URL_MINUTES. */
    public const UPLOAD_FILES = 50;

    /** The most files one finish request names: saving an asset reads the file to measure it. */
    public const FINISH_FILES = 10;

    private const URL_MINUTES = 15;

    private const TICKET_MINUTES = 120;

    /** @var array<string, bool> folder => exists, per container, for one request */
    private array $folders = [];

    /**
     * What the container holds at each path: `missing`, `same` (that size, with meta), `unfinished`, `conflict`
     * (another size; never replaced), `invalid` or `forbidden`. Sizes come from one listing per folder, so the
     * bucket is not asked once per file.
     *
     * @param  list<array{path: string, size: int}>  $files
     * @return list<array{path: string, status: string, message?: string}>
     */
    public function check(User $user, string $handle, array $files): array
    {
        $target = $this->target($user, $handle);

        if (isset($target['status'])) {
            return array_map(fn (array $file) => ['path' => $file['path'], ...$target], $files);
        }

        $container = $target['container'];
        $listing = $this->listing($container, array_column($files, 'path'));

        return array_map(fn (array $file) => ['path' => $file['path'], ...$this->status($user, $container, $file['path'], (int) $file['size'], $listing)], $files);
    }

    /**
     * For each file the container lacks, how to send it: a signed upload straight to the bucket (`upload`), the
     * site's own upload (`direct`), or nothing more than finishing it (`finish`, for an unfinished one).
     *
     * @param  list<array{path: string, size: int}>  $files
     * @return list<array<string, mixed>>
     */
    public function uploads(User $user, string $handle, array $files): array
    {
        $checked = $this->check($user, $handle, $files);
        $container = AssetContainer::find($handle);
        $sizes = array_column($files, 'size', 'path');

        return array_map(function (array $check) use ($user, $container, $sizes): array {
            $path = $check['path'];
            $size = (int) $sizes[$path];

            if ($check['status'] === self::UNFINISHED) {
                return ['path' => $path, 'status' => self::FINISH, 'ticket' => self::ticket($user, $container, $path, $size)];
            }

            if ($check['status'] !== AssetPush::MISSING) {
                return $check;
            }

            if ($size > self::maxBytes()) {
                return ['path' => $path, 'status' => AssetPush::INVALID, 'message' => __('core-web::sync.media_push_too_large', ['path' => $path, 'mb' => intdiv(self::maxBytes(), 1024 * 1024)])];
            }

            $filesystem = $container->disk()->filesystem();

            // The site sanitises an SVG as it stores it, so an SVG always goes through the site.
            if (! self::signsUploads($filesystem) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
                return $size > AssetPush::maxKilobytes() * 1024
                    ? ['path' => $path, 'status' => AssetPush::INVALID, 'message' => __('core-web::sync.media_push_too_large', ['path' => $path, 'mb' => intdiv(AssetPush::maxKilobytes(), 1024)])]
                    : ['path' => $path, 'status' => self::DIRECT];
            }

            $signed = $filesystem->temporaryUploadUrl($path, now()->addMinutes(self::URL_MINUTES), self::putOptions($filesystem, $path));

            return [
                'path' => $path,
                'status' => self::UPLOAD,
                'url' => $signed['url'],
                'headers' => self::headers($signed['headers']),
                'ticket' => self::ticket($user, $container, $path, $size),
            ];
        }, $checked);
    }

    /**
     * Saves the asset for each file its ticket names, once the file is in the container at the ticket's size, with
     * its alt text and focal point. Saved quietly: a push of thousands of files must not refresh the static cache
     * once per file. A file the container's rules refuse is deleted again; one with meta already is left alone.
     *
     * @param  list<array{ticket: string, alt?: string|null, focus?: string|null}>  $items
     * @return list<array{path: string, status: string, message?: string}>
     */
    public function finish(User $user, string $handle, array $items): array
    {
        $target = $this->target($user, $handle);

        return array_map(function (array $item) use ($user, $handle, $target): array {
            $ticket = self::readTicket($user, $handle, (string) $item['ticket']);

            if ($ticket === null) {
                return ['path' => '', 'status' => AssetPush::INVALID, 'message' => __('core-web::sync.media_push_ticket')];
            }

            $path = $ticket['path'];

            if (isset($target['status'])) {
                return ['path' => $path, ...$target];
            }

            return ['path' => $path, ...$this->finishOne($target['container'], $path, $ticket['size'], $item)];
        }, $items);
    }

    /** The largest file a signed upload takes. */
    public static function maxBytes(): int
    {
        return max(1, (int) config('core-web.content_sync.media_push_bytes', 1024 * 1024 * 1024));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{status: string, message?: string}
     */
    private function finishOne(Container $container, string $path, int $size, array $item): array
    {
        $filesystem = $container->disk()->filesystem();

        if (! $filesystem->exists($path)) {
            return ['status' => AssetPush::MISSING, 'message' => __('core-web::sync.media_push_not_arrived', ['path' => $path])];
        }

        if ((int) $filesystem->size($path) !== $size) {
            return ['status' => AssetPush::CONFLICT, 'message' => __('core-web::sync.push_asset_conflict', ['path' => "{$container->handle()}::{$path}"])];
        }

        if ($this->hasMeta($container, $path, [])) {
            return ['status' => AssetPush::SAME];
        }

        if (($refusal = $this->breaksRules($container, $filesystem, $path)) !== null) {
            $filesystem->delete($path);

            return ['status' => AssetPush::INVALID, 'message' => $refusal];
        }

        $asset = $container->makeAsset($path);

        foreach (AssetPush::META_KEYS as $key) {
            if (is_string($item[$key] ?? null) && $item[$key] !== '') {
                $asset->set($key, mb_substr($item[$key], 0, 1000));
            }
        }

        $asset->saveQuietly();

        return ['status' => self::STORED];
    }

    /** The first of the container's file rules the stored file breaks, read from a local copy; null when it has none. */
    private function breaksRules(Container $container, FilesystemAdapter $filesystem, string $path): ?string
    {
        $rules = collect($container->validationRules())->map(fn ($rule) => FieldValidator::parse($rule))->all();

        if ($rules === []) {
            return null;
        }

        $copy = tempnam(sys_get_temp_dir(), 'core-web-media-push-');
        $stream = $filesystem->readStream($path);

        try {
            if (! is_resource($stream) || file_put_contents($copy, $stream) === false) {
                return __('core-web::sync.media_push_not_arrived', ['path' => $path]);
            }

            $file = new UploadedFile($copy, basename($path), null, null, true);
            $validator = Validator::make(['file' => $file], ['file' => array_merge(['file'], $rules)]);

            return $validator->fails() ? $validator->errors()->first('file') : null;
        } finally {
            is_resource($stream) && fclose($stream);
            @unlink($copy);
        }
    }

    /**
     * @param  array{files: array<string, int>, meta: array<string, true>}  $listing
     * @return array{status: string, message?: string}
     */
    private function status(User $user, Container $container, string $path, int $size, array $listing): array
    {
        if (! AssetPush::safePath($path)) {
            return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.push_asset_path', ['path' => $path])];
        }

        if (! AssetPush::allowedExtension($path)) {
            return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.push_asset_extension', ['path' => $path])];
        }

        if (! array_key_exists($path, $listing['files'])) {
            $folder = dirname($path);

            // As in the CP, a new folder needs the permission to create one.
            if ($folder !== '.' && ! $this->folderExists($container, $folder) && ! $user->can('create', [AssetFolder::class, $container])) {
                return ['status' => AssetPush::FORBIDDEN, 'message' => __('core-web::sync.push_asset_folder', ['folder' => $folder])];
            }

            return ['status' => AssetPush::MISSING];
        }

        if ($listing['files'][$path] !== $size) {
            return ['status' => AssetPush::CONFLICT, 'message' => __('core-web::sync.push_asset_conflict', ['path' => "{$container->handle()}::{$path}"])];
        }

        return ['status' => $this->hasMeta($container, $path, $listing['meta']) ? AssetPush::SAME : self::UNFINISHED];
    }

    /**
     * Whether the site holds the file's meta. The eloquent driver keeps it in the database; Statamic's own keeps it on
     * the disk, as `.meta/<name>.yaml` beside the file, found in the listing when given.
     *
     * @param  array<string, true>  $listed
     */
    private function hasMeta(Container $container, string $path, array $listed): bool
    {
        $asset = $container->makeAsset($path);

        if ($asset instanceof EloquentAsset) {
            return $asset->metaExists();
        }

        return $listed !== [] ? isset($listed[$asset->metaPath()]) : $container->disk()->filesystem()->exists($asset->metaPath());
    }

    /**
     * The sizes of the files in each folder the paths name, and the meta files beside them, read once per folder.
     *
     * @param  list<string>  $paths
     * @return array{files: array<string, int>, meta: array<string, true>}
     */
    private function listing(Container $container, array $paths): array
    {
        $filesystem = $container->disk()->filesystem();
        $listing = ['files' => [], 'meta' => []];

        foreach (array_unique(array_map(fn (string $path) => dirname($path) === '.' ? '' : dirname($path), $paths)) as $folder) {
            foreach ([$folder, ltrim($folder.'/.meta', '/')] as $i => $listed) {
                try {
                    foreach ($filesystem->getDriver()->listContents($listed, false) as $item) {
                        if (! $item instanceof FileAttributes) {
                            continue;
                        }

                        if ($i === 1) {
                            $listing['meta'][$item->path()] = true;
                        } else {
                            $listing['files'][$item->path()] = $item->fileSize() ?? (int) $filesystem->size($item->path());
                        }
                    }
                } catch (Throwable) {
                    // A folder the disk does not have lists nothing.
                }
            }
        }

        return $listing;
    }

    private function folderExists(Container $container, string $folder): bool
    {
        $key = $container->handle().'::'.$folder;

        return $this->folders[$key] ??= $container->folders('/', true)->contains($folder);
    }

    /** @return array{container: Container}|array{status: string, message: string} */
    private function target(User $user, string $handle): array
    {
        $container = AssetContainer::find($handle);

        if ($container === null) {
            return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.no_container', ['handle' => $handle])];
        }

        if (! $user->can('store', [AssetContract::class, $container])) {
            return ['status' => AssetPush::FORBIDDEN, 'message' => __('core-web::sync.push_asset_forbidden', ['handle' => $handle])];
        }

        return ['container' => $container];
    }

    /** Whether the disk hands out signed upload addresses: a bucket does, a local folder or core-web's layer does not. */
    private static function signsUploads(FilesystemAdapter $filesystem): bool
    {
        // A local disk with `serve` signs uploads to its own route, which reads the whole file into memory.
        if ($filesystem instanceof LocalFilesystemAdapter && ($filesystem->getConfig()['serve'] ?? false)) {
            return false;
        }

        // Laravel 12 before providesTemporaryUploadUrls(): the S3 driver is the disk that signs uploads.
        return method_exists($filesystem, 'providesTemporaryUploadUrls') ? $filesystem->providesTemporaryUploadUrls() : $filesystem instanceof AwsS3V3Adapter;
    }

    /**
     * What the stored object carries: its type from its extension, and the disk's Cache-Control and public ACL,
     * as a write through the disk would set them.
     *
     * @return array<string, string>
     */
    private static function putOptions(FilesystemAdapter $filesystem, string $path): array
    {
        $config = $filesystem->getConfig();

        return array_filter([
            'ContentType' => (new ExtensionMimeTypeDetector)->detectMimeTypeFromPath($path) ?? 'application/octet-stream',
            'CacheControl' => $config['options']['CacheControl'] ?? null,
            'ACL' => ($config['visibility'] ?? null) === 'public' ? 'public-read' : null,
        ], fn (mixed $value) => is_string($value) && $value !== '');
    }

    /**
     * The headers the signed upload must carry, one string each; Host comes from the URL.
     *
     * @param  array<string, list<string>|string>  $headers
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) !== 'host') {
                $flat[(string) $name] = implode(', ', (array) $values);
            }
        }

        return $flat;
    }

    private static function ticket(User $user, Container $container, string $path, int $size): string
    {
        return Crypt::encryptString((string) json_encode([
            'user' => (string) $user->id(),
            'container' => $container->handle(),
            'path' => $path,
            'size' => $size,
            'expires' => now()->addMinutes(self::TICKET_MINUTES)->getTimestamp(),
        ]));
    }

    /** @return array{path: string, size: int}|null the path and size a ticket this site gave this user for this container names */
    private static function readTicket(User $user, string $handle, string $ticket): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($ticket), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['user'] ?? null) !== (string) $user->id() || ($data['container'] ?? null) !== $handle
            || ! is_string($data['path'] ?? null) || ! is_int($data['size'] ?? null) || (int) ($data['expires'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return ['path' => $data['path'], 'size' => $data['size']];
    }
}
