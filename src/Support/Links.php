<?php

namespace Inly\CoreWeb\Support;

/**
 * A link's URL, with the `?query` or `#anchor` an editor adds beside an entry
 * link, so one entry serves several variants and the link still follows a slug
 * rename. Statamic's `link` fieldtype cannot hold the suffix itself: it reads
 * everything after `entry::` as the id.
 */
final class Links
{
    /** Any link shape (a `link` field, an Entry or Asset it unwrapped to, a URL string) as a URL, or null. */
    public static function url(mixed $value, mixed $suffix = null): ?string
    {
        $value = Values::unwrap($value);

        $url = match (true) {
            is_object($value) && method_exists($value, 'url') => (string) $value->url(),
            is_string($value) => $value,
            default => '',
        };

        return $url === '' ? null : self::withSuffix($url, $suffix);
    }

    /** `filter=x`, `?filter=x` and `&filter=x` join the query string; `#section` is appended as the fragment. */
    public static function withSuffix(string $url, mixed $suffix): string
    {
        $suffix = Values::unwrap($suffix);
        $suffix = is_string($suffix) ? trim($suffix) : '';

        if ($url === '' || $suffix === '') {
            return $url;
        }

        if (str_starts_with($suffix, '#')) {
            return $url.$suffix;
        }

        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $query = ltrim($suffix, '?&');

        return $base.(str_contains($base, '?') ? '&' : '?').$query.($fragment === null ? '' : '#'.$fragment);
    }
}
