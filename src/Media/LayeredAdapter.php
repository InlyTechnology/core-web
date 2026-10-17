<?php

namespace Inly\CoreWeb\Media;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToSetVisibility;
use Throwable;

/**
 * A disk outside production: the bucket's own files (lower) are read, never
 * written. Every write lands in the layer (upper), reads try the layer
 * first, and a file only the bucket holds cannot be deleted or moved, so a
 * staging site or a laptop shares production's files without touching them.
 */
final class LayeredAdapter implements FilesystemAdapter
{
    /**
     * @param  Closure(string): string  $upperUrl
     * @param  Closure(string): string  $lowerUrl
     */
    public function __construct(
        private readonly FilesystemAdapter $upper,
        private readonly FilesystemAdapter $lower,
        private readonly Closure $upperUrl,
        private readonly Closure $lowerUrl,
        private readonly Cache $cache,
        private readonly string $indexKey,
        private readonly int $indexSeconds = 300,
    ) {}

    /** The layer's URL for a file it holds, else the bucket's. */
    public function getUrl(string $path): string
    {
        return $this->inLayer($path) ? ($this->upperUrl)($path) : ($this->lowerUrl)($path);
    }

    /** Whether the layer holds the file, from an index listed once and kept a few minutes: a bucket layer would otherwise cost a request per image. */
    public function inLayer(string $path): bool
    {
        $index = $this->cache->remember($this->indexKey, $this->indexSeconds, function (): array {
            try {
                return collect($this->upper->listContents('', true))
                    ->filter(fn (StorageAttributes $item) => $item->isFile())
                    ->mapWithKeys(fn (StorageAttributes $item) => [ltrim($item->path(), '/') => true])
                    ->all();
            } catch (Throwable $e) {
                report($e);

                return [];
            }
        });

        return isset($index[ltrim($path, '/')]);
    }

    public function fileExists(string $path): bool
    {
        return $this->upper->fileExists($path) || $this->lower->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->upper->directoryExists($path) || $this->lower->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upper->write($path, $contents, $config);
        $this->forgetIndex();
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upper->writeStream($path, $contents, $config);
        $this->forgetIndex();
    }

    public function read(string $path): string
    {
        return $this->upper->fileExists($path) ? $this->upper->read($path) : $this->lower->read($path);
    }

    public function readStream(string $path)
    {
        return $this->upper->fileExists($path) ? $this->upper->readStream($path) : $this->lower->readStream($path);
    }

    public function delete(string $path): void
    {
        if ($this->upper->fileExists($path)) {
            $this->upper->delete($path);
            $this->forgetIndex();

            return;
        }

        if ($this->lower->fileExists($path)) {
            throw UnableToDeleteFile::atLocation($path, 'The file is production\'s; outside production only files added here can be deleted.');
        }
    }

    public function deleteDirectory(string $path): void
    {
        if ($this->lower->directoryExists($path)) {
            throw UnableToDeleteDirectory::atLocation($path, 'The folder holds production\'s files; outside production only folders added here can be deleted.');
        }

        $this->upper->deleteDirectory($path);
        $this->forgetIndex();
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->upper->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (! $this->upper->fileExists($path)) {
            throw UnableToSetVisibility::atLocation($path, 'The file is production\'s; outside production only files added here can change.');
        }

        $this->upper->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->upper->fileExists($path) ? $this->upper->visibility($path) : $this->lower->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->upper->fileExists($path) ? $this->upper->mimeType($path) : $this->lower->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->upper->fileExists($path) ? $this->upper->lastModified($path) : $this->lower->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->upper->fileExists($path) ? $this->upper->fileSize($path) : $this->lower->fileSize($path);
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
            throw UnableToMoveFile::because('The file is production\'s; outside production only files added here can be moved.', $source, $destination);
        }

        $this->upper->move($source, $destination, $config);
        $this->forgetIndex();
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if ($this->upper->fileExists($source)) {
            $this->upper->copy($source, $destination, $config);
        } else {
            $this->upper->writeStream($destination, $this->lower->readStream($source), $config);
        }

        $this->forgetIndex();
    }

    private function forgetIndex(): void
    {
        $this->cache->forget($this->indexKey);
    }
}
