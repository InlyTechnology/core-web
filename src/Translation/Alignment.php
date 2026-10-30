<?php

namespace Inly\CoreWeb\Translation;

/**
 * The pure half of a synced translation: the original's blocks, rows,
 * order and shared values with the translation's own texts matched back
 * by row id, the structure two values are compared by, and the texts a
 * value holds by path. Paths name rows by id (`page_builder.hero.heading`),
 * so a moved block keeps its texts and their history.
 */
final class Alignment
{
    /**
     * @param  array<string, mixed>  $shape  a FieldShape with `sets` or `rows`
     * @return list<array<string, mixed>>
     */
    public static function align(mixed $origin, mixed $translation, array $shape): array
    {
        $ownRows = self::rows($translation);
        $own = [];

        foreach ($ownRows as $index => $row) {
            $own[self::id($row, $index)] = $row;
        }

        $aligned = [];

        foreach (self::rows($origin) as $index => $row) {
            $match = $own[self::id($row, $index)] ?? null;

            // Content saved before its rows had ids is matched by position, once the original's rows get theirs.
            if ($match === null && isset($ownRows[$index]) && ! self::hasId($ownRows[$index])) {
                $match = $ownRows[$index];
            }

            if ($match !== null && isset($shape['sets']) && ($match['type'] ?? null) !== ($row['type'] ?? null)) {
                $match = null;
            }

            $aligned[] = self::alignFields($row, $match, self::fieldsOf($row, $shape));
        }

        return $aligned;
    }

    /**
     * Which rows a value holds and in what order, all the way down, without their values.
     *
     * @param  array<string, mixed>  $shape
     * @return list<mixed>
     */
    public static function structure(mixed $value, array $shape): array
    {
        $structure = [];

        foreach (self::rows($value) as $index => $row) {
            $children = [];

            foreach (self::fieldsOf($row, $shape) as $handle => $field) {
                if (is_array($field)) {
                    $children[$handle] = self::nestedStructure($row[$handle] ?? null, $field);
                }
            }

            $structure[] = array_filter(['id' => self::id($row, $index), 'type' => $row['type'] ?? null, 'children' => $children]);
        }

        return $structure;
    }

    /**
     * Whether a structure has a reference's rows, in its order, all the way
     * down. A reference row without a stored id matches whatever id stands at
     * its position, since the control panel gives such a row a new id each
     * time a page is opened.
     *
     * @param  list<mixed>  $structure
     * @param  list<mixed>  $reference
     */
    public static function matches(array $structure, array $reference): bool
    {
        return $structure === self::adopt($reference, $structure);
    }

    /**
     * The translated texts a value holds, by path.
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    public static function texts(mixed $value, array $shape, string $prefix): array
    {
        $texts = [];

        foreach (self::rows($value) as $index => $row) {
            $texts += self::textsOf($row, self::fieldsOf($row, $shape), $prefix.'.'.self::id($row, $index));
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $own
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function alignFields(array $row, ?array $own, array $fields): array
    {
        foreach ($fields as $handle => $field) {
            if ($field === 'text') {
                // A text the translation's row lacks was cleared there, so the original's does not show through.
                if ($own !== null && array_key_exists($handle, $own)) {
                    $row[$handle] = $own[$handle];
                } elseif ($own !== null) {
                    unset($row[$handle]);
                }

                continue;
            }

            if (! is_array($field) || ! array_key_exists($handle, $row)) {
                continue;
            }

            if (isset($field['group'])) {
                $row[$handle] = is_array($row[$handle])
                    ? self::alignFields($row[$handle], is_array($own[$handle] ?? null) ? $own[$handle] : null, $field['group'])
                    : $row[$handle];

                continue;
            }

            $row[$handle] = self::align($row[$handle], $own[$handle] ?? null, $field);
        }

        return $row;
    }

    /** @param  array<string, mixed>  $field */
    private static function nestedStructure(mixed $value, array $field): mixed
    {
        if (! isset($field['group'])) {
            return self::structure($value, $field);
        }

        $children = [];

        foreach ($field['group'] as $handle => $inner) {
            if (is_array($inner) && is_array($value)) {
                $children[$handle] = self::nestedStructure($value[$handle] ?? null, $inner);
            }
        }

        return $children;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function textsOf(array $row, array $fields, string $prefix): array
    {
        $texts = [];

        foreach ($fields as $handle => $field) {
            if ($field === 'text') {
                if (array_key_exists($handle, $row) && ! blank($row[$handle])) {
                    $texts["{$prefix}.{$handle}"] = $row[$handle];
                }

                continue;
            }

            if (! is_array($field)) {
                continue;
            }

            $texts += isset($field['group'])
                ? (is_array($row[$handle] ?? null) ? self::textsOf($row[$handle], $field['group'], "{$prefix}.{$handle}") : [])
                : self::texts($row[$handle] ?? null, $field, "{$prefix}.{$handle}");
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    private static function fieldsOf(array $row, array $shape): array
    {
        if (isset($shape['sets'])) {
            return (array) ($shape['sets'][$row['type'] ?? ''] ?? []);
        }

        return (array) ($shape['rows'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param  array<array-key, mixed>  $reference
     * @param  array<array-key, mixed>  $structure
     * @return array<array-key, mixed>
     */
    private static function adopt(array $reference, array $structure): array
    {
        foreach ($reference as $key => $item) {
            if (! is_array($item) || ! is_array($structure[$key] ?? null)) {
                continue;
            }

            if (is_string($item['id'] ?? null) && str_starts_with($item['id'], '#') && isset($structure[$key]['id'])) {
                $item['id'] = $structure[$key]['id'];
            }

            $reference[$key] = self::adopt($item, $structure[$key]);
        }

        return $reference;
    }

    /** @param  array<string, mixed>  $row */
    private static function hasId(array $row): bool
    {
        return filled($row['id'] ?? $row['_id'] ?? null);
    }

    /** A row's id as stored (`id`) or as the control panel posts it (`_id`); its position when it has neither. */
    private static function id(array $row, int $index): string
    {
        $id = $row['id'] ?? $row['_id'] ?? null;

        return filled($id) ? (string) $id : "#{$index}";
    }
}
