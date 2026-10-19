<?php

namespace Inly\CoreWeb\ContentSync;

use InvalidArgumentException;
use Statamic\Facades\Path;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

/**
 * Where each kind of synced content lives under content/, as the Stache lays
 * it out: the one definition both halves use, the remote to name an item's
 * file and the local checkout to check a path it was sent and to tell which
 * locked files a pull covers.
 */
final class ContentPaths
{
    /** Each synced type with its Stache store. */
    public const TYPES = [
        'entries' => 'entries',
        'terms' => 'terms',
        'globals' => 'global-variables',
        'collection-trees' => 'collection-trees',
        'nav-trees' => 'nav-trees',
    ];

    /** The default layout, for a store whose directory is configured outside content/. */
    private const DEFAULT_DIRECTORIES = [
        'entries' => 'collections',
        'terms' => 'taxonomies',
        'globals' => 'globals',
        'collection-trees' => 'trees/collections',
        'nav-trees' => 'trees/navigation',
    ];

    public static function root(): string
    {
        return base_path('content');
    }

    public static function assertType(string $type): void
    {
        if (! array_key_exists($type, self::TYPES)) {
            throw new InvalidArgumentException("[{$type}] is not a synced content type; use one of ".implode(', ', array_keys(self::TYPES)).'.');
        }
    }

    /** The type's directory relative to content/: its store's, when that sits under content/. */
    public static function directory(string $type): string
    {
        self::assertType($type);

        $store = rtrim(Path::tidy(Stache::store(self::TYPES[$type])->directory()), '/');
        $root = rtrim(Path::tidy(self::root()), '/').'/';

        return str_starts_with($store.'/', $root) && $store.'/' !== $root
            ? substr($store, strlen($root))
            : self::DEFAULT_DIRECTORIES[$type];
    }

    /** An item's file relative to content/, from its absolute path in the store; null when it is not in the store's directory. */
    public static function relative(string $type, string $absolute): ?string
    {
        self::assertType($type);

        $store = rtrim(Path::tidy(Stache::store(self::TYPES[$type])->directory()), '/').'/';
        $file = Path::tidy($absolute);

        if (! str_starts_with($file, $store)) {
            return null;
        }

        $path = self::directory($type).'/'.substr($file, strlen($store));

        return self::safe($path) ? $path : null;
    }

    /** A relative path with no way out of content/: no absolute path, drive, backslash, control character, `.` or `..` segment. */
    public static function safe(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, ':')) {
            return false;
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * The handle (collection, taxonomy, global set, tree) and site a type's file
     * belongs to, from where it sits; null for a path that is not one of the
     * type's files. A single-site layout has no site folder, so its site is the
     * default site; a term's file holds every site's localizations, so it has none.
     *
     * @return array{handle: string, site: ?string}|null
     */
    public static function parse(string $type, string $path): ?array
    {
        if (! self::safe($path)) {
            return null;
        }

        $prefix = self::directory($type).'/';

        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        $segments = explode('/', substr($path, strlen($prefix)));
        $file = end($segments);

        if (str_starts_with($file, '.')) {
            return null;
        }

        $default = Site::default()->handle();

        return match ($type) {
            'entries' => match (count($segments)) {
                2 => ['handle' => $segments[0], 'site' => $default],
                3 => ['handle' => $segments[0], 'site' => $segments[1]],
                default => null,
            },
            'terms' => count($segments) === 2 && str_ends_with($file, '.yaml') ? ['handle' => $segments[0], 'site' => null] : null,
            'globals' => count($segments) === 2 && str_ends_with($file, '.yaml') ? ['handle' => basename($file, '.yaml'), 'site' => $segments[0]] : null,
            default => match (true) {
                ! str_ends_with($file, '.yaml') => null,
                count($segments) === 1 => ['handle' => basename($file, '.yaml'), 'site' => $default],
                count($segments) === 2 => ['handle' => basename($file, '.yaml'), 'site' => $segments[0]],
                default => null,
            },
        };
    }

    /** Whether a path is one of the type's files, within the handle and site a pull or an export is limited to. */
    public static function inScope(string $type, string $path, ?string $handle = null, ?string $site = null): bool
    {
        $parsed = self::parse($type, $path);

        if ($parsed === null) {
            return false;
        }

        if ($handle !== null && $parsed['handle'] !== $handle) {
            return false;
        }

        return $site === null || $parsed['site'] === null || $parsed['site'] === $site;
    }
}
