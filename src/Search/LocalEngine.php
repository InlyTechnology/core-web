<?php

namespace Inly\CoreWeb\Search;

/**
 * Runs a search over the site's own copy of an object's rows, as the Objects
 * API runs it over the database: the rules Core's search contract publishes
 * for the term, its synonyms, each filter, the sort, the page and the facet
 * counts. It answers only what those rules cover; refusal() names anything
 * else, which goes to Core instead.
 *
 * A row is the object's attributes by property name, with its `id`.
 */
final class LocalEngine
{
    private const NOT = ':not';

    private const EMPTY = ':empty';

    private const NOT_EMPTY = ':not_empty';

    private const OPERATORS = ['=', '<', '>', '<=', '>=', '<>'];

    /** Types whose values a number comparison orders and matches as the database does. */
    private const NUMBER_TYPES = ['number', 'money', 'percentage', 'rating', 'quantity'];

    /**
     * Types the engine orders as the database does: numbers, yes or no, and
     * dates, which Core writes in one form so their text sorts in time order.
     * Text the database orders by its collation, which PHP cannot repeat.
     */
    private const ORDERED_TYPES = [...self::NUMBER_TYPES, 'boolean', 'date'];

    public function __construct(private readonly SearchSchema $schema) {}

    /** Why the rows cannot answer the query here, null when they can. */
    public function refusal(SearchQuery $query): ?string
    {
        $query = $query->splitFor($this->schema);
        $split = $this->split($query);

        if ($split['text'] !== '') {
            if ($this->schema->searchMode() !== 'fields' || $this->schema->searchFields() === []) {
                return 'the object searches an index or code of its own';
            }

            foreach ($this->schema->searchFields() as $field) {
                if (! $this->schema->onRows($field) || str_contains($field, '.')) {
                    return "the search field [{$field}] is not on the rows";
                }
            }
        }

        foreach ([$query->filters, $this->synonymFilters($split)] as $filters) {
            foreach ($filters as $key => $values) {
                $rule = $this->schema->rule((string) $key);

                if ($rule === null) {
                    return "the filter [{$key}] follows no rule of the search contract";
                }

                if (($reason = $this->refusesValues((string) $key, $rule, $values)) !== null) {
                    return $reason;
                }
            }
        }

        foreach ($this->sorts($query) as [$field]) {
            if (! $this->schema->sortable($field) || ! $this->schema->onRows($field) || str_contains($field, '.')) {
                return "the sort [{$field}] is not on the rows";
            }

            if ($this->schema->sortsByCode($field) || ($field !== 'id' && ! in_array($this->schema->type($field), self::ORDERED_TYPES, true))) {
                return "the sort [{$field}] is ordered by the database";
            }
        }

        if (count($query->facets) > SearchQuery::MAX_FACETS) {
            return 'more facets than Core counts';
        }

        foreach ($query->facets as $key) {
            if (! $this->schema->facetable($key) || $this->schema->rule($key) !== SearchSchema::EXACT) {
                return "the facet [{$key}] is not counted by a value";
            }
        }

        return null;
    }

    /**
     * @param  iterable<array-key, array<string, mixed>>  $rows
     */
    public function run(SearchQuery $query, iterable $rows, bool $stale = false): SearchResult
    {
        $query = $query->splitFor($this->schema);
        $split = $this->split($query);
        $words = $split['text'] === '' ? [] : array_slice(SearchText::words($split['text']) ?: [$split['text']], 0, SearchText::MAX_WORDS);
        $plate = $split['text'] === '' ? null : SearchText::plate($split['text']);
        $synonyms = $this->synonymFilters($split);

        $matched = [];
        $facetable = [];

        foreach ($rows as $id => $row) {
            $row = ['id' => $row['id'] ?? $id] + $row;

            if (($words !== [] && ! $this->matchesText($row, $words, $plate)) || ! $this->matchesAll($row, $synonyms)) {
                continue;
            }

            // The keys whose own filter the row misses: a row missing one still counts in that key's facet.
            $misses = [];

            foreach ($query->filters as $key => $values) {
                if ($this->matches($row, (string) $key, $values)) {
                    continue;
                }

                $misses[] = (string) $key;

                if (count($misses) > 1) {
                    break;
                }
            }

            if ($misses === []) {
                $matched[] = $row;
            }

            if (count($misses) <= 1) {
                $facetable[] = [$row, $misses[0] ?? null];
            }
        }

        usort($matched, $this->compareRows($this->sorts($query)));

        $size = max(1, min($query->pageSize ?? $this->schema->defaultPageSize(), $this->schema->maxPageSize()));
        $total = count($matched);
        $lastPage = max(1, (int) ceil($total / $size));

        return new SearchResult(
            rows: $query->page > $lastPage ? [] : array_slice($matched, ($query->page - 1) * $size, $size),
            total: $total,
            page: $query->page,
            pageSize: $size,
            lastPage: $lastPage,
            facets: $this->facets($query->facets, $facetable),
            engine: SearchResult::LOCAL,
            status: $stale ? SearchResult::STALE : SearchResult::OK,
        );
    }

