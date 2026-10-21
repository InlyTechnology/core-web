<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Media\MediaDisks;
use InvalidArgumentException;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Site;
use Statamic\Facades\YAML;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Which files of content/ a push sends: those changed since the last pull or
 * push, narrowed by type and handle, site, path arguments and git's index or
 * last commit. `content:push` and `content:status` read it the same way, so
 * status lists exactly what a push with the same arguments sends.
 */
final class PushSelection
{
    public const STATE_NEW = 'new';

    public const STATE_CHANGED = 'changed';

    public const STATE_UNPUBLISHED = 'not_live';

    /**
     * @param  list<array{type: string, handle: ?string}>  $scopes
     * @param  list<string>  $paths  relative to content/, each a file, a folder or a `*` pattern
     */
    private function __construct(
        private LocalContent $local,
        private array $scopes,
        private ?string $site,
        private array $paths,
        private ?string $git,
        private bool $publish,
    ) {}

    /**
     * @param  list<string>  $paths
     *
     * @throws InvalidArgumentException for an option or path a push cannot take
     */
    public static function fromOptions(LocalContent $local, ?string $only, ?string $site, array $paths, bool $staged, bool $committed, bool $publish): self
    {
        if ($staged && $committed) {
            throw new InvalidArgumentException('Pass --staged or --committed, not both: --staged compares with git\'s index, --committed with the last commit.');
        }

        $git = match (true) {
            $staged => 'staged',
            $committed => 'committed',
            default => null,
        };

        if ($git !== null && ! $local->inGit()) {
            throw new InvalidArgumentException("--{$git} reads git, and content/ is not in a git checkout.");
        }

        return new self($local, self::scopes((string) $only), self::site($site), array_map(fn (string $path) => self::normalize($local, $path), $paths), $git, $publish);
    }

    /**
     * The files a push sends, by path, in path order.
     *
     * @param  array{items: array<string, string>, pushed: array<string, string>, published: array<string, string>, drafts: array<string, string>}  $lock
     * @return array<string, array{type: string, hash: string, state: string, draft: bool}>
     *
     * @throws InvalidArgumentException for a path argument that names no file a push carries
     */
    public function files(array $lock): array
    {
        $files = [];
        $matched = [];

        foreach ($this->candidates() as $path => $type) {
            $arguments = $this->matchingArguments($path);

            if ($arguments === false) {
                continue;
            }

            foreach ($arguments as $argument) {
                $matched[$argument] = true;
            }

            $hash = $this->local->hash($path);

            // A --publish push resends what an earlier push only drafted, until it is live.
            $sent = $this->publish ? ($lock['published'][$path] ?? null) : ($lock['pushed'][$path] ?? null);

            if ($hash === null || $hash === ($lock['items'][$path] ?? null) || $hash === $sent || ! $this->inGitAsNow($path)) {
                continue;
            }

            $files[$path] = [
                'type' => $type,
                'hash' => $hash,
                'state' => match (true) {
                    $hash === ($lock['pushed'][$path] ?? null) => self::STATE_UNPUBLISHED,
                    isset($lock['items'][$path]) => self::STATE_CHANGED,
                    default => self::STATE_NEW,
                },
                'draft' => isset($lock['drafts'][$path]),
            ];
        }

        $unmatched = array_values(array_diff($this->paths, array_keys($matched)));

        if ($unmatched !== []) {
            throw new InvalidArgumentException('No file a push carries matches '.implode(', ', array_map(fn (string $path) => "[{$path}]", $unmatched)).': a path is an existing file or folder under content/ (or a quoted * pattern) of the types and site the push is limited to; a deleted file is never pushed.');
        }

        ksort($files);

        return $files;
    }

    /**
     * Files pushed as they are now that no editor has made live yet, and that a plain push therefore skips.
     *
     * @param  array{items: array<string, string>, pushed: array<string, string>, published: array<string, string>}  $lock
     * @return list<string>
     */
    public function waiting(array $lock): array
    {
        $waiting = [];

        foreach ($this->candidates() as $path => $type) {
            if ($this->matchingArguments($path) === false || ! $this->inGitAsNow($path)) {
                continue;
            }

            $hash = $this->local->hash($path);

            if ($hash !== null && $hash === ($lock['pushed'][$path] ?? null) && $hash !== ($lock['items'][$path] ?? null) && $hash !== ($lock['published'][$path] ?? null)) {
                $waiting[] = $path;
            }
        }

        sort($waiting);

        return $waiting;
    }

    /**
     * The local layer of every asset disk that reads the site's index: the files uploaded here, by container.
     *
     * @return array<string, string> container handle => the layer's folder
     */
    public static function assetLayers(): array
    {
        if (! CoreWebServiceProvider::enabled('media')) {
            return [];
        }

        $layers = [];

        foreach (AssetContainer::all() as $container) {
            $disk = (string) $container->diskHandle();
            $root = config("filesystems.disks.{$disk}.upper.root");

            if (MediaDisks::isRemoteIndexed($disk) && is_string($root) && is_dir($root)) {
                $layers[$container->handle()] = $root;
            }
        }

        return $layers;
    }

