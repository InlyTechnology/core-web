<?php

namespace Inly\CoreWeb\ContentSync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

/**
 * This checkout's content/ directory as a pull writes it, and content.lock
 * beside it: the hash of every file as last pulled, committed, so a pull can
 * tell a file the developer changed from one the site changed. A path is
 * only ever resolved inside content/.
 */
class LocalContent
{
    /** @var array<string, string>|null path => git blob id, as the checkout's HEAD has content/ */
    private ?array $committed = null;

    public function __construct(private string $root, private string $lockFile) {}

    public static function fromApp(): self
    {
        return new self(ContentPaths::root(), base_path('content.lock'));
    }

    public function root(): string
    {
        return $this->root;
    }

    public function lockFile(): string
    {
        return $this->lockFile;
    }

    /** The sha256 of a file as it is now; null when there is none. */
    public function hash(string $path): ?string
    {
        $file = $this->absolute($path);

        return is_file($file) ? hash_file('sha256', $file) : null;
    }

    /** A file's contents as they are now; null when there is none, or it is a symlink, which a pull never reads through. */
    public function read(string $path): ?string
    {
        $file = $this->absolute($path);

        return is_file($file) && ! is_link($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * Whether a file is exactly as the checkout's last commit has it: with no
     * lock entry yet (a first pull), that is a file nobody changed here.
     * False outside a git checkout.
     */
    public function matchesCommitted(string $path): bool
    {
        $blob = $this->committed()[$path] ?? null;
        $file = $this->absolute($path);

        if ($blob === null || ! is_file($file) || is_link($file)) {
            return false;
        }

        $contents = (string) file_get_contents($file);

        return hash(strlen($blob) === 64 ? 'sha256' : 'sha1', 'blob '.strlen($contents)."\0".$contents) === $blob;
    }

    /** Throws for a path a write or delete must not go through: one out of content/, or a symlink, which would carry it elsewhere. */
    public function assertWritable(string $path): void
    {
        $file = $this->absolute($path);

        if (is_link($file)) {
            throw new InvalidArgumentException("[{$path}] is a symlink, so a pull neither writes nor deletes it.");
        }
    }

    public function write(string $path, string $contents): void
    {
        $this->assertWritable($path);
        $file = $this->absolute($path);
        File::ensureDirectoryExists(dirname($file));
        $this->assertInside(dirname($file));
        File::put($file, $contents);
    }

    public function delete(string $path): void
    {
        $this->assertWritable($path);
        File::delete($this->absolute($path));
    }

    /**
     * `items`: each file's hash as last pulled here. `bases`: the site's own
     * hash of the version each file was last pulled from, which a push names
     * as what its change was made against. `pushed`: each file's hash as last
     * pushed, so a file unchanged since is not pushed again.
     *
     * @return array{remote: ?string, pulled_at: ?string, items: array<string, string>, bases: array<string, string>, pushed: array<string, string>}
     */
    public function lock(): array
    {
        $data = is_file($this->lockFile) ? json_decode((string) file_get_contents($this->lockFile), true) : null;

        if (! is_array($data)) {
            return ['remote' => null, 'pulled_at' => null, 'items' => [], 'bases' => [], 'pushed' => []];
        }

        return [
            'remote' => $data['remote'] ?? null,
            'pulled_at' => $data['pulled_at'] ?? null,
            'items' => self::hashes($data['items'] ?? []),
            'bases' => self::hashes($data['bases'] ?? []),
            'pushed' => self::hashes($data['pushed'] ?? []),
        ];
    }

    /**
     * After a pull: the lock's `pushed` is kept for the files the pull left as they were.
     *
     * @param  array<string, string>  $items
     * @param  array<string, string>  $bases
     */
    public function writeLock(string $remote, array $items, array $bases = []): void
    {
        $pushed = array_filter($this->lock()['pushed'], fn (string $hash, string $path) => ($items[$path] ?? null) !== $hash && $this->hash($path) === $hash, ARRAY_FILTER_USE_BOTH);

        $this->put($remote, CarbonImmutable::now()->toIso8601String(), $items, $bases, $pushed);
    }

    /** @param  array<string, string>  $pushed */
    public function writePushed(array $pushed): void
    {
        $lock = $this->lock();

        $this->put((string) $lock['remote'], $lock['pulled_at'], $lock['items'], $lock['bases'], $pushed);
    }

    /**
     * @param  array<string, string>  $items
     * @param  array<string, string>  $bases
     * @param  array<string, string>  $pushed
     */
    private function put(string $remote, ?string $pulledAt, array $items, array $bases, array $pushed): void
    {
        ksort($items);
        ksort($bases);
        ksort($pushed);

        File::put($this->lockFile, json_encode(array_filter([
            'remote' => $remote,
            'pulled_at' => $pulledAt,
            'items' => $items,
            'bases' => $bases === [] ? null : $bases,
            'pushed' => $pushed === [] ? null : $pushed,
        ], fn (mixed $value) => $value !== null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, string> */
    private static function hashes(mixed $map): array
    {
        return array_filter((array) $map, fn (mixed $hash, mixed $path) => is_string($path) && is_string($hash) && ContentPaths::safe($path), ARRAY_FILTER_USE_BOTH);
    }

    /** Statamic reads content/ through the Stache, which knows nothing of files written behind its back. */
    public function refreshStache(): void
    {
        Artisan::call('statamic:stache:refresh');
    }

    /**
     * One `git ls-tree` for the folder, compared by blob id, not a `git show` per file.
     *
     * @return array<string, string>
     */
    private function committed(): array
    {
        if ($this->committed !== null) {
            return $this->committed;
        }

        $this->committed = [];

        try {
            $result = is_dir($this->root) ? Process::path($this->root)->timeout(60)->run(['git', 'ls-tree', '-r', '-z', 'HEAD', '--', '.']) : null;
        } catch (Throwable) {
            return $this->committed;
        }

        if ($result === null || ! $result->successful()) {
            return $this->committed;
        }

        foreach (explode("\0", $result->output()) as $line) {
            if (preg_match('/^100(?:644|755) blob ([0-9a-f]{40}|[0-9a-f]{64})\t(.+)$/s', $line, $match) === 1) {
                $this->committed[$match[2]] = $match[1];
            }
        }

        return $this->committed;
    }

    private function absolute(string $path): string
    {
        if (! ContentPaths::safe($path)) {
            throw new InvalidArgumentException("[{$path}] is not a path inside content/.");
        }

        $file = rtrim($this->root, '/').'/'.$path;
        $this->assertInside($this->deepestExisting(dirname($file)));

        return $file;
    }

    /** A symlinked folder must not carry a write out of content/. */
    private function assertInside(string $directory): void
    {
        $root = realpath($this->root);
        $real = realpath($directory);

        if ($root === false || $real === false) {
            return;
        }

        if ($real !== $root && ! str_starts_with($real.'/', rtrim($root, '/').'/')) {
            throw new InvalidArgumentException("[{$directory}] leads outside content/.");
        }
    }

    private function deepestExisting(string $directory): string
    {
        while (! is_dir($directory) && dirname($directory) !== $directory) {
            $directory = dirname($directory);
        }

        return $directory;
    }
}
