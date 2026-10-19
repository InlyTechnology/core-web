<?php

namespace Inly\CoreWeb\Support;

/** The hash of a stored value, with map keys sorted at every depth so the order fields were saved in does not change it. */
final class Fingerprint
{
    /** @param  array<mixed>  $value */
    public static function of(array $value): string
    {
        return hash('sha256', json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
