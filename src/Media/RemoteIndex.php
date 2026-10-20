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

    /**
     * Each index file as last parsed, by path, so a disk built again in the same process does not parse it again.
     *
     * @var array<string, array{stamp: string, data: ?array<string, mixed>}>
     */
    private static array $parsed = [];

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

    /** When the index was last written, the date a file the live site gives none for is taken to be from; null before a pull. */
    public function pulledAt(): ?int
    {
        $times = array_map(fn (string $file) => (int) @filemtime($file), $this->indexFiles());

        return $times === [] ? null : max($times);
    }

    /** The live container's own URL, the base Statamic builds an asset's URL on; null before a pull. */
    public function url(): ?string
    {
        return $this->load()['url'];
    }

    /** @return list<string> the paths a container's index holds now */
    public function paths(string $container): array
    {
        $data = $this->read($this->file($container)) ?? [];

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
        unset(self::$parsed[$file]);
        $this->loaded = null;
    }

    public function forget(string $container): void
    {
        File::delete($this->file($container));
        unset(self::$parsed[$this->file($container)]);
        $this->loaded = null;
    }

    /**
     * Removes the index of every container not in the list: one renamed or moved to another disk would otherwise list its files here still.
     *
     * @param  list<string>  $containers
     * @return list<string> the containers whose index was removed
     */
    public function prune(array $containers): array
    {
        $removed = [];

        foreach ($this->indexFiles() as $file) {
            $container = basename($file, '.json');

            if (! in_array($container, $containers, true)) {
                File::delete($file);
                unset(self::$parsed[$file]);
                $removed[] = $container;
            }
        }

        $this->loaded = null;

        return $removed;
    }

    /**
     * The containers whose index is here, each with whether it reads: an unreadable one lists nothing.
     *
     * @return array<string, bool>
     */
    public function status(): array
    {
        $status = [];

        foreach ($this->indexFiles() as $file) {
            $status[basename($file, '.json')] = $this->read($file) !== null;
        }

        return $status;
    }

    /**
     * The URL when it is http(s) on one of the hosts, else null: an index points only at the live site and its container's own host.
     *
     * @param  list<string>  $hosts
     */
    public static function allowedUrl(mixed $url, array $hosts): ?string
    {
        if (! is_string($url) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hosts, true) ? $url : null;
    }

    /**
     * The hosts an index's URLs may name: the live site's and the container's own.
     *
     * @return list<string>
     */
    public static function hosts(?string ...$urls): array
    {
        $hosts = [];

        foreach ($urls as $url) {
            $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

            if ($host !== '' && in_array(parse_url((string) $url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /** @return array{signature: string, files: array<string, array{url: ?string, meta: ?array<string, mixed>}>, url: ?string} */
    private function load(): array
    {
        $indexes = $this->indexFiles();
        $signature = implode('|', array_map(fn (string $file) => $file.':'.@filemtime($file).':'.@filesize($file), $indexes));

        if ($this->loaded !== null && $this->loaded['signature'] === $signature) {
            return $this->loaded;
        }

        $files = [];
        $url = null;

        foreach ($indexes as $index) {
            $data = $this->read($index) ?? [];
            $containerUrl = is_string($data['url'] ?? null) ? $data['url'] : null;
            $hosts = self::hosts(is_string($data['remote'] ?? null) ? $data['remote'] : null, $containerUrl);
            $url ??= self::allowedUrl($containerUrl, $hosts);

            foreach ((array) ($data['files'] ?? []) as $path => $entry) {
                if (is_string($path) && is_array($entry)) {
                    $files[$path] = [
                        // Checked again on reading: the file is on this disk, and anything that writes it could point it anywhere.
                        'url' => self::allowedUrl($entry['url'] ?? null, $hosts),
                        'meta' => is_array($entry['meta'] ?? null) ? $entry['meta'] : null,
                    ];
                }
            }
        }

        return $this->loaded = ['signature' => $signature, 'files' => $files, 'url' => $url];
    }

    /** @return list<string> */
    private function indexFiles(): array
    {
        $indexes = is_dir($this->root) ? glob($this->root.'/*.json') ?: [] : [];
        sort($indexes);
        clearstatcache();

        return $indexes;
    }

    /** @return array<string, mixed>|null null when the file is missing or no index */
    private function read(string $file): ?array
    {
        $stamp = @filemtime($file).':'.@filesize($file);

        if ((self::$parsed[$file]['stamp'] ?? null) === $stamp) {
            return self::$parsed[$file]['data'];
        }

        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = is_array($data) && is_array($data['files'] ?? null) ? $data : null;
        self::$parsed[$file] = ['stamp' => $stamp, 'data' => $data];

        return $data;
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
