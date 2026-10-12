<?php

namespace Inly\CoreWeb\Sitemaps;

use Carbon\CarbonImmutable;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Throwable;

/**
 * The sitemaps the registered snapshots declare (Snapshot::sitemap()), per
 * site: what /sitemap-core-{type}.xml lists and which of them robots.txt and
 * the sitemap index advertise. Reads go through SnapshotStore, so a render
 * never pulls unless the snapshot is completely cold.
 */
final class SnapshotSitemaps
{
    public function __construct(
        private readonly SnapshotRegistry $registry,
        private readonly SnapshotStore $store,
    ) {}

    public static function path(string $type): string
    {
        return "sitemap-core-{$type}.xml";
    }

    /** Whether the type has a sitemap that could list anything on the site. */
    public function declares(string $type, string $site): bool
    {
        return $this->registry->has($type) && $this->registry->get($type)->hasSitemap($site);
    }

    /**
     * The site's entries, `loc` absolute and `lastmod` an ISO 8601 string.
     * Empty for a type that declares none there.
     *
     * @return list<array{loc: string, lastmod: ?string, images: list<string>}>
     */
    public function entries(string $type, string $site, string $root): array
    {
        if (! $this->declares($type, $site)) {
            return [];
        }

        $snapshot = $this->registry->get($type);
        $documents = $this->store->documents($snapshot, $snapshot->partitionFor($site) ?: null);
        $root = rtrim($root, '/');

        return array_map(fn (array $entry) => [
            'loc' => str_starts_with($entry['loc'], '/') ? $root.$entry['loc'] : $entry['loc'],
            'lastmod' => self::lastmod($entry['lastmod']),
            'images' => $entry['images'],
        ], $snapshot->sitemapEntries($documents, $site));
    }

    /** Nothing cached and no pull succeeded: an empty sitemap would not be the truth. */
    public function isDegraded(string $type, string $site): bool
    {
        if (! $this->declares($type, $site)) {
            return false;
        }

        $snapshot = $this->registry->get($type);

        return $this->store->read($snapshot, $snapshot->partitionFor($site) ?: null)->degraded;
    }

    /**
     * Every type with at least one URL on the site, in registration order.
     * Each stops at its first entry, so robots.txt never builds a whole list.
     *
     * @return list<string>
     */
    public function types(string $site): array
    {
        return array_values(array_filter(
            $this->registry->types(),
            fn (string $type) => $this->hasEntries($type, $site),
        ));
    }

    private function hasEntries(string $type, string $site): bool
    {
        if (! $this->declares($type, $site)) {
            return false;
        }

        $snapshot = $this->registry->get($type);

        return $snapshot->hasSitemapEntries($this->store->documents($snapshot, $snapshot->partitionFor($site) ?: null), $site);
    }

    private static function lastmod(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toAtomString();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toAtomString();
        } catch (Throwable) {
            return null;
        }
    }
}
