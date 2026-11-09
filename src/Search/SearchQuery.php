<?php

namespace Inly\CoreWeb\Search;

/**
 * One search of an object, as the Objects API reads it: the term, each filter
 * key's values, the sort, the page and the facets to count. Filter values are
 * always lists of strings, which Core reads as any of (or, for a key that
 * takes bounds, every bound), so a value holding a comma is never split.
 */
final class SearchQuery
{
    /** Core counts at most this many facets in one request. */
    public const MAX_FACETS = 10;

    /** Most values one key takes from a page address, and the longest of them. */
    public const MAX_URL_VALUES = 20;

    public const MAX_URL_VALUE_LENGTH = 100;

    /** The furthest page a query asks for. */
    public const MAX_PAGE = 10000;

    /** A bound as a page address writes one: a number, or a date with or without a time. */
    public const BOUND_VALUE = '/^(-?\d+(\.\d+)?|\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?)?)$/';

    /** Page address parameters that are never a filter key. */
    public const RESERVED = ['q', 'sort', 'page'];

    /**
     * @param  array<string, list<string>>  $filters
     * @param  list<string>  $facets
     */
    public function __construct(
        public readonly ?string $term = null,
        public readonly array $filters = [],
        public readonly ?string $sort = null,
        public readonly int $page = 1,
        public readonly ?int $pageSize = null,
        public readonly array $facets = [],
    ) {}

    /**
     * Read from the Objects API's own parameters (`filter[...]`, `sort`,
     * `page[number]`, `page[size]`, `facets`), as server code or a test writes
     * them. A string value is split on its commas, as Core splits it, and a
     * list's items are kept whole until splitFor() reads them as the key does.
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromApi(array $query): self
    {
        $filter = is_array($query['filter'] ?? null) ? $query['filter'] : [];
        $term = $filter['_search'] ?? null;
        unset($filter['_search']);

        $filters = [];

        foreach ($filter as $key => $value) {
            if (($values = self::values($value, split: ! is_array($value))) !== []) {
                $filters[(string) $key] = $values;
            }
        }

        $page = $query['page'] ?? null;
        $facets = $query['facets'] ?? [];

        return new self(
            term: is_scalar($term) ? SearchText::term((string) $term) : null,
            filters: $filters,
            sort: is_string($query['sort'] ?? null) && $query['sort'] !== '' ? $query['sort'] : null,
            page: self::page(is_array($page) ? ($page['number'] ?? 1) : ($page ?? 1)),
            pageSize: is_array($page) && is_numeric($page['size'] ?? null) ? max(1, min(self::MAX_PAGE, (int) $page['size'])) : null,
            facets: self::keys(is_string($facets) ? explode(',', $facets) : (array) $facets),
        );
    }

    /**
     * Read from a page address, keyed by the schema's own filter keys:
     * `?q=elbil&make=Volvo,Škoda&price_max=300000&sort=-price&page=2`. A key
     * the object does not filter by, a sort it does not offer, a key holding
     * a token other than `:empty` (for a key that may hold no value), a bound
     * that is no number or date and a value that is none of a key's declared
     * options are dropped, so a crafted address searches no differently from
     * one the page wrote.
     *
     * @param  array<string, mixed>  $params
     */
    public static function fromUrl(array $params, SearchSchema $schema): self
    {
        $filters = [];

        foreach ($params as $param => $value) {
            $param = (string) $param;

            if (in_array($param, self::RESERVED, true)) {
                continue;
            }

            [$key, $bound] = self::boundParam($param, $schema);

            if (str_starts_with($key, '_') || ! $schema->filters($key)) {
                continue;
            }

            $values = array_slice(self::values($value, split: ! is_array($value) || ! $schema->keepsListItemsWhole($key)), 0, self::MAX_URL_VALUES);

            // A token the page never writes (`:not` before all) would turn the rest of the list around, so the key goes.
            if (array_filter($values, fn (string $item): bool => str_starts_with($item, ':') && ($item !== ':empty' || ! $schema->nullable($key) || $bound !== null)) !== []) {
                continue;
            }

            $values = array_values(array_filter($values, fn (string $item): bool => mb_strlen($item) <= self::MAX_URL_VALUE_LENGTH));

            // A bound Core compares a number or a date with: anything else would be an error there, not a search.
            if ($schema->rule($key) === SearchSchema::RANGE) {
                $values = array_values(array_filter($values, fn (string $item): bool => $item === ':empty' || preg_match(self::BOUND_VALUE, $item) === 1));
                $values = $bound === null ? $values : array_map(fn (string $item): string => $bound.$item, array_slice($values, 0, 1));
            }

            // A value none of the options holds selects nothing on the page, while Core may cast it to one (`01` to 1).
            if ($schema->declaresOptions($key)) {
                $values = array_values(array_filter($values, fn (string $item): bool => $item === ':empty' || array_key_exists($item, $schema->labels($key))));
            }

            if ($values !== []) {
                $filters[$key] = array_values(array_unique([...$filters[$key] ?? [], ...$values]));
            }
        }

        $sort = is_string($params['sort'] ?? null) ? $params['sort'] : null;
        $sortable = $sort !== null && $sort !== '' && array_filter(
            explode(',', $sort),
            fn (string $part): bool => ! $schema->sortable(ltrim($part, '-')),
        ) === [];

        return new self(
            term: is_scalar($params['q'] ?? null) ? SearchText::term((string) $params['q']) : null,
            filters: $filters,
            sort: $sortable ? $sort : null,
            page: self::page($params['page'] ?? 1),
        );
    }

