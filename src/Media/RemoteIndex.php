<?php

namespace Inly\CoreWeb\Media;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * The live site's asset index as `content:pull` last brought it for one
 * disk: one JSON file per asset container on that disk, each file's public
 * URL and its Statamic `.meta` data, never its bytes. It lives in a
 * gitignored folder under storage/ and is disposable: a pull replaces it.
 */
final class RemoteIndex
{
    /** @var array{signature: string, files: array<string, array{url: ?string, meta: ?array<string, mixed>}>, url: ?string}|null */
    private ?array $loaded = null;

    public function __construct(private readonly string $root) {}

    public static function root(): string
    {
        return storage_path('core-web/remote-assets');
    }

    public static function forDisk(string $disk): self
    {
        return new self(self::root().'/'.self::segment($disk));
    }

    public function directory(): string
    {
        return $this->root;
    }

    /**
     * Every pulled file on the disk, by path.
     *
     * @return array<string, array{url: ?string, meta: ?array<string, mixed>}>
     */
    public function files(): array
    {
        return $this->load()['files'];
    }

    /** Changes whenever a pull rewrites the index. */
    public function signature(): string
    {
        return $this->load()['signature'];
    }

    /** The live container's own URL, the base Statamic builds an asset's URL on; null before a pull. */
    public function url(): ?string
    {
        return $this->load()['url'];
    }

    /** @return list<string> the paths a container's index holds now */
    public function paths(string $container): array
    {
        $data = $this->read($this->file($container));

        return array_keys($data['files'] ?? []);
    }

    /** @param  array<string, array{url: ?string, meta: ?array<string, mixed>}>  $files */
    public function put(string $container, string $remote, ?string $url, array $files): void
    {
        File::ensureDirectoryExists($this->root, 0700);
        $this->ignoreInGit();

        ksort($files);
        $file = $this->file($container);
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        File::put($temporary, json_encode([
            'remote' => $remote,
            'container' => $container,
            'url' => $url,
            'pulled_at' => CarbonImmutable::now()->toIso8601String(),
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // A rename, so a request reading the index mid-pull sees the old one or the new one, never half of either.
        rename($temporary, $file);
        $this->loaded = null;
    }

    public function forget(string $container): void
    {
        File::delete($this->file($container));
        $this->loaded = null;
    }

    /** @return array{signature: string, files: array<string, array{url: ?string, meta: ?array<string, mixed>}>, url: ?string} */
    private function load(): array
    {
        $indexes = is_dir($this->root) ? glob($this->root.'/*.json') ?: [] : [];
        sort($indexes);
        clearstatcache();
        $signature = implode('|', array_map(fn (string $file) => $file.':'.@filemtime($file).':'.@filesize($file), $indexes));

        if ($this->loaded !== null && $this->loaded['signature'] === $signature) {
            return $this->loaded;
        }

        $files = [];
        $url = null;

        foreach ($indexes as $index) {
            $data = $this->read($index);
            $url ??= is_string($data['url'] ?? null) ? $data['url'] : null;

            foreach ((array) ($data['files'] ?? []) as $path => $entry) {
                if (is_string($path) && is_array($entry)) {
                    $files[$path] = [
                        'url' => is_string($entry['url'] ?? null) ? $entry['url'] : null,
                        'meta' => is_array($entry['meta'] ?? null) ? $entry['meta'] : null,
                    ];
                }
            }
        }

        return $this->loaded = ['signature' => $signature, 'files' => $files, 'url' => $url];
    }

    /** @return array<string, mixed> */
    private function read(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }

    private function file(string $container): string
    {
        return $this->root.'/'.self::segment($container).'.json';
    }

    /** storage/ ignores only its own subfolders in a Laravel app, so the store says it itself. */
    private function ignoreInGit(): void
    {
        $base = dirname($this->root);

        if (! is_file($base.'/.gitignore')) {
            File::put($base.'/.gitignore', "*\n");
        }
    }

    private static function segment(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new InvalidArgumentException("[{$name}] is not a disk or container handle.");
        }

        return $name;
    }
}
