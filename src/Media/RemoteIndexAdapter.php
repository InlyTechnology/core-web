<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Statamic\Facades\YAML;
use Throwable;

/**
 * The live site's files as a read-only disk, from the index `content:pull`
 * brought (RemoteIndex): it lists them, answers each one's `.meta` from the
 * pulled data and reads its bytes from its public URL. It never writes; it
 * sits under a LayeredAdapter, whose layer takes every write.
 */
final class RemoteIndexAdapter implements FilesystemAdapter
{
    /** @var array{signature: string, entries: array<string, array{url: ?string, contents: ?string, size: ?int, last_modified: ?int, mime_type: ?string}>}|null */
    private ?array $built = null;

    public function __construct(
        private readonly RemoteIndex $index,
        private readonly string $fallbackUrl,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 60,
    ) {}

    /** The file's public URL on the live site; a path it does not hold resolves against the live container's URL. */
    public function getUrl(string $path): string
    {
        $file = $this->index->files()[self::key(rawurldecode($path))] ?? null;

        if (is_string($file['url'] ?? null)) {
            return $file['url'];
        }

        $base = rtrim($this->index->url() ?? $this->fallbackUrl, '/');

        return trim($path, '/') === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    public function fileExists(string $path): bool
    {
        return $this->entry($path) !== null;
    }

    public function directoryExists(string $path): bool
    {
        $prefix = self::key($path).'/';

        if ($prefix === '/') {
            return $this->entries() !== [];
        }

        foreach (array_keys($this->entries()) as $file) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function read(string $path): string
    {
        $entry = $this->entry($path) ?? throw UnableToReadFile::fromLocation($path, 'not in the pulled index');

        if ($entry['contents'] !== null) {
            return $entry['contents'];
        }

        if ($entry['url'] === null || ! in_array(parse_url($entry['url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw UnableToReadFile::fromLocation($path, 'the live site gave it no public URL');
        }

        try {
            $response = Http::connectTimeout($this->connectTimeout)->timeout($this->timeout)->get($entry['url']);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        if (! $response->successful()) {
            throw UnableToReadFile::fromLocation($path, "{$entry['url']} answered HTTP {$response->status()}");
        }

        return $response->body();
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, 'the live site\'s files are read only here');
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, 'the live site\'s files are read only here');
    }

    public function delete(string $path): void
    {
        throw ProductionFileLocked::at($path, 'deleted');
    }

    public function deleteDirectory(string $path): void
    {
        throw ProductionFileLocked::at($path, 'deleted');
    }

    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToWriteFile::atLocation($path, 'the live site\'s files are read only here');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw ProductionFileLocked::at($path, 'changed');
    }

    public function visibility(string $path): FileAttributes
    {
        $this->entry($path) ?? throw UnableToRetrieveMetadata::visibility($path, 'not in the pulled index');

        return new FileAttributes($path, null, 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        $attributes = $this->attributes($path) ?? throw UnableToRetrieveMetadata::mimeType($path, 'not in the pulled index');

        return $attributes->mimeType() !== null ? $attributes : throw UnableToRetrieveMetadata::mimeType($path, 'unknown');
    }

    public function lastModified(string $path): FileAttributes
    {
        $attributes = $this->attributes($path) ?? throw UnableToRetrieveMetadata::lastModified($path, 'not in the pulled index');

        return $attributes->lastModified() !== null ? $attributes : throw UnableToRetrieveMetadata::lastModified($path, 'unknown');
    }

    public function fileSize(string $path): FileAttributes
    {
        $attributes = $this->attributes($path) ?? throw UnableToRetrieveMetadata::fileSize($path, 'not in the pulled index');

        return $attributes->fileSize() !== null ? $attributes : throw UnableToRetrieveMetadata::fileSize($path, 'unknown');
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = self::key($path) === '' ? '' : self::key($path).'/';
        $directories = [];

        foreach (array_keys($this->entries()) as $file) {
            if ($prefix !== '' && ! str_starts_with($file, $prefix)) {
                continue;
            }

            $relative = substr($file, strlen($prefix));
            $segments = explode('/', $relative);

            if (! $deep && count($segments) > 1) {
                $directories[$prefix.$segments[0]] = true;

                continue;
            }

            for ($i = 1; $i < count($segments); $i++) {
                $directories[$prefix.implode('/', array_slice($segments, 0, $i))] = true;
            }

            yield $this->attributes($file);
        }

        foreach (array_keys($directories) as $directory) {
            yield new DirectoryAttributes($directory, 'public');
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw ProductionFileLocked::at($source, 'moved');
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToWriteFile::atLocation($destination, 'the live site\'s files are read only here');
    }

    private function attributes(string $path): ?FileAttributes
    {
        $entry = $this->entry($path);

        if ($entry === null) {
            return null;
        }

        return new FileAttributes(
            self::key($path),
            $entry['size'],
            'public',
            $entry['last_modified'],
            $entry['mime_type'] ?? (new ExtensionMimeTypeDetector)->detectMimeTypeFromPath($path),
        );
    }

    /** @return array{url: ?string, contents: ?string, size: ?int, last_modified: ?int, mime_type: ?string}|null */
    private function entry(string $path): ?array
    {
        return $this->entries()[self::key($path)] ?? null;
    }

    /**
     * Each pulled file and the `.meta` file beside it (dir/.meta/name.yaml), as Statamic reads a container.
     *
     * @return array<string, array{url: ?string, contents: ?string, size: ?int, last_modified: ?int, mime_type: ?string}>
     */
    private function entries(): array
    {
        $signature = $this->index->signature();

        if ($this->built !== null && $this->built['signature'] === $signature) {
            return $this->built['entries'];
        }

        $entries = [];

        foreach ($this->index->files() as $path => $file) {
            $meta = $file['meta'];
            $entries[$path] = [
                'url' => $file['url'],
                'contents' => null,
                'size' => is_numeric($meta['size'] ?? null) ? (int) $meta['size'] : null,
                'last_modified' => is_numeric($meta['last_modified'] ?? null) ? (int) $meta['last_modified'] : null,
                'mime_type' => is_string($meta['mime_type'] ?? null) ? $meta['mime_type'] : null,
            ];

            if ($meta !== null) {
                $contents = YAML::dump($meta);
                $directory = dirname($path);
                $entries[($directory === '.' ? '' : $directory.'/').'.meta/'.basename($path).'.yaml'] = [
                    'url' => null,
                    'contents' => $contents,
                    'size' => strlen($contents),
                    'last_modified' => $entries[$path]['last_modified'],
                    'mime_type' => 'application/yaml',
                ];
            }
        }

        $this->built = ['signature' => $signature, 'entries' => $entries];

        return $entries;
    }

    private static function key(string $path): string
    {
        return trim($path, '/');
    }
}