    /**
     * The page address parameters that read back as this query, keys sorted:
     * a key's values joined with commas, or as a `[]` list when one of a
     * value-in-use key's values holds a comma; a bound as `key_min` or `key_max`.
     *
     * @return array<string, string|list<string>>
     */
    public function toUrl(SearchSchema $schema): array
    {
        $params = [];

        foreach ($this->filters as $key => $values) {
            foreach ($values as $index => $value) {
                if (preg_match('/^(>=|<=)(.+)$/', $value, $match) === 1 && $schema->rule($key) === SearchSchema::RANGE) {
                    $params[$key.($match[1] === '>=' ? '_min' : '_max')] = $match[2];
                    unset($values[$index]);
                }
            }

            $values = array_values($values);

            if ($values === []) {
                continue;
            }

            $whole = array_filter($values, fn (string $value): bool => str_contains($value, ',')) !== [];
            $params[$key] = $whole ? $values : implode(',', $values);
        }

        $params += array_filter([
            'q' => $this->term,
            'sort' => $this->sort,
            'page' => $this->page > 1 ? (string) $this->page : null,
        ], fn (?string $value): bool => $value !== null);

        ksort($params);

        return $params;
    }

    /**
     * The Objects API parameters of this query.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        $filter = $this->filters;

        if ($this->term !== null) {
            $filter['_search'] = $this->term;
        }

        ksort($filter);

        return array_filter([
            'filter' => $filter,
            'sort' => $this->sort,
            'page' => array_filter(['number' => $this->page, 'size' => $this->pageSize]),
            'facets' => $this->facets === [] ? null : implode(',', $this->facets),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * The query with each list item split on its commas, as Core splits it,
     * except for a key whose items are values in use, which Core keeps whole.
     */
    public function splitFor(SearchSchema $schema): self
    {
        $filters = [];

        foreach ($this->filters as $key => $values) {
            $filters[$key] = $schema->keepsListItemsWhole($key) ? $values : self::values($values, split: true);
        }

        return new self($this->term, array_filter($filters), $this->sort, $this->page, $this->pageSize, $this->facets);
    }

    /** One key for every query that asks the same, whatever order its parts came in. */
    public function key(): string
    {
        $api = $this->toApi();
        $api['filter'] = array_map(function (array|string $values): array|string {
            if (! is_array($values)) {
                return $values;
            }

            // A leading `:not` turns the whole list around, so it is kept apart from a value spelled `:not`.
            $not = ($values[0] ?? null) === ':not';
            $values = $not ? array_slice($values, 1) : $values;
            sort($values);

            return $not ? [':not' => $values] : $values;
        }, $api['filter'] ?? []);

        if (isset($api['facets'])) {
            $facets = $this->facets;
            sort($facets);
            $api['facets'] = implode(',', $facets);
        }

        return sha1(json_encode($api, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param  list<string>|string  $values */
    public function withFilter(string $key, array|string $values): self
    {
        $filters = $this->filters;
        $values = self::values($values, split: false);

        if ($values === []) {
            unset($filters[$key]);
        } else {
            $filters[$key] = $values;
        }

        return new self($this->term, $filters, $this->sort, 1, $this->pageSize, $this->facets);
    }

    public function withTerm(?string $term): self
    {
        return new self(SearchText::term($term), $this->filters, $this->sort, 1, $this->pageSize, $this->facets);
    }

    public function withSort(?string $sort): self
    {
        return new self($this->term, $this->filters, $sort === '' ? null : $sort, 1, $this->pageSize, $this->facets);
    }

    public function withPage(int $page, ?int $pageSize = null): self
    {
        return new self($this->term, $this->filters, $this->sort, self::page($page), $pageSize === null ? $this->pageSize : max(1, $pageSize), $this->facets);
    }

    /** @param  list<string>  $facets */
    public function withFacets(array $facets): self
    {
        return new self($this->term, $this->filters, $this->sort, $this->page, $this->pageSize, self::keys($facets));
    }

    /**
     * A key's values as a list of strings, nothing blank: yes and no as `true`
     * and `false`, and a string split on its commas where `$split` asks.
     *
     * @return list<string>
     */
    public static function values(mixed $value, bool $split): array
    {
        $items = is_array($value) ? array_values($value) : [$value];
        $values = [];

        foreach ($items as $item) {
            $item = match (true) {
                $item === true => 'true',
                $item === false => 'false',
                is_scalar($item) => (string) $item,
                default => null,
            };

            foreach ($item === null ? [] : ($split ? explode(',', $item) : [$item]) as $part) {
                if ($part !== '') {
                    $values[] = $part;
                }
            }
        }

        return $values;
    }

    /** A page number as asked, from 1 to MAX_PAGE, 1 for anything that is no whole number. */
    private static function page(mixed $page): int
    {
        if (is_int($page)) {
            return max(1, min(self::MAX_PAGE, $page));
        }

        return is_string($page) && preg_match('/^\d{1,9}$/', $page) === 1 ? max(1, min(self::MAX_PAGE, (int) $page)) : 1;
    }

    /**
     * The filter key a page address parameter names and the bound it sets:
     * `price_min` is `price` from `>=`, for a key that takes bounds.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function boundParam(string $param, SearchSchema $schema): array
    {
        foreach (['_min' => '>=', '_max' => '<='] as $suffix => $operator) {
            $key = substr($param, 0, -strlen($suffix));

            if (str_ends_with($param, $suffix) && ! $schema->filters($param) && $schema->rule($key) === SearchSchema::RANGE) {
                return [$key, $operator];
            }
        }

        return [$param, null];
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return list<string>
     */
    private static function keys(array $keys): array
    {
        return array_values(array_unique(array_filter(array_map(fn (mixed $key): string => is_scalar($key) ? trim((string) $key) : '', $keys), fn (string $key): bool => $key !== '')));
    }
}
