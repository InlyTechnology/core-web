<?php

namespace Inly\CoreWeb\ContentSync;

use Closure;
use Facades\Statamic\Fields\Validator as FieldValidator;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Inly\CoreWeb\Media\MissingAltText;
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

    /** Send the file through the site (media/file): the site sanitises it as it stores it (an SVG). */
    public const DIRECT = 'direct';

    public const STORED = 'stored';

    /** The most files one check request names. */
    public const CHECK_FILES = 500;

    /** The most files one uploads request names: the site asks the bucket about each before signing it. */
    public const UPLOAD_FILES = 50;

    /** The most files one finish request names: finishing reads each file from the bucket. */
    public const FINISH_FILES = 10;

    /** How long a signed upload address is valid; the checkout signs again for what it has not sent within it. */
    public const URL_MINUTES = 15;

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
        $whole = array_values(array_map(fn (array $file) => $file['path'], array_filter($files, fn (array $file) => ($listing['files'][$file['path']] ?? null) === (int) $file['size'])));
        $meta = $this->metaPaths($container, $whole, $listing['meta']);

        return array_map(fn (array $file) => ['path' => $file['path'], ...$this->status(
            $user, $container, $file['path'], (int) $file['size'], $listing['files'][$file['path']] ?? null, fn () => isset($meta[$file['path']]),
        )], $files);
    }

    /**
     * For each file the container lacks, how to send it: a signed upload straight to the bucket (`upload`), the
     * site's own upload (`direct`), or nothing more than finishing it (`finish`, for an unfinished one). Each file
     * is looked up on the disk again here, so a listing from minutes ago never decides what is sent.
     *
     * @param  list<array{path: string, size: int, md5: string}>  $files
     * @return list<array<string, mixed>>
     */
    public function uploads(User $user, string $handle, array $files): array
    {
        $target = $this->target($user, $handle);

        if (isset($target['status'])) {
            return array_map(fn (array $file) => ['path' => $file['path'], ...$target], $files);
        }

        $container = $target['container'];
        $filesystem = $container->disk()->filesystem();
        $existing = [];

        foreach ($files as $file) {
            $existing[$file['path']] = AssetPush::safePath($file['path']) && $filesystem->fileExists($file['path']) ? (int) $filesystem->size($file['path']) : null;
        }

        $meta = $this->metaPaths($container, array_keys(array_filter($existing, fn (?int $size) => $size !== null)), null);

        return array_map(function (array $file) use ($user, $container, $filesystem, $existing, $meta): array {
            $path = $file['path'];
            $size = (int) $file['size'];
            $md5 = strtolower((string) $file['md5']);
            $status = $this->status($user, $container, $path, $size, $existing[$path], fn () => isset($meta[$path]));

            if ($status['status'] === self::UNFINISHED) {
                return ['path' => $path, 'status' => self::FINISH, 'ticket' => self::ticket($user, $container, $path, $size, $md5, self::FINISH)];
            }

            if ($status['status'] !== AssetPush::MISSING) {
                return ['path' => $path, ...$status];
            }

            if (self::throughSite($path)) {
                return ['path' => $path, 'status' => self::DIRECT];
            }

            $options = self::putOptions($filesystem, $path, $md5);
            $signed = $filesystem->temporaryUploadUrl($path, now()->addMinutes(self::URL_MINUTES), $options);

            return [
                'path' => $path,
                'status' => self::UPLOAD,
                'url' => $signed['url'],
                'headers' => self::headers($signed['headers'], $options),
                'ticket' => self::ticket($user, $container, $path, $size, $md5, self::UPLOAD),
            ];
        }, $files);
    }

    /**
     * Saves the asset for each file its ticket names, once the file is in the container with the ticket's bytes,
     * with its alt text and focal point. Saved quietly: a push of thousands of files must not refresh the static
     * cache once per file. A file this push sent that the container's rules refuse is deleted again; any other is
     * left as it is.
     *
     * @param  list<array{ticket: string, alt?: string|null, focus?: string|null}>  $items
     * @return list<array{path: string, status: string, message?: string}>
     */
    public function finish(User $user, string $handle, array $items): array
    {
        $target = $this->target($user, $handle);

        $results = array_map(function (array $item) use ($user, $handle, $target): array {
            $ticket = self::readTicket($user, $handle, (string) $item['ticket']);

            if ($ticket === null) {
                return ['path' => '', 'status' => AssetPush::INVALID, 'message' => __('core-web::sync.media_push_ticket')];
            }

            if (isset($target['status'])) {
                return ['path' => $ticket['path'], ...$target];
            }

            return ['path' => $ticket['path'], ...$this->finishOne($target['container'], $ticket, $item)];
        }, $items);

        // The dashboard's count of images without alt text, which a quiet save leaves as it was.
        if (in_array(self::STORED, array_column($results, 'status'), true)) {
            MissingAltText::forget($handle);
        }

        return $results;
    }

    /** The largest file media:push copies; an SVG, which goes through the site, takes content:push's limit. */
    public static function maxBytes(string $path): int
    {
        return self::throughSite($path)
            ? AssetPush::maxKilobytes() * 1024
            : max(1, (int) config('core-web.content_sync.media_push_bytes', 1024 * 1024 * 1024));
    }

    /** The site sanitises an SVG as it stores it, so an SVG goes through the site rather than to the bucket. */
    private static function throughSite(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
    }

    /**
     * @param  array{path: string, size: int, md5: string, kind: string}  $ticket
     * @param  array<string, mixed>  $item
     * @return array{status: string, message?: string}
     */
    private function finishOne(Container $container, array $ticket, array $item): array
    {
        $path = $ticket['path'];
        $sent = $ticket['kind'] === self::UPLOAD;
        $filesystem = $container->disk()->filesystem();
        $other = ['status' => AssetPush::CONFLICT, 'message' => __('core-web::sync.media_push_other_file', ['path' => "{$container->handle()}::{$path}"])];

        if (! $filesystem->fileExists($path)) {
            return ['status' => AssetPush::MISSING, 'message' => __('core-web::sync.media_push_not_arrived', ['path' => $path])];
        }

        if ((int) $filesystem->size($path) !== $ticket['size']) {
            // The signed address takes only the ticket's bytes, so a file with their MD5 was sent with a size it did not declare.
            if ($sent && self::checksum($filesystem, $path) === $ticket['md5']) {
                $filesystem->delete($path);

                return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.media_push_size', ['path' => $path, 'size' => $ticket['size']])];
            }

            return $other;
        }

        $copy = tempnam(sys_get_temp_dir(), 'core-web-media-push-');

        try {
            $stream = $filesystem->readStream($path);

            if (! is_resource($stream) || file_put_contents($copy, $stream) === false) {
                return ['status' => AssetPush::MISSING, 'message' => __('core-web::sync.media_push_not_arrived', ['path' => $path])];
            }

            if (md5_file($copy) !== $ticket['md5']) {
                return $other;
            }

            if ($this->hasMeta($container, $path)) {
                return ['status' => AssetPush::SAME];
            }

            $refusal = $this->breaksRules($container, $copy, $path) ?? ($sent ? self::wrongType($filesystem, $path) : null);
        } finally {
            isset($stream) && is_resource($stream) && fclose($stream);
            @unlink($copy);
        }

        if ($refusal !== null) {
            // Only a file this push sent is taken out again; one that was there before is the site's to judge.
            if (! $sent) {
                return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.media_push_left', ['reason' => $refusal])];
            }

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

    /** The file's checksum on the disk: its MD5 on a local disk, its ETag on a bucket, which is the MD5 of a single PUT. */
    private static function checksum(FilesystemAdapter $filesystem, string $path): ?string
    {
        try {
            $checksum = $filesystem->checksum($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($checksum) ? strtolower($checksum) : null;
    }

    /** The first of the container's file rules the file breaks, read from its local copy; null when it breaks none. */
    private function breaksRules(Container $container, string $copy, string $path): ?string
    {
        $rules = collect($container->validationRules())->map(fn ($rule) => FieldValidator::parse($rule))->all();

        if ($rules === []) {
            return null;
        }

        $validator = Validator::make(['file' => new UploadedFile($copy, basename($path), null, null, true)], ['file' => array_merge(['file'], $rules)]);

        return $validator->fails() ? $validator->errors()->first('file') : null;
    }

    /** The bucket keeps the type the upload named, which a signed address cannot fix, so it must be the extension's. */
    private static function wrongType(FilesystemAdapter $filesystem, string $path): ?string
    {
        $expected = self::contentType($path);
        $stored = (string) $filesystem->mimeType($path);

        return strtolower(trim(explode(';', $stored)[0])) === $expected
            ? null
            : __('core-web::sync.media_push_type', ['path' => $path, 'type' => $stored, 'expected' => $expected]);
    }

    /**
     * @param  ?int  $existing  the size of the file the container has at the path, null when it has none
     * @param  Closure(): bool  $hasMeta
     * @return array{status: string, message?: string}
     */
    private function status(User $user, Container $container, string $path, int $size, ?int $existing, Closure $hasMeta): array
    {
        if (! AssetPush::safePath($path)) {
            return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.push_asset_path', ['path' => $path])];
        }

        if (! AssetPush::allowedExtension($path)) {
            return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.push_asset_extension', ['path' => $path])];
        }

        if ($existing === null) {
            if ($size > self::maxBytes($path)) {
                return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.media_push_too_large', ['path' => $path, 'mb' => intdiv(self::maxBytes($path), 1024 * 1024)])];
            }

            $folder = dirname($path);

            // As in the CP, a new folder needs the permission to create one.
            if ($folder !== '.' && ! $this->folderExists($container, $folder) && ! $user->can('create', [AssetFolder::class, $container])) {
                return ['status' => AssetPush::FORBIDDEN, 'message' => __('core-web::sync.push_asset_folder', ['folder' => $folder])];
            }

            return ['status' => AssetPush::MISSING];
        }

        if ($existing !== $size) {
            return ['status' => AssetPush::CONFLICT, 'message' => __('core-web::sync.push_asset_conflict', ['path' => "{$container->handle()}::{$path}"])];
        }

        return ['status' => $hasMeta() ? AssetPush::SAME : self::UNFINISHED];
    }

    /** Whether the site holds the file's meta: a database row with the eloquent driver, `.meta/<name>.yaml` on the disk with Statamic's own. */
    private function hasMeta(Container $container, string $path): bool
    {
        return $this->metaPaths($container, [$path], null) !== [];
    }

    /**
     * Which of the paths the site holds meta for, in one query with the eloquent driver; otherwise from the meta
     * files a listing found, or asking the disk when no listing was made.
     *
     * @param  list<string>  $paths
     * @param  ?array<string, true>  $listed  the meta file paths a listing of the paths' folders found
     * @return array<string, true>
     */
    private function metaPaths(Container $container, array $paths, ?array $listed): array
    {
        if ($paths === []) {
            return [];
        }

        if ($container->makeAsset($paths[0]) instanceof EloquentAsset) {
            return array_fill_keys(app('statamic.eloquent.assets.model')::query()
                ->where('container', $container->handle())
                ->whereIn('path', $paths)
                ->pluck('path')
                ->all(), true);
        }

        $filesystem = $container->disk()->filesystem();
        $found = [];

        foreach ($paths as $path) {
            $meta = $container->makeAsset($path)->metaPath();

            if ($listed !== null ? isset($listed[$meta]) : $filesystem->exists($meta)) {
                $found[$path] = true;
            }
        }

        return $found;
    }

    /**
     * The sizes of the files in each folder the paths name, and the meta files beside them, read once per folder.
     * A listing that fails fails the request: read as empty, it would make every file look missing.
     *
     * @param  list<string>  $paths
     * @return array{files: array<string, int>, meta: array<string, true>}
     */
    private function listing(Container $container, array $paths): array
    {
        $filesystem = $container->disk()->filesystem();
        $eloquent = $paths !== [] && $container->makeAsset($paths[0]) instanceof EloquentAsset;
        $listing = ['files' => [], 'meta' => []];

        foreach (array_unique(array_map(fn (string $path) => dirname($path) === '.' ? '' : dirname($path), $paths)) as $folder) {
            foreach ($filesystem->getDriver()->listContents($folder, false) as $item) {
                if ($item instanceof FileAttributes) {
                    $listing['files'][$item->path()] = $item->fileSize() ?? (int) $filesystem->size($item->path());
                }
            }

            // The eloquent driver keeps meta in the database, so its .meta folders say nothing.
            if (! $eloquent) {
                foreach ($filesystem->getDriver()->listContents(ltrim($folder.'/.meta', '/'), false) as $item) {
                    $item instanceof FileAttributes && $listing['meta'][$item->path()] = true;
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

    /**
     * The container, when it exists, its disk takes signed uploads and the user may upload to it.
     *
     * @return array{container: Container}|array{status: string, message: string}
     */
    private function target(User $user, string $handle): array
    {
        $container = AssetContainer::find($handle);

        if ($container === null) {
            return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.no_container', ['handle' => $handle])];
        }

        if (! self::signsUploads($container->disk()->filesystem())) {
            return ['status' => AssetPush::INVALID, 'message' => __('core-web::sync.media_push_not_bucket', ['handle' => $handle])];
        }

        if (! $user->can('store', [AssetContract::class, $container])) {
            return ['status' => AssetPush::FORBIDDEN, 'message' => __('core-web::sync.push_asset_forbidden', ['handle' => $handle])];
        }

        return ['container' => $container];
    }

    /** Whether the disk hands out signed upload addresses: a bucket does, a local folder or core-web's layer does not. */
    private static function signsUploads(FilesystemAdapter $filesystem): bool
    {
        // Laravel 12, and 13 before 13.20, answer false to providesTemporaryUploadUrls() for the S3 driver, which signs them.
        if ($filesystem instanceof AwsS3V3Adapter) {
            return true;
        }

        // A local disk with `serve` signs uploads to its own route, which reads the whole file into memory.
        if ($filesystem instanceof LocalFilesystemAdapter && ($filesystem->getConfig()['serve'] ?? false)) {
            return false;
        }

        return method_exists($filesystem, 'providesTemporaryUploadUrls') && $filesystem->providesTemporaryUploadUrls();
    }

    private static function contentType(string $path): string
    {
        return (new ExtensionMimeTypeDetector)->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
    }

    /**
     * What the stored object carries: its type from its extension, and the disk's Cache-Control and public ACL, as
     * a write through the disk would set them, and the file's MD5, which the signature covers, so the address takes
     * no other bytes.
     *
     * @return array<string, string>
     */
    private static function putOptions(FilesystemAdapter $filesystem, string $path, string $md5): array
    {
        $config = $filesystem->getConfig();

        return array_filter([
            'ContentType' => self::contentType($path),
            'CacheControl' => $config['options']['CacheControl'] ?? null,
            'ACL' => ($config['visibility'] ?? null) === 'public' ? 'public-read' : null,
            'ContentMD5' => base64_encode((string) hex2bin($md5)),
        ], fn (mixed $value) => is_string($value) && $value !== '');
    }

    /**
     * The headers the upload must carry, one string each: the signed ones, and the type and Cache-Control, which an
     * S3 signature leaves out but the bucket stores from the request. Host comes from the URL.
     *
     * @param  array<string, list<string>|string>  $signed
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    private static function headers(array $signed, array $options): array
    {
        $flat = [];

        foreach ($signed as $name => $values) {
            if (strtolower((string) $name) !== 'host') {
                $flat[(string) $name] = implode(', ', (array) $values);
            }
        }

        $named = array_map('strtolower', array_keys($flat));

        foreach (['Content-Type' => 'ContentType', 'Cache-Control' => 'CacheControl'] as $header => $option) {
            if (isset($options[$option]) && ! in_array(strtolower($header), $named, true)) {
                $flat[$header] = $options[$option];
            }
        }

        return $flat;
    }

    private static function ticket(User $user, Container $container, string $path, int $size, string $md5, string $kind): string
    {
        return Crypt::encryptString((string) json_encode([
            'user' => (string) $user->id(),
            'container' => $container->handle(),
            'path' => $path,
            'size' => $size,
            'md5' => $md5,
            'kind' => $kind,
            'expires' => now()->addMinutes(self::TICKET_MINUTES)->getTimestamp(),
        ]));
    }

    /** @return array{path: string, size: int, md5: string, kind: string}|null what a ticket this site gave this user for this container names */
    private static function readTicket(User $user, string $handle, string $ticket): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($ticket), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['user'] ?? null) !== (string) $user->id() || ($data['container'] ?? null) !== $handle
            || ! is_string($data['path'] ?? null) || ! is_int($data['size'] ?? null) || ! is_string($data['md5'] ?? null)
            || ! in_array($data['kind'] ?? null, [self::UPLOAD, self::FINISH], true) || (int) ($data['expires'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return ['path' => $data['path'], 'size' => $data['size'], 'md5' => $data['md5'], 'kind' => $data['kind']];
    }
}
