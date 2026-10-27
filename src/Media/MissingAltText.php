<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\Support\Assets;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\AssetContainer;
use Throwable;

/**
 * The images in a container with no alt text, counted by a queued scan and
 * kept until an asset in that container is saved, uploaded or deleted, or a
 * day has passed (meta written around Statamic fires no event). A dashboard
 * visit reads the count and never scans in its own request: reading every
 * asset's meta can copy files out of the bucket.
 */
final class MissingAltText
{
    /** The newest images listed by id; the count covers them all. */
    public const KEEP = 100;

    /** @return array{count: int, ids: list<string>, scanned_at: int}|null null until the first scan has run */
    public static function cached(string $container, string $field = 'alt'): ?array
    {
        $cached = Cache::get(self::key($container, $field));

        return is_array($cached) ? $cached : null;
    }

    /** @return array{count: int, ids: list<string>, scanned_at: int} */
    public static function scan(string $container, string $field = 'alt'): array
    {
        // Read before the scan, so an asset saved while it runs leaves this result under a key nobody reads.
        $key = self::key($container, $field);
        $missing = [];

        foreach (AssetContainer::find($container)?->queryAssets()->lazy() ?? [] as $asset) {
            if (self::lacksAlt($asset, $field)) {
                $missing[] = ['id' => $asset->id(), 'modified' => self::modified($asset)];
            }
        }

        usort($missing, fn (array $a, array $b): int => $b['modified'] <=> $a['modified'] ?: strcmp($a['id'], $b['id']));

        $result = [
            'count' => count($missing),
            'ids' => array_column(array_slice($missing, 0, self::KEEP), 'id'),
            'scanned_at' => now()->getTimestamp(),
        ];

        Cache::put($key, $result, now()->addDay());

        return $result;
    }

    /** Every field's count for the container goes, by moving it to a new generation. */
    public static function forget(string $container): void
    {
        Cache::forever(self::generationKey($container), self::generation($container) + 1);
    }

    private static function lacksAlt(Asset $asset, string $field): bool
    {
        try {
            if (! $asset->isImage() && ! $asset->isSvg()) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $alt = Assets::meta($asset, $field);

        return ! is_scalar($alt) || trim((string) $alt) === '';
    }

    private static function modified(Asset $asset): int
    {
        try {
            return (int) $asset->lastModified()?->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function key(string $container, string $field): string
    {
        return 'core-web:missing-alt:'.$container.':'.$field.':'.self::generation($container);
    }

    private static function generation(string $container): int
    {
        return (int) Cache::get(self::generationKey($container), 0);
    }

    private static function generationKey(string $container): string
    {
        return 'core-web:missing-alt:'.$container.':generation';
    }
}