    /** @return array{text: string, filters: array<string, list<mixed>>} */
    private function split(SearchQuery $query): array
    {
        return $query->term === null
            ? ['text' => '', 'filters' => []]
            : $this->schema->synonyms()->split($query->term);
    }

    /**
     * The filters a term's synonyms name, each value as a filter value reads it.
     *
     * @param  array{text: string, filters: array<string, list<mixed>>}  $split
     * @return array<string, list<string>>
     */
    private function synonymFilters(array $split): array
    {
        return array_map(fn (array $values): array => SearchQuery::values($values, split: false), $split['filters']);
    }

    /** @param  list<string>  $values */
    private function refusesValues(string $key, string $rule, array $values): ?string
    {
        $excluded = ($values[0] ?? null) === self::NOT;

        foreach ($excluded ? array_slice($values, 1) : $values as $value) {
            if ($value === self::NOT) {
                continue;
            }

            if ($value === self::EMPTY || $value === self::NOT_EMPTY) {
                if (! $this->schema->nullable($key)) {
                    return "the filter [{$key}] takes no [{$value}]";
                }

                continue;
            }

            $valid = match ($rule) {
                SearchSchema::BOOLEAN => in_array($value, ['true', 'false', '1', '0'], true),
                SearchSchema::RANGE => $this->comparesBound($key, $value),
                // A value none of the options holds may still be one the database casts to (`01` to 1).
                SearchSchema::EXACT => ! $this->schema->declaresOptions($key) ? ! in_array($value, ['true', 'false'], true) : array_key_exists($value, $this->schema->labels($key)),
                // Core reads `true` and `false` as yes and no, which a text column does not compare with.
                default => ! in_array($value, ['true', 'false'], true),
            };

            if (! $valid) {
                return "the filter [{$key}] takes no [{$value}]";
            }
        }

        return null;
    }

