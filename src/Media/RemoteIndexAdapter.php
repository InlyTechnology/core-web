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
    /** read() holds the whole file in memory, so a file past this is refused there; readStream() spools any size to a temp file. */
    public const MAX_READ_BYTES = 32 * 1024 * 1024;

    private const CHUNK_BYTES = 1024 * 1024;

    /** @var array{signature: string, entries: array<string, array{url: ?string, contents: ?string, size: ?int, last_modified: ?int, mime_type: ?string}>}|null */
    private ?array $built = null;

    /** @var array<string, array{size: ?int, last_modified: ?int}> */
    private array $heads = [];

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
        $stream = $this->download($path, self::MAX_READ_BYTES);

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $path)
    {
        return $this->download($path, null);
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

        if ($attributes->lastModified() !== null) {
            return $attributes;
        }

        $lastModified = $this->head($path)['last_modified'] ?? $this->index->pulledAt();

        return $lastModified !== null
            ? new FileAttributes($attributes->path(), lastModified: $lastModified)
            : throw UnableToRetrieveMetadata::lastModified($path, 'unknown');
    }

    public function fileSize(string $path): FileAttributes
    {
        $attributes = $this->attributes($path) ?? throw UnableToRetrieveMetadata::fileSize($path, 'not in the pulled index');

        if ($attributes->fileSize() !== null) {
            return $attributes;
        }

        $size = $this->head($path)['size'] ?? $this->measure($path);

        return $size !== null ? new FileAttributes($attributes->path(), $size) : throw UnableToRetrieveMetadata::fileSize($path, 'unknown');
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

    /**
     * The file's bytes from its public URL, copied a chunk at a time into a temp stream that spills to disk.
     *
     * @return resource
     */
    private function download(string $path, ?int $limit)
    {
        $entry = $this->entry($path) ?? throw UnableToReadFile::fromLocation($path, 'not in the pulled index');
        $stream = fopen('php://temp', 'w+b');

        if ($entry['contents'] !== null) {
            fwrite($stream, $entry['contents']);
            rewind($stream);

            return $stream;
        }

        if ($entry['url'] === null) {
            fclose($stream);

            throw UnableToReadFile::fromLocation($path, 'the live site gave it no public URL on its own hosts');
        }

        try {
            // No redirects: the index's hosts were checked, wherever a redirect would lead was not.
            $response = Http::connectTimeout($this->connectTimeout)->timeout($this->timeout)->withoutRedirecting()->withOptions(['stream' => true])->get($entry['url']);

            if (! $response->successful()) {
                throw UnableToReadFile::fromLocation($path, "{$entry['url']} answered HTTP {$response->status()}");
            }

            if ($limit !== null && (int) $response->header('Content-Length') > $limit) {
                throw UnableToReadFile::fromLocation($path, "{$entry['url']} is larger than {$limit} bytes; read it as a stream");
            }

            $body = $response->toPsrResponse()->getBody();
            $copied = 0;

            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);
                $copied += strlen($chunk);

                if ($limit !== null && $copied > $limit) {
                    throw UnableToReadFile::fromLocation($path, "{$entry['url']} is larger than {$limit} bytes; read it as a stream");
                }

                fwrite($stream, $chunk);
            }
        } catch (Throwable $e) {
            fclose($stream);

            throw $e instanceof UnableToReadFile ? $e : UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        rewind($stream);

        return $stream;
    }

    /**
     * What the live site says of a file the index has no `.meta` for, which Statamic measures it by once and keeps in the layer.
     *
     * @return array{size: ?int, last_modified: ?int}
     */
    private function head(string $path): array
    {
        $url = $this->entry($path)['url'] ?? null;

        if ($url === null) {
            return ['size' => null, 'last_modified' => null];
        }

        return $this->heads[$url] ??= (function () use ($url): array {
            try {
                $response = Http::connectTimeout($this->connectTimeout)->timeout($this->timeout)->withoutRedirecting()->head($url);
            } catch (Throwable) {
                return ['size' => null, 'last_modified' => null];
            }

            $length = $response->header('Content-Length');
            $modified = $response->header('Last-Modified');

            return [
                'size' => $response->successful() && ctype_digit($length) ? (int) $length : null,
                'last_modified' => $response->successful() && $modified !== '' ? (strtotime($modified) ?: null) : null,
            ];
        })();
    }

    /** The size of the file's bytes, for a site that answers HEAD without a length. */
    private function measure(string $path): ?int
    {
        try {
            $stream = $this->download($path, null);
        } catch (UnableToReadFile) {
            return null;
        }

        $size = fstat($stream)['size'] ?? null;
        fclose($stream);

        return is_int($size) ? $size : null;
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
