<?php

namespace Inly\CoreWeb\Content\Feed;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;

/**
 * The served pages of every site, each localization its own item, oldest change first and then by id, after an
 * opaque cursor naming the last item Core received. Ordered in memory, since an entry no CP save has stamped
 * carries no `updated_at` for the Stache to index and its change time is the file's.
 */
class ContentFeed
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 200;

    public function __construct(protected ContentProjection $projection) {}

    /**
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function page(?CarbonInterface $updatedSince = null, ?string $cursor = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $after = $cursor === null ? null : self::decode($cursor);
        $since = $updatedSince?->getTimestamp();

        $items = $this->entries()
            ->map(fn (Entry $entry): array => ['entry' => $entry, 'at' => $entry->lastModified()->getTimestamp(), 'id' => (string) $entry->id()])
            ->filter(fn (array $item): bool => $since === null || $item['at'] >= $since)
            ->filter(fn (array $item): bool => $after === null || self::compare([$item['at'], $item['id']], $after) > 0)
            ->sort(fn (array $a, array $b): int => self::compare([$a['at'], $a['id']], [$b['at'], $b['id']]))
            ->values();

        $page = $items->take($limit);
        $last = $page->last();

        return [
            'data' => $page->map(fn (array $item): array => $this->projection->project($item['entry']))->values()->all(),
            'next_cursor' => $items->count() > $limit && $last !== null ? self::encode($last['at'], $last['id']) : null,
        ];
    }

    /** A served entry by id, in whichever site it belongs to; null for anything else. */
    public function find(string $id): ?Entry
    {
        $entry = Entries::find($id);

        return FeedCollections::serves($entry) ? $entry : null;
    }

    public static function isCursor(string $cursor): bool
    {
        return self::decode($cursor) !== null;
    }

    /** @return Collection<int, Entry> */
    protected function entries(): Collection
    {
        $collections = FeedCollections::handles();

        return $collections === [] ? collect() : Entries::query()->whereIn('collection', $collections)->get()->values();
    }

    /**
     * Time, then id byte by byte: PHP's own comparison reads numeric ids as numbers.
     *
     * @param  array{0: int, 1: string}  $a
     * @param  array{0: int, 1: string}  $b
     */
    private static function compare(array $a, array $b): int
    {
        return $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]);
    }

    private static function encode(int $at, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode([$at, $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{0: int, 1: string}|null */
    private static function decode(string $cursor): ?array
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($decoded) || ! is_int($decoded[0] ?? null) || ! is_string($decoded[1] ?? null)) {
            return null;
        }

        return [$decoded[0], $decoded[1]];
    }
}
