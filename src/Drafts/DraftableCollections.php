<?php

namespace Inly\CoreWeb\Drafts;

/**
 * The collections a site lets its sources draft (core-web.drafts.collections),
 * keyed by handle: the blueprints a new entry may take, its default first
 * (none named: the collection's own), whether a new entry goes under a parent
 * in the collection's tree (`structured`), and the fields an entry is never
 * published without (`published_with`).
 */
final class DraftableCollections
{
    /** @return list<string> */
    public static function handles(): array
    {
        return array_map('strval', array_keys(self::all()));
    }

    public static function writable(string $collection): bool
    {
        return array_key_exists($collection, self::all());
    }

    /** @return list<string> */
    public static function blueprints(string $collection): array
    {
        return self::list($collection, 'blueprints');
    }

    public static function structured(string $collection): bool
    {
        return (bool) (self::all()[$collection]['structured'] ?? false);
    }

    /** @return list<string> */
    public static function publishedWith(string $collection): array
    {
        return self::list($collection, 'published_with');
    }

    /** @return list<string> */
    private static function list(string $collection, string $key): array
    {
        return array_values(array_map('strval', (array) (self::all()[$collection][$key] ?? [])));
    }

    /** @return array<string, array<string, mixed>> */
    private static function all(): array
    {
        return (array) config('core-web.drafts.collections', []);
    }
}