    /**
     * Whether the engine compares a bound as the database would: a number
     * with a number field, a date with a date field, as Core writes them.
     */
    private function comparesBound(string $key, string $value): bool
    {
        $bound = self::bound($value);
        $type = $this->schema->filterType($key);

        return match (true) {
            $bound === null => false,
            in_array($type, self::NUMBER_TYPES, true) => is_numeric($bound[1]),
            $type === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $bound[1]) === 1,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, list<string>>  $filters
     */
    private function matchesAll(array $row, array $filters): bool
    {
        foreach ($filters as $key => $values) {
            if (! $this->matches($row, $key, $values)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the row passes one key's filter: a list led by `:not` excludes
     * the rest, `:empty` and `:not_empty` ask whether it holds a value, and
     * any other values are matched by the key's rule.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $values
     */
    private function matches(array $row, string $key, array $values): bool
    {
        $value = $row[(string) $this->schema->fieldFor($key)] ?? null;
        $value = is_scalar($value) ? $value : null;

        if (($values[0] ?? null) === self::NOT) {
            $excluded = array_values(array_filter(array_slice($values, 1), fn (string $value): bool => $value !== self::NOT));

            // `NOT (x IN (...))` is unknown for a null, so the database keeps a row with no value only where the key offers `:empty`.
            if ($excluded !== [] && $value === null && ! $this->schema->nullable($key)) {
                return false;
            }

            return $excluded === [] || ! $this->matches($row, $key, $excluded);
        }

        $rule = (string) $this->schema->rule($key);
        $empty = $value === null || (in_array($rule, [SearchSchema::PARTIAL, SearchSchema::PLATE], true) && $value === '');
        $plain = array_values(array_filter($values, fn (string $item): bool => $item !== self::EMPTY && $item !== self::NOT_EMPTY));

        if (in_array(self::NOT_EMPTY, $values, true)) {
            return in_array(self::EMPTY, $values, true) || ! $empty;
        }

        if (in_array(self::EMPTY, $values, true)) {
            return $empty || ($plain !== [] && $this->matchesValues($value, $rule, $plain));
        }

        return $plain === [] || $this->matchesValues($value, $rule, $plain);
    }

    /** @param  list<string>  $values */
    private function matchesValues(string|int|float|bool|null $value, string $rule, array $values): bool
    {
        if ($rule === SearchSchema::RANGE) {
            foreach ($values as $item) {
                if (! self::withinBound($value, (array) self::bound($item))) {
                    return false;
                }
            }

            return true;
        }

        if ($rule === SearchSchema::PLATE) {
            $plates = array_values(array_filter(array_map(SearchText::plate(...), $values)));
            $plate = $value === null ? null : SearchText::plate((string) $value);

            return $plates === [] || ($plate !== null && array_filter($plates, fn (string $term): bool => str_contains($plate, $term)) !== []);
        }

        if ($value === null) {
            return false;
        }

        foreach ($values as $item) {
            $matches = match ($rule) {
                SearchSchema::BOOLEAN => (bool) $value === in_array($item, ['true', '1'], true),
                SearchSchema::EXACT => SearchSchema::string($value) === $item || (is_int($value) || is_float($value)) && is_numeric($item) && (float) $value === (float) $item,
                default => str_contains(mb_strtolower((string) $value), mb_strtolower($item)),
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * A bound's operator and value, read as Core reads one: the longest
     * operator it starts with, `=` when it starts with none.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function bound(string $value): ?array
    {
        $operator = '=';

        foreach (self::OPERATORS as $candidate) {
            if (str_starts_with($value, $candidate)) {
                $operator = $candidate;
            }
        }

        $bound = str_starts_with($value, $operator) ? substr($value, strlen($operator)) : $value;

        return preg_match('/^-?\d[\d.:\-T ]*$/', $bound) === 1 ? [$operator, $bound] : null;
    }

    /** @param  array{0?: string, 1?: string}  $bound */
    private static function withinBound(string|int|float|bool|null $value, array $bound): bool
    {
        if ($value === null || ! isset($bound[0], $bound[1])) {
            return false;
        }

        $order = is_numeric($value) && is_numeric($bound[1])
            ? (float) $value <=> (float) $bound[1]
            : strcmp((string) $value, $bound[1]);

        return match ($bound[0]) {
            '<' => $order < 0,
            '>' => $order > 0,
            '<=' => $order <= 0,
            '>=' => $order >= 0,
            '<>' => $order !== 0,
            default => $order === 0,
        };
    }

    /**
     * Every word in one of the search fields, or the term as a plate inside a
     * plate field, as Core's declared-field search matches.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $words
     */
    private function matchesText(array $row, array $words, ?string $plate): bool
    {
        $fields = $this->schema->searchFields();
        $fold = $this->schema->ignoresAccents()
            ? SearchText::fold(...)
            : fn (string $text): string => mb_strtolower($text);

        if ($plate !== null) {
            foreach ($fields as $field) {
                $value = $row[$field] ?? null;

                if (($this->schema->fields()[$field]['type'] ?? null) === 'license_plate' && is_scalar($value) && str_contains((string) SearchText::plate((string) $value), $plate)) {
                    return true;
                }
            }
        }

        $texts = array_map(fn (string $field): ?string => is_scalar($row[$field] ?? null) ? $fold((string) $row[$field]) : null, $fields);

        foreach ($words as $word) {
            $word = $fold($word);

            if (array_filter($texts, fn (?string $text): bool => $text !== null && str_contains($text, $word)) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The sort as field and direction pairs: the query's, else the object's
     * default.
     *
     * @return list<array{0: string, 1: 'asc'|'desc'}>
     */
    private function sorts(SearchQuery $query): array
    {
        $sort = $query->sort ?? $this->schema->defaultSort();

        return array_values(array_map(
            fn (string $part): array => [ltrim($part, '-'), str_starts_with($part, '-') ? 'desc' : 'asc'],
            array_filter(explode(',', (string) $sort), fn (string $part): bool => ltrim($part, '-') !== ''),
        ));
    }

    /**
     * Rows in the sort's order, as Postgres orders them: no value last going
     * up and first going down, and the id last, in the direction of the last
     * sort, as Core breaks ties. refusal() leaves only numbers, yes or no and
     * dates, which compare the same here as there.
     *
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $sorts
     * @return \Closure(array<string, mixed>, array<string, mixed>): int
     */
    private function compareRows(array $sorts): \Closure
    {
        $sorts = [...$sorts, ['id', $sorts === [] ? 'asc' : $sorts[array_key_last($sorts)][1]]];

        return function (array $a, array $b) use ($sorts): int {
            foreach ($sorts as [$field, $direction]) {
                $order = self::compareValues($a[$field] ?? null, $b[$field] ?? null);

                if ($order !== 0) {
                    return $direction === 'desc' ? -$order : $order;
                }
            }

            return 0;
        };
    }

    private static function compareValues(mixed $a, mixed $b): int
    {
        $a = is_scalar($a) ? $a : null;
        $b = is_scalar($b) ? $b : null;

        if ($a === null || $b === null) {
            return ($a === null) <=> ($b === null);
        }

        if ((is_numeric($a) || is_bool($a)) && (is_numeric($b) || is_bool($b))) {
            return (float) $a <=> (float) $b;
        }

        return strcmp((string) $a, (string) $b);
    }

    /**
     * Each facet's rows: per value, the rows selecting it would leave (the
     * query without the key's own filter), with its label, most rows first.
     *
     * @param  list<string>  $keys
     * @param  list<array{0: array<string, mixed>, 1: ?string}>  $candidates  each row that misses at most one key's filter, and which
     * @return array<string, list<array{value: string, label: string, count: int}>>
     */
    private function facets(array $keys, array $candidates): array
    {
        $facets = [];

        foreach ($keys as $key) {
            $field = (string) $this->schema->fieldFor($key);
            $limit = $this->schema->valuesInUseLimit($key);
            $nullable = $this->schema->nullable($key);
            $counts = [];

            foreach ($candidates as [$row, $miss]) {
                if ($miss !== null && $miss !== $key) {
                    continue;
                }

                $value = is_scalar($row[$field] ?? null) ? SearchSchema::string($row[$field]) : null;

                // Values in use never offer blank text, which is neither a value nor empty.
                if (($value === null && ! $nullable) || ($value === '' && $limit !== null)) {
                    continue;
                }

                $value ??= self::EMPTY;
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }

            $empty = $counts[self::EMPTY] ?? null;
            unset($counts[self::EMPTY]);

            if ($limit !== null) {
                // The most frequent values, a tie going to the value that sorts first; the empty count rides on top.
                uksort($counts, fn (string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: strcmp($a, $b));
                $counts = array_slice($counts, 0, $limit, true);
            }

            if ($empty !== null) {
                $counts[self::EMPTY] = $empty;
            }

            $labels = $this->schema->labels($key);
            $rows = [];

            foreach ($counts as $value => $count) {
                $value = (string) $value;
                $rows[] = [
                    'value' => $value,
                    'label' => $value === self::EMPTY ? __('Empty') : ($labels[$value] ?? $value),
                    'count' => $count,
                ];
            }

            usort($rows, fn (array $a, array $b): int => ($a['value'] === self::EMPTY) <=> ($b['value'] === self::EMPTY)
                ?: $b['count'] <=> $a['count']
                ?: strcmp(SearchText::fold($a['label']), SearchText::fold($b['label']))
                ?: strcmp($a['value'], $b['value']));

            $facets[$key] = $rows;
        }

        return $facets;
    }
}
