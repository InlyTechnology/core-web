<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

/** The paths of required asset fields left empty, at any depth of replicators, grids and groups. */
final class RequiredAssets
{
    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function missing(Fields $fields, array $values, string $path = ''): array
    {
        $missing = [];

        foreach ($fields->all() as $handle => $field) {
            $at = ltrim("{$path}.{$handle}", '.');
            $value = $values[$handle] ?? null;
            $fieldtype = $field->fieldtype();

            if ($field->type() === 'assets') {
                if ($field->isRequired() && blank($value)) {
                    $missing[] = $at;
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
                        array_push($missing, ...self::missing($fieldtype->fields($type), $set, "{$at}.{$index}"));
                    }
                }
            } elseif ($fieldtype instanceof Grid) {
                foreach (array_values($value) as $index => $row) {
                    array_push($missing, ...self::missing($fieldtype->fields(), is_array($row) ? $row : [], "{$at}.{$index}"));
                }
            } elseif ($fieldtype instanceof Group) {
                array_push($missing, ...self::missing($fieldtype->fields(), $value, $at));
            }
        }

        return $missing;
    }
}
