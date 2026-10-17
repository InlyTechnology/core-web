<?php

namespace Inly\CoreWeb\Drafts;

use Illuminate\Support\Arr;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

/**
 * The values the CP's form leaves out of a save because their field is hidden
 * by its conditions (`if`, `unless`, …), at any depth. Statamic evaluates
 * conditions only in the CP's JavaScript, so this is a port of its
 * field-conditions Validator.js (statamic/cms 6.32) for the cases a save
 * depends on: a revealer only folds the form and never hides a value, a
 * field with `always_save` or `visibility: hidden` keeps its value, and a
 * custom condition, which runs JavaScript the server cannot, keeps it too.
 */
final class FieldConditions
{
    /** The condition keys in the order Statamic picks the first one set. */
    private const KEYS = ['if', 'if_any', 'show_when', 'show_when_any', 'unless', 'unless_any', 'hide_when', 'hide_when_any'];

    private const OPERATORS = ['equals', 'not', 'contains', 'contains_any', '===', '!==', '>', '>=', '<', '<=', 'custom'];

    private const ALIASES = ['is' => 'equals', '==' => 'equals', 'isnt' => 'not', '!=' => 'not', 'includes' => 'contains', 'includes_any' => 'contains_any'];

    private const NUMBER_OPERATORS = ['>', '>=', '<', '<='];

