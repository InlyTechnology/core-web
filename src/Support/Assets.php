<?php

namespace Inly\CoreWeb\Support;

use Statamic\Contracts\Assets\Asset;
use Throwable;

/**
 * An asset's meta read without letting a storage outage take the page down:
 * on a meta cache miss Statamic copies the file out of the bucket to measure
 * it, outside the disk's `throw => false`, so a bare `->get()` turns a bucket
 * 503 into a 500 for the whole page. Everything read this way is cosmetic, so
 * a failure is reported and the page keeps rendering.
 */
final class Assets
{
    /** A meta key (`alt`, `focus` …) of an asset or of any field shape holding one, or the fallback. */
    public static function meta(mixed $asset, string $key, mixed $fallback = null): mixed
    {
        $asset = self::asset($asset);

        return $asset === null ? $fallback : (self::guard(fn () => $asset->get($key)) ?? $fallback);
    }

    public static function mimeType(mixed $asset): ?string
    {
        $asset = self::asset($asset);
        $mime = $asset === null ? null : self::guard(fn () => $asset->mimeType());

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /** Null rather than 0 when unknown, so a caller hides the figure instead of printing a wrong one. */
    public static function size(mixed $asset): ?int
    {
        $asset = self::asset($asset);
        $size = $asset === null ? null : self::guard(fn () => $asset->size());

        return is_numeric($size) ? (int) $size : null;
    }

    private static function asset(mixed $value): ?Asset
    {
        $asset = Values::first($value);

        return $asset instanceof Asset ? $asset : null;
    }

    private static function guard(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
