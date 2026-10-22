<?php

namespace Inly\CoreWeb\Support;

use Illuminate\Support\Collection;
use Statamic\Contracts\Query\Builder;
use Statamic\Fields\ArrayableString;
use Statamic\Fields\Value;

/**
 * One field reads back as a Value, a Collection, a query builder, an array
 * or a single object depending on where it is read (a page-builder set, an
 * entry's view scope, a related entry), so templates normalise through here
 * rather than guessing the shape.
 */
final class Values
{
    /** A Value or an ArrayableString (an ArrayableLink is one) unwrapped, however deeply nested, down to what it holds. */
    public static function unwrap(mixed $value): mixed
    {
        while ($value instanceof Value || $value instanceof ArrayableString) {
            $value = $value->value();
        }

        return $value;
    }

    /**
     * Any relation shape as a flat Collection, empty slots dropped. A query
     * builder is fetched with get(), never first(): an ordered builder applies
     * the editor's manual order only after fetching.
     */
    public static function collect(mixed $value): Collection
    {
        $value = self::unwrap($value);

        if ($value instanceof Builder) {
            $value = $value->get();
        }

        if ($value instanceof Collection) {
            return $value->map(fn (mixed $item) => self::unwrap($item))->filter()->values();
        }

        if (is_array($value) && array_is_list($value)) {
            return collect($value)->map(fn (mixed $item) => self::unwrap($item))->filter()->values();
        }

        return collect(blank($value) ? [] : [$value]);
    }

    /** The first item of any relation shape, in the editor's order, or null. */
    public static function first(mixed $value): mixed
    {
        return self::collect($value)->first();
    }
}