    /**
     * The dotted paths (`page_builder.2.limit`) of the values a CP save of
     * these values would omit.
     *
     * @param  array<string, mixed>  $values  pre-processed, as the CP's form holds them
     * @return list<string>
     */
    public static function omitted(Fields $fields, array $values): array
    {
        return self::walk($fields, $values, $values, '', []);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $root
     * @param  list<string>  $revealers  paths of the revealers in this scope and the ones around it
     * @return list<string>
     */
    private static function walk(Fields $fields, array $values, array $root, string $prefix, array $revealers): array
    {
        $all = $fields->all();
        $revealers = [...$revealers, ...$all->filter(fn (Field $field) => $field->type() === 'revealer')->keys()->map(fn ($handle) => self::join($prefix, (string) $handle))->all()];
        $omitted = [];

        foreach ($all as $handle => $field) {
            $path = self::join($prefix, (string) $handle);

            if ($field->type() === 'revealer') {
                continue;
            }

            if (self::omits($field, $values, $root, $path, $revealers)) {
                $omitted[] = $path;

                continue;
            }

            $value = $values[$handle] ?? null;
            $fieldtype = $field->fieldtype();

            if (! is_array($value)) {
                continue;
            }

            // A Bard is a Replicator too, with each set's values under its node's attrs.
            if ($fieldtype instanceof Bard) {
                foreach ($value as $index => $node) {
                    $set = is_array($node) && ($node['type'] ?? null) === 'set' ? ($node['attrs']['values'] ?? null) : null;

                    if (is_array($set) && is_string($set['type'] ?? null) && $fieldtype->flattenedSetsConfig()->has($set['type'])) {
                        array_push($omitted, ...self::walk($fieldtype->fields($set['type'], $index), $set, $root, "{$path}.{$index}.attrs.values", $revealers));
                    }
                }
            } elseif ($fieldtype instanceof Replicator) {
                foreach ($value as $index => $set) {
                    if (is_array($set) && is_string($set['type'] ?? null) && $fieldtype->flattenedSetsConfig()->has($set['type'])) {
                        array_push($omitted, ...self::walk($fieldtype->fields($set['type'], $index), $set, $root, "{$path}.{$index}", $revealers));
                    }
                }
            } elseif ($fieldtype instanceof Grid) {
                foreach ($value as $index => $row) {
                    if (is_array($row)) {
                        array_push($omitted, ...self::walk($fieldtype->fields($index), $row, $root, "{$path}.{$index}", $revealers));
                    }
                }
            } elseif ($fieldtype instanceof Group) {
                array_push($omitted, ...self::walk($fieldtype->fields(), $value, $root, $path, $revealers));
            }
        }

        return $omitted;
    }

    /**
     * ShowField.js's `omitValue`: the field fails its conditions once those on
     * a revealer are set aside.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $root
     * @param  list<string>  $revealers
     */
    private static function omits(Field $field, array $values, array $root, string $path, array $revealers): bool
    {
        if ($field->visibility() === 'hidden' || $field->get('always_save') === true) {
            return false;
        }

        $key = collect(self::KEYS)->first(fn (string $key) => ! blank($field->get($key)));

        if ($key === null || ! is_array($conditions = $field->get($key))) {
            return false;
        }

        $passOnAny = str_contains($key, 'any');
        $showOnPass = ! str_contains($key, 'unless') && ! str_contains($key, 'hide_when');
        $prefix = str_contains($path, '.') ? (string) preg_replace('~\.[^.]+$~', '', $path) : '';
        $results = [];

        foreach (self::parse($conditions, (string) $field->prefix()) as $condition) {
            if (in_array(self::absolute($condition['field'], $prefix, $path), $revealers, true)) {
                continue;
            }

            $passes = self::passes($condition, $values, $root, $path);

            if ($passes === null) {
                return false;
            }

            $results[] = $passes;
        }

        $passes = $passOnAny ? in_array(true, $results, true) : ! in_array(false, $results, true);

        return $showOnPass ? ! $passes : $passes;
    }

    /**
     * Converter.js's `fromBlueprint`: `{ field: 'operator value' }`, or a list
     * of them per field.
     *
     * @param  array<string, mixed>  $conditions
     * @return list<array{field: string, operator: string, value: string}>
     */
    private static function parse(array $conditions, string $prefix): array
    {
        $parsed = [];

        foreach ($conditions as $field => $rhs) {
            $field = (string) $field;
            $scoped = str_starts_with($field, '$root.') || str_starts_with($field, 'root.') || str_starts_with($field, '$parent.') ? $field : $prefix.$field;

            foreach (is_array($rhs) && array_is_list($rhs) ? $rhs : [$rhs] as $condition) {
                $condition = match (true) {
                    $condition === null => 'null',
                    $condition === '' => 'empty',
                    is_bool($condition) => $condition ? 'true' : 'false',
                    is_scalar($condition) => (string) $condition,
                    default => json_encode($condition),
                };
                $operator = 'equals';
                $value = $condition;

                foreach ([...self::OPERATORS, ...array_keys(self::ALIASES)] as $candidate) {
                    if (preg_match('~^'.preg_quote($candidate, '~').' [^=]~', $condition) === 1) {
                        $operator = self::ALIASES[$candidate] ?? $candidate;
                        $value = (string) preg_replace('~^'.preg_quote($candidate, '~').' *~', '', $value);
                    }
                }

                $parsed[] = ['field' => $scoped, 'operator' => $operator, 'value' => $value];
            }
        }

        return $parsed;
    }

    /**
     * Validator.js's `passesCondition`, with the JavaScript comparison it
     * evals; null for a custom condition, which only the CP can run.
     *
     * @param  array{field: string, operator: string, value: string}  $condition
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $root
     */
    private static function passes(array $condition, array $values, array $root, string $path): ?bool
    {
        $operator = match ($condition['operator']) {
            'equals' => '==',
            'not' => '!=',
            'contains' => 'includes',
            'contains_any' => 'includes_any',
            default => $condition['operator'],
        };

        if ($operator === 'custom') {
            return null;
        }

        $lhs = self::value($condition['field'], $values, $root, $path);
        $rhs = match ($condition['value']) {
            'null' => null,
            'true' => true,
            'false' => false,
            default => $condition['value'],
        };

        if (in_array($operator, self::NUMBER_OPERATORS, true)) {
            [$a, $b] = [self::number($lhs), self::number($rhs)];

            return match ($operator) {
                '>' => $a > $b,
                '>=' => $a >= $b,
                '<' => $a < $b,
                '<=' => $a <= $b,
            };
        }

        if ($operator === 'includes') {
            if (is_array($lhs)) {
                return in_array($rhs, $lhs, true);
            }

            return str_contains(in_array($lhs, [null, false, 0, 0.0, ''], true) ? '' : self::string($lhs), self::string($rhs, 'null'));
        }

        $lhs = $lhs === '' ? null : $lhs;

        if ($operator === 'includes_any') {
            $any = array_map('trim', explode(',', self::string($rhs, 'null')));

            if (is_array($lhs)) {
                return collect($lhs)->contains(fn (mixed $item) => in_array($item, $any, true));
            }

            // The JavaScript tests its pattern against the value as it would be eval()'d: a string quoted.
            $lhs = is_string($lhs) ? json_encode(trim($lhs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : self::string($lhs, 'null');

            return preg_match('~'.implode('|', array_map(fn (string $value) => preg_quote($value, '~'), $any)).'~', $lhs) === 1;
        }

        if ($rhs === 'empty') {
            [$lhs, $rhs] = [self::isEmpty($lhs), true];
        }

        [$lhs, $rhs] = [is_string($lhs) ? trim($lhs) : $lhs, is_string($rhs) ? trim($rhs) : $rhs];

        if (is_array($lhs) || is_object($lhs)) {
            return false;
        }

        return match ($operator) {
            '==' => self::looseEquals($lhs, $rhs),
            '!=' => ! self::looseEquals($lhs, $rhs),
            '===' => self::strictEquals($lhs, $rhs),
            '!==' => ! self::strictEquals($lhs, $rhs),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $root
     */
    private static function value(string $field, array $values, array $root, string $path): mixed
    {
        if (str_starts_with($field, '$parent.')) {
            $field = self::parent($field, $path);
        }

        if (preg_match('~^\$?root\.~', $field) === 1) {
            return Arr::get($root, (string) preg_replace('~^\$?root\.~', '', $field));
        }

        return Arr::get($values, $field);
    }

    /** ParentResolver.js: `$parent.handle` from a field's path as a `$root.` path. */
    private static function parent(string $field, string $path): string
    {
        $parentPath = self::parentPath($path, true);
        $field = (string) preg_replace('~^\$parent\.~', '', $field);

        while (str_starts_with($field, '$parent.')) {
            $parentPath = self::parentPath($parentPath);
            $field = (string) preg_replace('~^\$parent\.~', '', $field);
        }

        return '$root.'.self::join($parentPath, $field);
    }

    private static function parentPath(string $path, bool $removeCurrentField = false): string
    {
        $pattern = '~(.*?[^.]+)(\.[0-9]+)*\.[^.]*$~';

        if ($removeCurrentField || preg_match('~(\.[0-9]+)$~', $path) === 1) {
            $path = (string) preg_replace($pattern, '$1', $path, 1);
        }

        return str_contains($path, '.') ? (string) preg_replace($pattern, '$1$2', $path, 1) : '';
    }

    /** Validator.js's `relativeLhsToAbsoluteFieldPath`, to tell a condition on a revealer. */
    private static function absolute(string $field, string $prefix, string $path): string
    {
        if (str_starts_with($field, '$parent.')) {
            $field = self::parent($field, $path);
        }

        if (preg_match('~^\$?root\.~', $field) === 1) {
            return (string) preg_replace('~^\$?root\.~', '', $field);
        }

        return self::join($prefix, $field);
    }

    /** JavaScript's `==` between the scalars a condition compares. */
    private static function looseEquals(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_bool($a) || is_bool($b)) {
            return self::looseEquals(is_bool($a) ? (int) $a : $a, is_bool($b) ? (int) $b : $b);
        }

        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }

        return self::number($a) == self::number($b);
    }

    private static function strictEquals(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && ! is_string($a) && is_numeric($b) && ! is_string($b)) {
            return $a == $b;
        }

        return $a === $b;
    }

    /** JavaScript's `Number()`, NAN where it gives NaN. */
    private static function number(mixed $value): float
    {
        return match (true) {
            $value === null => 0.0,
            is_bool($value) => (float) $value,
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && trim($value) === '' => 0.0,
            is_string($value) && is_numeric(trim($value)) => (float) trim($value),
            default => NAN,
        };
    }

    /** JavaScript's `toString()` of a scalar, as `lhs.includes()` sees it. */
    private static function string(mixed $value, string $null = ''): string
    {
        return match (true) {
            $value === null => $null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * Validator.js's `isEmpty` of a value as it would be eval()'d: a boolean
     * has no keys and so is empty, a string is quoted and so never is.
     */
    private static function isEmpty(mixed $value): bool
    {
        return match (true) {
            $value === null, is_bool($value) => true,
            is_array($value) => $value === [],
            default => false,
        };
    }

    private static function join(string $prefix, string $handle): string
    {
        return $prefix === '' ? $handle : "{$prefix}.{$handle}";
    }
}
