<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Fields\Blueprint;

/** Fields sent in the shape Statamic stores them: kept as they are, apart from handles the blueprint does not have. */
class StoredValues implements DraftValueNormaliser
{
    public function normalise(Blueprint $blueprint, string $collection, string $site, array $input, array $base): array
    {
        $known = $blueprint->fields()->all()->keys()->map(fn ($handle) => (string) $handle)->all();
        $data = array_intersect_key($input, array_flip($known));

        return [
            'data' => $data,
            'ignored' => array_values(array_diff(array_map('strval', array_keys($input)), $known)),
            'images_needed' => [],
            'unchanged' => array_values(array_map('strval', array_keys(array_filter($data, fn (mixed $value, string $handle) => array_key_exists($handle, $base) && $base[$handle] === $value, ARRAY_FILTER_USE_BOTH)))),
        ];
    }
}
