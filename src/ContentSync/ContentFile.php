<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Support\Arr;
use Statamic\Facades\Collection;
use Statamic\Facades\YAML;
use Throwable;

/**
 * A synced file read and written the way the flat-file Stache does, so a
 * site on the Eloquent driver pulls as the same bytes: what only that driver
 * writes is dropped, key order is never a change, and neither is a save
 * stamp alone.
 */
final class ContentFile
{
    /** Stamped on every save: they say when, not what. */
    private const STAMPS = ['updated_at', 'updated_by'];

    /** What an entry's fileData() puts before its data, in that order. */
    private const ENTRY_HEAD = ['id', 'origin', 'published', 'blueprint'];

    /** What a term's fileData() puts after its data, in that order. */
    private const TERM_TAIL = ['blueprint', 'localizations'];

    /** The file without `parent` on an entry of a structured collection: its tree holds that, and the flat-file Stache never writes it. */
    public static function canonical(string $type, string $path, string $contents): string
    {
        if ($type !== 'entries' || ! str_contains($contents, 'parent:')) {
            return $contents;
        }

        $data = self::parse($path, $contents);
        $handle = ContentPaths::parse($type, $path)['handle'] ?? null;

        if ($data === null || ! array_key_exists('parent', $data) || $handle === null || ! Collection::find($handle)?->hasStructure()) {
            return $contents;
        }

        return self::dump($path, Arr::except($data, 'parent'));
    }

    /** Whether two versions of a file hold the same content, whatever their key order and save stamps. */
    public static function same(string $path, string $one, string $other): bool
    {
        $one = self::parse($path, $one);
        $other = self::parse($path, $other);

        return $one !== null && $other !== null && self::equal(self::unstamped($one), self::unstamped($other));
    }

    /**
     * The remote file as the Stache writes it after setting its values on the
     * local one: the local key order kept, new keys after it, the keys
     * fileData() places itself where it places them.
     */
    public static function merge(string $type, string $path, string $remote, ?string $local): string
    {
        $remoteData = self::parse($path, $remote);
        $localData = $local === null ? null : self::parse($path, $local);

        if ($remoteData === null || $localData === null) {
            return $remote;
        }

        $data = self::ordered($remoteData, $localData);

        if ($type === 'entries') {
            $data = self::pick($data, self::ENTRY_HEAD) + Arr::except($data, self::ENTRY_HEAD);
        }

        if ($type === 'terms') {
            $data = Arr::except($data, self::TERM_TAIL) + self::pick($data, self::TERM_TAIL);
        }

        return self::dump($path, $data);
    }

    /**
     * The keys there are, in the order given rather than the data's.
     *
     * @param  list<string>  $keys
     */
    private static function pick(array $data, array $keys): array
    {
        $picked = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $picked[$key] = $data[$key];
            }
        }

        return $picked;
    }

    /** @return array<array-key, mixed>|null */
    private static function parse(string $path, string $contents): ?array
    {
        try {
            $data = YAML::parse($contents);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** As ExistsAsFile::fileContents() writes it: front matter and body for a Markdown entry, plain YAML otherwise. */
    private static function dump(string $path, array $data): string
    {
        if (! str_ends_with($path, '.md')) {
            return YAML::dump($data);
        }

        if (! array_key_exists('content', $data)) {
            return YAML::dumpFrontMatter($data);
        }

        return $data['content'] === null
            ? YAML::dump($data)
            : YAML::dumpFrontMatter(Arr::except($data, 'content'), $data['content']);
    }

    private static function unstamped(array $data): array
    {
        $data = Arr::except($data, self::STAMPS);

        // A term's other sites are stamped inside its localizations.
        if (is_array($data['localizations'] ?? null)) {
            $data['localizations'] = array_map(fn (mixed $each) => is_array($each) ? Arr::except($each, self::STAMPS) : $each, $data['localizations']);
        }

        return $data;
    }

    private static function equal(mixed $one, mixed $other): bool
    {
        if (! is_array($one) || ! is_array($other)) {
            return $one === $other;
        }

        if (count($one) !== count($other) || array_is_list($one) !== array_is_list($other)) {
            return false;
        }

        foreach ($one as $key => $value) {
            if (! array_key_exists($key, $other) || ! self::equal($value, $other[$key])) {
                return false;
            }
        }

        return true;
    }

    /** The remote values in the local key order, a list item matched by its `id` where it has one: list order is content and stays the remote's. */
    private static function ordered(mixed $remote, mixed $local): mixed
    {
        if (! is_array($remote) || ! is_array($local)) {
            return $remote;
        }

        if (array_is_list($remote)) {
            $byId = collect($local)->filter(fn (mixed $each) => is_array($each) && is_scalar($each['id'] ?? null))->keyBy(fn (array $each) => (string) $each['id']);

            return array_map(fn (mixed $item, int $index) => self::ordered($item, is_array($item) && is_scalar($item['id'] ?? null) ? $byId->get((string) $item['id']) : ($local[$index] ?? null)), $remote, array_keys($remote));
        }

        if (array_is_list($local) && $local !== []) {
            return $remote;
        }

        $ordered = [];

        foreach (array_keys($local) as $key) {
            if (array_key_exists($key, $remote)) {
                $ordered[$key] = self::ordered($remote[$key], $local[$key]);
            }
        }

        return $ordered + $remote;
    }
}
