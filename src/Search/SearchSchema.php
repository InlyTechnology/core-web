<?php

namespace Inly\CoreWeb\Search;

/**
 * What an object's `/schema` description says about searching it: the search
 * it declares, its filters and sorts, and for each filter key the rule it
 * matches by, read as Core's search contract publishes it
 * (docs/api/search-contract.md in inly-core). A key whose rule the contract
 * does not name has none here, and only Core can answer a request using it.
 */
final class SearchSchema
{
    /** Yes or no. */
    public const BOOLEAN = 'boolean';

    /** One bound per list item, such as `>=200000`. */
    public const RANGE = 'range';

    /** The value exactly: an option, or a value in use. */
    public const EXACT = 'exact';

    /** Part of the text, case-insensitively, accents kept. */
    public const PARTIAL = 'partial';

    /** Part of a plate, compared without spaces or dashes. */
    public const PLATE = 'plate';

    private const TEXT_TYPES = ['string', 'text', 'url'];

    /** @var array<string, string> filter key → field name */
    private array $fieldByKey = [];

    /** @param  array<string, mixed>  $attributes  the `/schema` endpoint's `data.attributes` */
    private function __construct(private readonly array $attributes)
    {
        foreach ($this->fields() as $name => $field) {
            $key = $field['filter_key'] ?? null;

            if (is_string($key) && $key !== '' && ! isset($this->fieldByKey[$key])) {
                $this->fieldByKey[$key] = (string) $name;
            }
        }
    }

    /** @param  array<string, mixed>  $attributes */
    public static function fromDescribe(array $attributes): self
    {
        return new self($attributes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /** @return array<string, array<string, mixed>> */
    public function fields(): array
    {
        return array_filter((array) ($this->attributes['fields'] ?? []), is_array(...));
    }

    /** The name of the field a filter key filters, or null when no field names it. */
    public function fieldFor(string $key): ?string
    {
        return $this->fieldByKey[$key] ?? null;
    }

    /** Whether rows carry the field: every row carries its id. */
    public function onRows(string $field): bool
    {
        return $field === 'id' || in_array($field, $this->list('fields_available'), true);
    }

    public function filters(string $key): bool
    {
        return in_array($key, $this->list('filters_available'), true);
    }

    public function nullable(string $key): bool
    {
        return in_array($key, $this->list('filters_nullable'), true);
    }

    public function sortable(string $key): bool
    {
        return in_array($key, $this->list('sorts_available'), true);
    }

    public function facetable(string $key): bool
    {
        return in_array($key, $this->list('facets_available'), true);
    }

    /**
     * The rule a filter key matches by, as the search contract publishes it,
     * or null when a client cannot apply it: a filter only Core runs
     * (`filter_custom`), one on a field the rows do not carry, or a type the
     * contract names no rule for (a relation, a list, a date with a time).
     */
    public function rule(string $key): ?string
    {
        $name = $this->fieldFor($key);
        $field = $name === null ? null : $this->fields()[$name];

        if ($field === null || ! $this->filters($key) || ($field['filter_custom'] ?? false) || ! $this->onRows($name) || str_contains($name, '.')) {
            return null;
        }

        $type = $this->filterType($key);

        return match (true) {
            $type === 'boolean' => self::BOOLEAN,
            in_array($key, $this->list('filters_with_operators'), true) => self::RANGE,
            filled($field['options'] ?? null) || isset($field['options_from_values']) => self::EXACT,
            in_array($type, self::TEXT_TYPES, true) => self::PARTIAL,
            $type === 'license_plate' => self::PLATE,
            default => null,
        };
    }

    /** The type a filter key's values are read as, '' when no field names it. */
    public function filterType(string $key): string
    {
        $name = $this->fieldFor($key);
        $field = $name === null ? [] : $this->fields()[$name];
        $type = $field['filter_type'] ?? $field['type'] ?? null;

        return is_string($type) ? $type : '';
    }

    /** A field's type, '' when the description names none. */
    public function type(string $field): string
    {
        $type = $this->fields()[$field]['type'] ?? null;

        return is_string($type) ? $type : '';
    }

    /** Whether a field orders by code of its own (`sort_custom`), which only Core runs. */
    public function sortsByCode(string $field): bool
    {
        return (bool) ($this->fields()[$field]['sort_custom'] ?? false);
    }

    /** Whether a key's options are a list the object declares, rather than the values in use. */
    public function declaresOptions(string $key): bool
    {
        $name = $this->fieldFor($key);

        return $name !== null && ! isset($this->fields()[$name]['options_from_values']) && filled($this->fields()[$name]['options'] ?? null);
    }

    /** Whether each item of a `[]` list is one value whole, commas and all: a value in use. */
    public function keepsListItemsWhole(string $key): bool
    {
        $name = $this->fieldFor($key);

        return $name !== null && isset($this->fields()[$name]['options_from_values']);
    }

    /** How many of its most frequent values a values-in-use key's facet counts, null for any other key. */
    public function valuesInUseLimit(string $key): ?int
    {
        $name = $this->fieldFor($key);
        $limit = $name === null ? null : ($this->fields()[$name]['options_from_values'] ?? null);

        return is_numeric($limit) ? (int) $limit : null;
    }

    /**
     * The label each option value reads as, by value.
     *
     * @return array<string, string>
     */
    public function labels(string $key): array
    {
        $name = $this->fieldFor($key);
        $labels = [];

        foreach ((array) ($name === null ? [] : ($this->fields()[$name]['options'] ?? [])) as $option) {
            if (is_array($option) && is_scalar($option['value'] ?? null)) {
                $value = self::string($option['value']);
                $labels[$value] = is_scalar($option['label'] ?? null) ? (string) $option['label'] : $value;
            }
        }

        return $labels;
    }

    /** `fields`, `index` or `custom`, null when the object declares no search. */
    public function searchMode(): ?string
    {
        $mode = $this->search()['mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }

    /** @return list<string> */
    public function searchFields(): array
    {
        return array_values(array_filter((array) ($this->search()['fields'] ?? []), is_string(...)));
    }

    public function ignoresAccents(): bool
    {
        return (bool) ($this->search()['ignore_accents'] ?? false);
    }

    public function synonyms(): SearchSynonyms
    {
        return new SearchSynonyms(array_filter((array) ($this->search()['synonyms'] ?? []), is_array(...)), $this->ignoresAccents());
    }

    /** Whether the object's search has any synonyms. */
    public function hasSynonyms(): bool
    {
        return filled($this->search()['synonyms'] ?? null);
    }

    /** The sort Core lists by when a request names none, as a `sort` value. */
    public function defaultSort(): ?string
    {
        $sort = $this->attributes['default_sort'] ?? null;

        return is_string($sort) && $sort !== '' ? $sort : null;
    }

    public function defaultPageSize(): int
    {
        return max(1, (int) ($this->attributes['page']['default_size'] ?? 25));
    }

    public function maxPageSize(): int
    {
        return max(1, (int) ($this->attributes['page']['max_size'] ?? 100));
    }

    /** A value as a filter and a facet read it: true and false as `1` and `0`, as Core groups them. */
    public static function string(mixed $value): string
    {
        return match (true) {
            $value === true => '1',
            $value === false => '0',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /** @return array<string, mixed> */
    private function search(): array
    {
        return (array) ($this->attributes['search'] ?? []);
    }

    /** @return list<string> */
    private function list(string $key): array
    {
        return array_values(array_map(strval(...), array_filter((array) ($this->attributes[$key] ?? []), is_scalar(...))));
    }
}
