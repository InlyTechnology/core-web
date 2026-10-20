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

    /** @return array{remote: ?string, pulled_at: ?string, items: array<string, string>} */
    public function lock(): array
    {
        $data = is_file($this->lockFile) ? json_decode((string) file_get_contents($this->lockFile), true) : null;

        if (! is_array($data)) {
            return ['remote' => null, 'pulled_at' => null, 'items' => []];
        }

        $items = array_filter((array) ($data['items'] ?? []), fn (mixed $hash, mixed $path) => is_string($path) && is_string($hash) && ContentPaths::safe($path), ARRAY_FILTER_USE_BOTH);

        return ['remote' => $data['remote'] ?? null, 'pulled_at' => $data['pulled_at'] ?? null, 'items' => $items];
    }

    /** @param  array<string, string>  $items */
    public function writeLock(string $remote, array $items): void
    {
        ksort($items);

        File::put($this->lockFile, json_encode([
            'remote' => $remote,
            'pulled_at' => CarbonImmutable::now()->toIso8601String(),
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
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
