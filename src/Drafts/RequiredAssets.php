<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Facades\AssetContainer;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

/** Required asset fields at any depth of replicators, grids and groups, by the path of their value. */
final class RequiredAssets
{
    /**
     * The required asset fields left empty.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function missing(Fields $fields, array $values): array
    {
        return self::where($fields, $values, fn (Field $field, mixed $value) => blank($value));
    }

    /**
     * The required asset fields naming only files that are on their way: a push's
     * check runs before it uploads them, so Statamic cannot find them yet.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $arriving  `container::path` of each file
     * @return list<string>
     */
    public static function arriving(Fields $fields, array $values, array $arriving): array
    {
        if ($arriving === []) {
            return [];
        }

        return self::where($fields, $values, function (Field $field, mixed $value) use ($arriving): bool {
            $paths = array_filter((array) $value, fn (mixed $path) => is_string($path) && $path !== '');

            if ($paths === []) {
                return false;
            }

            $container = $field->get('container') ?? (($all = AssetContainer::all())->count() === 1 ? $all->first()->handle() : null);

            foreach ($paths as $path) {
                if (! in_array(str_contains($path, '::') ? $path : "{$container}::{$path}", $arriving, true)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  callable(Field, mixed): bool  $matches
     * @return list<string>
     */
    private static function where(Fields $fields, array $values, callable $matches, string $path = ''): array
    {
        $found = [];

        foreach ($fields->all() as $handle => $field) {
            $at = ltrim("{$path}.{$handle}", '.');
            $value = $values[$handle] ?? null;
            $fieldtype = $field->fieldtype();

            if ($field->type() === 'assets') {
                if ($field->isRequired() && $matches($field, $value)) {
                    $found[] = $at;
                }

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            if ($fieldtype instanceof Replicator) {
                foreach (array_values($value) as $index => $set) {
                    $type = is_array($set) ? ($set['type'] ?? null) : null;

                    if (is_string($type) && ($set['enabled'] ?? true) && $fieldtype->flattenedSetsConfig()->has($type)) {
                        array_push($found, ...self::where($fieldtype->fields($type), $set, $matches, "{$at}.{$index}"));
                    }
                }
            } elseif ($fieldtype instanceof Grid) {
                foreach (array_values($value) as $index => $row) {
                    array_push($found, ...self::where($fieldtype->fields(), is_array($row) ? $row : [], $matches, "{$at}.{$index}"));
                }
            } elseif ($fieldtype instanceof Group) {
                array_push($found, ...self::where($fieldtype->fields(), $value, $matches, $at));
            }
        }

        return $found;
    }
}
