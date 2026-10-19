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
    private static ?string $subject = null;

    /**
     * Runs a walk over one subject only (`entry:{id}`, `nav:{handle}:{site}`), so fixing a finding re-reads its own page, not the site.
     *
     * @template T
     *
     * @param  callable(): T  $walk
     * @return T
     */
    public static function scoped(string $subject, callable $walk): mixed
    {
        $previous = self::$subject;
        self::$subject = $subject;

        try {
            return $walk();
        } finally {
            self::$subject = $previous;
        }
    }

    /** Whether a walk reads the subject: every subject, or only the one it is scoped to. */
    public static function covers(string $subject): bool
    {
        return self::$subject === null || self::$subject === $subject;
    }

    /** @return iterable<EntryContract> */
    public static function entries(): iterable
    {
        $collections = config('core-web.checks.collections');

        if (self::$subject !== null) {
            $entry = str_starts_with(self::$subject, 'entry:') ? Entry::find(substr(self::$subject, 6)) : null;

            return $entry !== null && (! is_array($collections) || in_array($entry->collectionHandle(), $collections, true)) ? [$entry] : [];
        }

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
                if (self::$subject !== null && self::$subject !== "nav:{$nav->handle()}:{$site}") {
                    continue;
                }

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
