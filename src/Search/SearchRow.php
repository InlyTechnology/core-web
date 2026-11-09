<?php

namespace Inly\CoreWeb\Search;

/**
 * A row as the search engines read it: an Objects API resource's attributes
 * by property name, with its `id`. What a search snapshot stores and what the
 * remote engine answers, so a site renders either the same way.
 */
final class SearchRow
{
    /**
     * @param  array<string, mixed>  $resource  one item of the Objects API's `data`
     * @return array<string, mixed>
     */
    public static function from(array $resource): array
    {
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];

        return ['id' => is_scalar($resource['id'] ?? null) ? (string) $resource['id'] : ''] + $attributes;
    }
}
