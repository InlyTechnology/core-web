<?php

namespace Inly\CoreWeb\Media\Push;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Assets\AssetContainer as Container;
use Statamic\Facades\Asset as Assets;
use Statamic\Facades\AssetContainer;

/**
 * The receiving end of pushed files. A pushed file waits in its container's holding folder, at
 * `<holding>/<first 12 of its sha256>/<the path it was pushed for>`, so the same file pushed twice lands once
 * and two files pushed for one path never meet; publishing an entry that points at it copies it out
 * (release()). Nothing here replaces or deletes a file already in the container.
 */
final class HeldAssets
{
    public const META = 'core_web_pushed';

    public static function holdingFolder(): string
    {
        return trim((string) config('core-web.media.push.holding_folder', '_pushed'), '/') ?: '_pushed';
    }

    public static function isHeld(string $path): bool
    {
        return str_starts_with(ltrim($path, '/'), self::holdingFolder().'/');
    }

    /**
     * What this site already has for a file pushed for $path: the same bytes at $path (`exists`), the same file
     * held from an earlier push (`held`), or nothing (`needed`, with the held path an upload would take).
     *
     * @return array{status: 'exists'|'held'|'needed', path: string}
     */
    public function lookup(Container $container, string $path, string $sha256, int $size): array
    {
        $disk = $this->disk($container);

        if ($this->same($disk, $path, $sha256, $size)) {
            return ['status' => 'exists', 'path' => $path];
        }

        $held = self::isHeld($path) ? $path : $this->heldPath($path, $sha256);

        return $this->same($disk, $held, $sha256, $size)
            ? ['status' => 'held', 'path' => $held]
            : ['status' => 'needed', 'path' => $this->heldPath($path, $sha256)];
    }

    /**
     * Holds an uploaded file for $path. The bytes must hash to $sha256, so a failed or altered upload never
     * lands under a name that claims otherwise.
     *
     * @param  resource  $stream  a rewindable stream
     * @return array{status: 'exists'|'held'|'uploaded', path: string}
     */
    public function hold(Container $container, string $path, string $sha256, $stream, string $pushedBy): array
    {
        $context = hash_init('sha256');
        $size = 0;

        while (! feof($stream)) {
            $chunk = (string) fread($stream, 1 << 20);
            $size += strlen($chunk);
            hash_update($context, $chunk);
        }

        if (! hash_equals($sha256, hash_final($context))) {
            throw new PushRefused('hash_mismatch', 'The file does not match its sha256.');
        }

        $found = $this->lookup($container, $path, $sha256, $size);

        if ($found['status'] !== 'needed') {
            return $found;
        }

        rewind($stream);

        if (! $this->disk($container)->writeStream($found['path'], $stream)) {
            throw new PushRefused('write_failed', 'The file could not be stored.');
        }

        $asset = $container->makeAsset($found['path']);
        $asset->set(self::META, ['for' => $path, 'sha256' => $sha256, 'by' => $pushedBy, 'at' => now()->toIso8601String()]);
        $asset->save();

        return ['status' => 'uploaded', 'path' => $found['path']];
    }

    /**
     * Copies a held file to the folder it was pushed for, under a free name there (`-2`, `-3` … when the name
     * is taken), and returns its path there. A copy, not a move: other drafts may still point at the held file,
     * and Statamic's reference updater never reaches working copies. A file released before is reused.
     */
    public function release(Asset $asset): ?string
    {
        $meta = $asset->get(self::META);

        if (! self::isHeld($asset->path()) || ! is_array($meta) || ! is_string($meta['for'] ?? null)) {
            return null;
        }

        $container = $asset->container();
        $disk = $this->disk($container);
        $released = $meta['released_to'] ?? null;

        if (is_string($released) && $disk->exists($released)) {
            return $released;
        }

        $target = $this->freePath($disk, $meta['for']);

        if (! $disk->copy($asset->path(), $target)) {
            throw new PushRefused('copy_failed', "[{$asset->path()}] could not be copied to [{$target}].");
        }

        $copy = $container->makeAsset($target);
        $copy->data(collect($asset->data()->all())->except(self::META)->all());
        $copy->save();

        $asset->set(self::META, [...$meta, 'released_to' => $target]);
        $asset->save();

        return $target;
    }

    public function container(string $handle): ?Container
    {
        $container = AssetContainer::find($handle);

        return $container instanceof Container ? $container : null;
    }

    public function find(string $container, string $path): ?Asset
    {
        $asset = Assets::find("{$container}::{$path}");

        return $asset instanceof Asset ? $asset : null;
    }

    private function heldPath(string $path, string $sha256): string
    {
        return self::holdingFolder().'/'.substr($sha256, 0, 12).'/'.ltrim($path, '/');
    }

    private function same(Filesystem $disk, string $path, string $sha256, int $size): bool
    {
        if (! $disk->exists($path) || $disk->size($path) !== $size) {
            return false;
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return false;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_equals($sha256, hash_final($context));
    }

    private function freePath(Filesystem $disk, string $path): string
    {
        $path = ltrim($path, '/');

        if (! $disk->exists($path)) {
            return $path;
        }

        $folder = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        for ($n = 2; ; $n++) {
            $candidate = ltrim(($folder === '.' ? '' : $folder.'/').$name.'-'.$n.($extension === '' ? '' : '.'.$extension), '/');

            if (! $disk->exists($candidate)) {
                return $candidate;
            }
        }
    }

    private function disk(Container $container): Filesystem
    {
        return Storage::disk($container->diskHandle());
    }
}
