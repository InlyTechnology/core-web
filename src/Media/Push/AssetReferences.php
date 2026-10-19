<?php

namespace Inly\CoreWeb\Media\Push;

use Closure;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

/**
 * The assets an entry's stored values point at, at any depth: `assets` fields (a path in the field's
 * container) and `asset::<container>::<path>` strings, the shape Bard images and links and link fields store.
 */
final class AssetReferences
{
    private const ID = '~^(statamic://)?asset::([^:/]+)::(.+)$~';

    /**
     * @param  array<string, mixed>  $values
     * @return list<string> `container::path` ids, each once
     */
    public static function collect(Fields $fields, array $values): array
    {
        $found = [];

        self::walk($fields, $values, function (string $container, string $path) use (&$found): ?string {
            $found["{$container}::{$path}"] = true;

            return null;
        });

        return array_keys($found);
    }

    /**
     * The values with each referenced id in $map (`container::path` => new path in that container) swapped.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    public static function rewrite(Fields $fields, array $values, array $map): array
    {
        if ($map === []) {
            return $values;
        }

        return self::walk($fields, $values, fn (string $container, string $path): ?string => $map["{$container}::{$path}"] ?? null);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  Closure(string, string): ?string  $visit  returns the new path, or null to keep it
     * @return array<string, mixed>
     */
    private static function walk(Fields $fields, array $values, Closure $visit): array
    {
        foreach ($fields->all() as $handle => $field) {
            if (! array_key_exists($handle, $values)) {
                continue;
            }

            $value = $values[$handle];
            $fieldtype = $field->fieldtype();

            if ($field->type() === 'assets') {
                $container = (string) ($field->get('container') ?? '');
                $values[$handle] = $container === '' ? $value : self::paths($value, $container, $visit);

                continue;
            }

            if (! is_array($value)) {
                $values[$handle] = self::strings($value, $visit);

                continue;
            }

            if ($fieldtype instanceof Bard) {
                $values[$handle] = self::bard($fieldtype, $value, $visit);
            } elseif ($fieldtype instanceof Replicator) {
                foreach ($value as $index => $set) {
                    $type = is_array($set) ? ($set['type'] ?? null) : null;

                    if (is_string($type) && $fieldtype->flattenedSetsConfig()->has($type)) {
                        $value[$index] = self::walk($fieldtype->fields($type), $set, $visit);
                    }
                }

                $values[$handle] = $value;
            } elseif ($fieldtype instanceof Grid) {
                foreach ($value as $index => $row) {
                    $value[$index] = is_array($row) ? self::walk($fieldtype->fields(), $row, $visit) : $row;
                }

                $values[$handle] = $value;
            } elseif ($fieldtype instanceof Group) {
                $values[$handle] = self::walk($fieldtype->fields(), $value, $visit);
            } else {
                $values[$handle] = self::strings($value, $visit);
            }
        }

        return $values;
    }

    /**
     * ProseMirror nodes: a set node's values are walked with the set's fields, everything else for ids.
     *
     * @param  array<mixed>  $nodes
     * @return array<mixed>
     */
    private static function bard(Bard $fieldtype, array $nodes, Closure $visit): array
    {
        foreach ($nodes as $index => $node) {
            $type = is_array($node) ? ($node['attrs']['values']['type'] ?? null) : null;

            if (($node['type'] ?? null) === 'set' && is_string($type) && $fieldtype->flattenedSetsConfig()->has($type)) {
                $nodes[$index]['attrs']['values'] = self::walk($fieldtype->fields($type), $node['attrs']['values'], $visit);
            } else {
                $nodes[$index] = self::strings($node, $visit);
            }
        }

        return $nodes;
    }

    private static function paths(mixed $value, string $container, Closure $visit): mixed
    {
        if (is_string($value) && $value !== '') {
            return $visit($container, $value) ?? $value;
        }

        if (is_array($value)) {
            return array_map(fn ($path) => self::paths($path, $container, $visit), $value);
        }

        return $value;
    }

    private static function strings(mixed $value, Closure $visit): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => self::strings($item, $visit), $value);
        }

        if (! is_string($value) || preg_match(self::ID, $value, $match) !== 1) {
            return $value;
        }

        $path = $visit($match[2], $match[3]);

        return $path === null ? $value : "{$match[1]}asset::{$match[2]}::{$path}";
    }
}
