<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as Container;
use Statamic\Contracts\Assets\AssetFolder;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Validator as FieldValidator;
use Statamic\Rules\AllowedFile;
use Statamic\Support\Svg;

/**
 * The asset half of a push: a file the checkout uploaded locally that a
 * pushed file names reaches the site at the same path, so the reference
 * holds there. Only a path the site does not have yet is written; one it has
 * with the same bytes is left alone, one with other bytes is refused, and
 * nothing is ever overwritten, moved or deleted. Uploading is checked as the
 * CP's upload checks it: the container's permission, its file rules.
 */
class AssetPush
{
    public const MISSING = 'missing';

    public const SAME = 'same';

    public const CONFLICT = 'conflict';

    public const FORBIDDEN = 'forbidden';

    public const INVALID = 'invalid';

    /** The editable meta a checkout's upload carries; the rest (size, dimensions) the site reads from the file. */
    public const META_KEYS = ['alt', 'focus'];

    /**
     * What the site holds at an asset's path: `missing`, `same` (the bytes the checkout would store), `conflict`, `forbidden` or `invalid`.
     *
     * @return array{status: string, message?: string}
     */
    public function check(User $user, string $container, string $path, string $hash, ?int $size = null): array
    {
        $found = $this->container($user, $container, $path);

        if (isset($found['status'])) {
            return $found;
        }

        $disk = $found['container']->disk();

        if (! $disk->exists($path)) {
            return ['status' => self::MISSING];
        }

        $conflict = ['status' => self::CONFLICT, 'message' => __('core-web::sync.push_asset_conflict', ['path' => "{$container}::{$path}"])];

        // The size settles most conflicts without reading a file that may sit in a bucket.
        if ($size !== null && $disk->size($path) !== $size) {
            return $conflict;
        }

        return hash_equals(self::streamHash($disk->filesystem()->readStream($disk->normalizePath($path))), $hash) ? ['status' => self::SAME] : $conflict;
    }

    /**
     * Writes an uploaded file at its path, with its alt text and focal point, when the site has nothing there.
     *
     * @param  array<string, mixed>  $meta
     * @return array{status: string, message?: string}
     */
    public function store(User $user, string $container, string $path, UploadedFile $file, array $meta): array
    {
        $found = $this->container($user, $container, $path);

        if (isset($found['status'])) {
            return $found;
        }

        $container = $found['container'];

        // The file is written at `path`, so the file rules must see its extension, not the upload's name.
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== strtolower($file->getClientOriginalExtension())) {
            return ['status' => self::INVALID, 'message' => __('core-web::sync.push_asset_extension', ['path' => $path])];
        }

        $rules = collect($container->validationRules())->map(fn ($rule) => FieldValidator::parse($rule))->all();
        $validator = Validator::make(['file' => $file], ['file' => array_merge(['file', new AllowedFile, 'max:'.self::maxKilobytes()], $rules)]);

        if ($validator->fails()) {
            return ['status' => self::INVALID, 'message' => $validator->errors()->first('file')];
        }

        $bytes = self::stored(basename($path), (string) file_get_contents($file->getRealPath()));
        $hash = hash('sha256', $bytes);

        // Two pushes of one new path would both see it missing; the lock lets only the first write it.
        $lock = Cache::lock('core-web-asset-push:'.sha1($container->handle().'::'.$path), 30);

        if (! $lock->get()) {
            return ['status' => self::CONFLICT, 'message' => __('core-web::sync.push_asset_conflict', ['path' => "{$container->handle()}::{$path}"])];
        }

        try {
            $existing = $this->check($user, $container->handle(), $path, $hash);

            if ($existing['status'] !== self::MISSING) {
                return $existing;
            }

            $container->disk()->put($path, $bytes);
        } finally {
            $lock->release();
        }

        $asset = $container->makeAsset($path);

        foreach (self::META_KEYS as $key) {
            if (is_string($meta[$key] ?? null) && $meta[$key] !== '') {
                $asset->set($key, mb_substr($meta[$key], 0, 1000));
            }
        }

        $asset->save();

        return ['status' => 'stored'];
    }

    /** The bytes the site stores for a file: an SVG sanitised as the CP's upload sanitises it, so a checkout can compare its own copy. */
    public static function stored(string $filename, string $bytes): string
    {
        if (config('statamic.assets.svg_sanitization_on_upload', true) && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
            return Svg::sanitize($bytes);
        }

        return $bytes;
    }

    /** @param  resource|null  $stream */
    private static function streamHash($stream): string
    {
        $context = hash_init('sha256');

        if (is_resource($stream)) {
            hash_update_stream($context, $stream);
            fclose($stream);
        }

        return hash_final($context);
    }

    public static function maxKilobytes(): int
    {
        return max(1, intdiv((int) config('core-web.content_sync.push_asset_bytes', 20 * 1024 * 1024), 1024));
    }

    /**
     * Whether a path is one an upload may name: inside the container, a file with an extension, never a `.meta` file or other hidden one.
     */
    public static function safePath(string $path): bool
    {
        if (! ContentPaths::safe($path) || pathinfo($path, PATHINFO_EXTENSION) === '') {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }

        return true;
    }

    /** Whether the CP would take an upload with the path's extension (Statamic's AllowedFile list). */
    private static function allowedExtension(string $path): bool
    {
        $named = new UploadedFile('/dev/null', basename($path), null, UPLOAD_ERR_NO_FILE, true);

        return ! Validator::make(['file' => $named], ['file' => [new AllowedFile]])->fails();
    }

    /** @return array{container: Container}|array{status: string, message: string} */
    private function container(User $user, string $handle, string $path): array
    {
        if (! self::safePath($path)) {
            return ['status' => self::INVALID, 'message' => __('core-web::sync.push_asset_path', ['path' => $path])];
        }

        if (! self::allowedExtension($path)) {
            return ['status' => self::INVALID, 'message' => __('core-web::sync.push_asset_extension', ['path' => $path])];
        }

        $container = AssetContainer::find($handle);

        if ($container === null) {
            return ['status' => self::INVALID, 'message' => __('core-web::sync.no_container', ['handle' => $handle])];
        }

        if (! $user->can('store', [AssetContract::class, $container])) {
            return ['status' => self::FORBIDDEN, 'message' => __('core-web::sync.push_asset_forbidden', ['handle' => $handle])];
        }

        $folder = dirname($path);

        // As in the CP, a new folder needs the permission to create one.
        if ($folder !== '.' && ! $container->folders('/', true)->contains($folder) && ! $user->can('create', [AssetFolder::class, $container])) {
            return ['status' => self::FORBIDDEN, 'message' => __('core-web::sync.push_asset_folder', ['folder' => $folder])];
        }

        return ['container' => $container];
    }
}
