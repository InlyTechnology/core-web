<?php

namespace Inly\CoreWeb\Checks;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;

/**
 * The content a check reads: every entry of the checked collections
 * (core-web.checks.collections, all of them when null) as a draft would
 * build on it, and every navigation tree, each walked to its strings.
 */
final class ContentWalk
{
    /** @return iterable<EntryContract> */
    public static function entries(): iterable
    {
        $collections = config('core-web.checks.collections');
        $query = Entry::query();

        if (is_array($collections)) {
            $query->whereIn('collection', $collections);
        }

        return $query->get();
    }

    /** @return iterable<array{handle: string, site: string, tree: array<mixed>}> */
    public static function navTrees(): iterable
    {
        foreach (Nav::all() as $nav) {
            foreach ($nav->trees() as $site => $tree) {
                yield ['handle' => $nav->handle(), 'site' => (string) $site, 'tree' => $tree->tree()];
            }
        }
    }

    /**
     * Every string in a value, by dotted path, with the key it sits under.
     *
     * @return iterable<array{path: string, key: string, value: string}>
     */
    public static function strings(mixed $value, string $path = ''): iterable
    {
        if (is_string($value)) {
            yield ['path' => $path, 'key' => (string) last(explode('.', $path)), 'value' => $value];

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            yield from self::strings($child, ltrim("{$path}.{$key}", '.'));
        }
    }
}
