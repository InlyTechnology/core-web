<?php

namespace Inly\CoreWeb\Translation;

use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

/**
 * What a translation owns in a field: `text` for a value it translates,
 * `shared` for one that always follows the original, and for a field that
 * holds others the shape of each (`sets` per set type, `rows` for a grid's
 * row, `group` for a group). A field's `translate: true|false` overrides
 * the default its type gives (core-web.translation.translated).
 */
final class FieldShape
{
    private const DEPTH = 6;

    /**
     * The top-level fields a synced translation keeps aligned: replicators and grids.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function synced(Blueprint $blueprint): array
    {
        $shapes = [];

        foreach ($blueprint->fields()->all() as $handle => $field) {
            if (in_array($field->type(), ['replicator', 'grid'], true)) {
                $shapes[(string) $handle] = self::of($field);
            }
        }

        return $shapes;
    }

    /** @return string|array<string, mixed> */
    public static function of(Field $field, int $depth = 0): string|array
    {
        $type = $field->type();

        if ($depth < self::DEPTH && $type === 'replicator') {
            $fieldtype = $field->fieldtype();
            $sets = [];

            foreach (array_keys($fieldtype->flattenedSetsConfig()->all()) as $set) {
                $sets[$set] = self::each($fieldtype->fields($set)->all(), $depth);
            }

            return ['sets' => $sets];
        }

        if ($depth < self::DEPTH && $type === 'grid') {
            return ['rows' => self::each($field->fieldtype()->fields()->all(), $depth)];
        }

        if ($depth < self::DEPTH && $type === 'group') {
            return ['group' => self::each($field->fieldtype()->fields()->all(), $depth)];
        }

        $translate = $field->config()['translate'] ?? null;

        if (is_bool($translate)) {
            return $translate ? 'text' : 'shared';
        }

        return in_array($type, (array) config('core-web.translation.translated', []), true) ? 'text' : 'shared';
    }

    /**
     * @param  iterable<Field>  $fields
     * @return array<string, string|array<string, mixed>>
     */
    private static function each(iterable $fields, int $depth): array
    {
        $shapes = [];

        foreach ($fields as $handle => $field) {
            $shapes[(string) $handle] = self::of($field, $depth + 1);
        }

        return $shapes;
    }
}
