<?php

namespace Inly\CoreWeb\Media;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Throwable;

/**
 * A disk outside production: the bucket's own files (lower) are read, never
 * written. Every write lands in the layer (upper), reads try the layer
 * first, and a file only the bucket holds cannot be deleted or moved, so a
 * staging site or a laptop shares production's files without touching them.
 * A layer file over a bucket file at the same path hides it; deleting the
 * layer's shows the bucket's again (there are no whiteouts).
 */
final class LayeredAdapter implements FilesystemAdapter
{
    /**
     * @param  Closure(string): string  $upperUrl
     * @param  Closure(string): string  $lowerUrl
     * @param  string|null  $containerUrl  the disk's own URL, when it is not the bucket's: the base Statamic builds Asset::url() on
     */
    public function __construct(
        private readonly FilesystemAdapter $upper,
        private readonly FilesystemAdapter $lower,
        private readonly Closure $upperUrl,
        private readonly Closure $lowerUrl,
        private readonly Cache $cache,
        private readonly string $indexKey,
        private readonly int $indexSeconds = 300,
        private readonly ?string $containerUrl = null,
    ) {}

    /** The layer's URL for a file it holds, else the bucket's; a URL path arrives encoded, as Statamic encodes it. */
    public function getUrl(string $path): string
    {
        if ($this->containerUrl !== null && self::key($path) === '') {
            return $this->containerUrl;
        }

        return $this->inLayer(rawurldecode($path)) ? ($this->upperUrl)($path) : ($this->lowerUrl)($path);
    }

    /**
     * Whether the layer holds the file, from an index listed once and kept a few minutes, since a bucket layer
     * would otherwise cost a request per image. Writes here update it in place; a failed listing is not kept.
     */
    public function inLayer(string $path): bool
    {
        return isset($this->index()[self::key($path)]);
    }

    /** The disk beneath the layer, which on a remote-indexed disk is itself the committed folder over the pulled index. */
    public function lower(): FilesystemAdapter
    {
        return $this->lower;
    }

    public function fileExists(string $path): bool
    {
        return $this->inLayer($path) || $this->lower->fileExists($path) || $this->upper->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->upper->directoryExists($path) || $this->lower->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upper->write($path, $contents, $config);
        $this->indexed($path, true);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upper->writeStream($path, $contents, $config);
        $this->indexed($path, true);
    }

    public function read(string $path): string
    {
        return $this->either($path, fn (FilesystemAdapter $adapter) => $adapter->read($path), UnableToReadFile::class);
    }

    public function readStream(string $path)
    {
        return $this->either($path, fn (FilesystemAdapter $adapter) => $adapter->readStream($path), UnableToReadFile::class);
    }

    public function delete(string $path): void
    {
        if ($this->upper->fileExists($path)) {
            $this->upper->delete($path);
            $this->indexed($path, false);

            return;
        }

        if ($this->lower->fileExists($path)) {
            throw ProductionFileLocked::at($path, 'deleted');
        }
    }

    public function deleteDirectory(string $path): void
    {
        if ($this->lower->directoryExists($path)) {
            throw ProductionFileLocked::at($path, 'deleted');
        }

        $this->upper->deleteDirectory($path);
        $this->cache->forget($this->indexKey);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->upper->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (! $this->upper->fileExists($path)) {
            throw ProductionFileLocked::at($path, 'changed');
        }

        $this->upper->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->either($path, fn (FilesystemAdapter $adapter) => $adapter->visibility($path), UnableToRetrieveMetadata::class);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->either($path, fn (FilesystemAdapter $adapter) => $adapter->mimeType($path), UnableToRetrieveMetadata::class);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->either($path, fn (FilesystemAdapter $adapter) => $adapter->lastModified($path), UnableToRetrieveMetadata::class);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->either($path, fn (FilesystemAdapter $adapter) => $adapter->fileSize($path), UnableToRetrieveMetadata::class);
    }

    /** Both, as one: a path in the layer hides the bucket's file at that path. */
    public function listContents(string $path, bool $deep): iterable
    {
        $seen = [];

        foreach ([$this->upper, $this->lower] as $adapter) {
            if (! $adapter->directoryExists($path) && $path !== '') {
                continue;
            }

            foreach ($adapter->listContents($path, $deep) as $item) {
                $key = ($item instanceof DirectoryAttributes ? 'dir:' : 'file:').trim($item->path(), '/');

                if (! isset($seen[$key])) {
                    $seen[$key] = true;

                    yield $item;
                }
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if (! $this->upper->fileExists($source)) {
            throw ProductionFileLocked::at($source, 'moved');
        }

        $this->upper->move($source, $destination, $config);
        $this->indexed($source, false);
        $this->indexed($destination, true);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if ($this->upper->fileExists($source)) {
            $this->upper->copy($source, $destination, $config);
        } else {
            $this->upper->writeStream($destination, $this->lower->readStream($source), $config);
        }

        $this->indexed($destination, true);
    }

    /**
     * The layer's answer for a file the index says it holds, else the bucket's; one the index has not seen yet
     * (written by another server since it was listed) falls back to the layer.
     *
     * @param  class-string<Throwable>  $missing
     */
    private function either(string $path, Closure $read, string $missing): mixed
    {
        if ($this->inLayer($path)) {
            return $read($this->upper);
        }

        try {
            return $read($this->lower);
        } catch (Throwable $e) {
            if (! $e instanceof $missing || ! $this->upper->fileExists($path)) {
                throw $e;
            }

            return $read($this->upper);
        }
    }

    /** @return array<string, true> */
    private function index(): array
    {
        $index = $this->cache->get($this->indexKey);

        if (is_array($index)) {
            return $index;
        }

        try {
            $index = collect($this->upper->listContents('', true))
                ->filter(fn (StorageAttributes $item) => $item->isFile())
                ->mapWithKeys(fn (StorageAttributes $item) => [self::key($item->path()) => true])
                ->all();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $this->cache->put($this->indexKey, $index, $this->indexSeconds);

        return $index;
    }

    private function indexed(string $path, bool $present): void
    {
        $index = $this->cache->get($this->indexKey);

        if (! is_array($index)) {
            return;
        }

        if ($present) {
            $index[self::key($path)] = true;
        } else {
            unset($index[self::key($path)]);
        }

        $this->cache->put($this->indexKey, $index, $this->indexSeconds);
    }

    private static function key(string $path): string
    {
        return trim($path, '/');
    }
}