    /**
     * The files uploaded here that a pushed file names, by `container::path`.
     *
     * @param  array<string, string>  $layers
     * @return array<string, array<string, mixed>>
     */
    public static function assetsIn(string $contents, array $layers): array
    {
        $found = [];

        foreach ($layers as $container => $root) {
            foreach (File::allFiles($root) as $file) {
                $path = str_replace('\\', '/', $file->getRelativePathname());

                if (! AssetPush::safePath($path) || ! str_contains($contents, $path)) {
                    continue;
                }

                $found["{$container}::{$path}"] = [
                    'container' => $container,
                    'path' => $path,
                    'file' => $file->getPathname(),
                    ...self::fingerprint($file),
                    ...self::meta($file),
                ];
            }
        }

        return $found;
    }

    /** @return array<string, string> every file of the scopes, path => type */
    private function candidates(): array
    {
        $found = [];

        foreach ($this->scopes as $scope) {
            $directory = rtrim($this->local->root(), '/').'/'.ContentPaths::directory($scope['type']);

            if (! is_dir($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                $path = ContentPaths::directory($scope['type']).'/'.str_replace('\\', '/', $file->getRelativePathname());

                if (ContentPaths::inScope($scope['type'], $path, $scope['handle'], $this->site) && ContentPush::typeOf($path) === $scope['type']) {
                    $found[$path] = $scope['type'];
                }
            }
        }

        return $found;
    }

    /**
     * Every path argument a file matches: empty when the push names no paths, false when it names some and none matches.
     *
     * @return list<string>|false
     */
    private function matchingArguments(string $path): array|false
    {
        if ($this->paths === []) {
            return [];
        }

        $matching = array_values(array_filter($this->paths, fn (string $argument) => str_contains($argument, '*')
            ? Str::is($argument, $path)
            : $path === $argument || str_starts_with($path, $argument.'/')));

        return $matching === [] ? false : $matching;
    }

    private function inGitAsNow(string $path): bool
    {
        return match ($this->git) {
            'staged' => $this->local->inGitAsNow($path, 'index'),
            'committed' => $this->local->inGitAsNow($path, 'HEAD'),
            default => true,
        };
    }

    /** A path argument relative to content/: one given from the project root (`content/...`) or as an absolute path is turned into that. */
    private static function normalize(LocalContent $local, string $path): string
    {
        $given = str_replace('\\', '/', trim($path));
        $root = rtrim(str_replace('\\', '/', $local->root()), '/').'/';
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $prefix = str_starts_with($root, $base) ? substr($root, strlen($base)) : basename($root).'/';
        $relative = preg_replace('#^(\./)+#', '', $given) ?? $given;

        if (str_starts_with($relative, $root)) {
            $relative = substr($relative, strlen($root));
        } elseif (str_starts_with($relative, $prefix)) {
            $relative = substr($relative, strlen($prefix));
        }

        $relative = rtrim($relative, '/');

        if ($relative.'/' === $prefix || $relative === '.') {
            $relative = '';
        }

        if ($relative === '' || ! ContentPaths::safe(str_replace('*', 'x', $relative))) {
            throw new InvalidArgumentException("[{$given}] is no file or folder under content/; leave the path out to push everything changed.");
        }

        return $relative;
    }

    /** @return list<array{type: string, handle: ?string}> */
    private static function scopes(string $only): array
    {
        $only = trim($only);

        if ($only === '') {
            return array_map(fn (string $type) => ['type' => $type, 'handle' => null], ContentPush::TYPES);
        }

        return array_values(array_map(function (string $part): array {
            [$type, $handle] = array_pad(explode(':', trim($part), 2), 2, null);

            if (! in_array($type, ContentPush::TYPES, true)) {
                throw new InvalidArgumentException("[{$type}] is not pushed; a push carries ".implode(', ', ContentPush::TYPES).'.');
            }

            return ['type' => $type, 'handle' => blank($handle) ? null : $handle];
        }, array_filter(explode(',', $only), fn (string $part) => trim($part) !== '')));
    }

    private static function site(?string $site): ?string
    {
        if (blank($site)) {
            return null;
        }

        if (Site::get($site) === null) {
            throw new InvalidArgumentException("No site [{$site}]. Known: ".Site::all()->keys()->implode(', ').'.');
        }

        return $site;
    }

    /** @return array{hash: string, size: int} of the bytes the site would store for the file */
    private static function fingerprint(SplFileInfo $file): array
    {
        $bytes = AssetPush::stored($file->getFilename(), (string) file_get_contents($file->getPathname()));

        return ['hash' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }

    /** @return array{alt?: string, focus?: string} the editable meta of a file uploaded here, from its `.meta` beside it */
    private static function meta(SplFileInfo $file): array
    {
        $meta = $file->getPath().'/.meta/'.$file->getFilename().'.yaml';

        try {
            $data = is_file($meta) ? (array) (YAML::parse((string) file_get_contents($meta))['data'] ?? []) : [];
        } catch (Throwable) {
            return [];
        }

        return array_filter(array_intersect_key($data, array_flip(AssetPush::META_KEYS)), fn (mixed $value) => is_string($value) && $value !== '');
    }
}
