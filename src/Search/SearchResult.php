<?php

namespace Inly\CoreWeb\Search;

/**
 * One page of a search's answer: the rows, how many matched, the facet counts
 * the query asked for, which engine answered and how far it can be trusted.
 */
final class SearchResult
{
    /** Filtered here, over the site's copy of the rows. */
    public const LOCAL = 'local';

    /** Answered by Core's Objects API. */
    public const REMOTE = 'remote';

    public const OK = 'ok';

    /** The last good answer, served while Core cannot give a fresh one. */
    public const STALE = 'stale';

    /** No answer: Core failed and nothing was kept. */
    public const UNAVAILABLE = 'unavailable';

    /** Core refused the query, or no engine here can run it. */
    public const INVALID = 'invalid';

    /**
     * @param  list<array<string, mixed>>  $rows  each row's attributes by property name, with its `id`
     * @param  array<string, list<array{value: string, label: string, count: int}>>  $facets
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly int $lastPage,
        public readonly array $facets,
        public readonly string $engine,
        public readonly string $status = self::OK,
    ) {}

    public static function unavailable(string $engine, int $page = 1, int $pageSize = 25): self
    {
        return new self([], 0, $page, $pageSize, 1, [], $engine, self::UNAVAILABLE);
    }

    public static function invalid(string $engine, int $page = 1, int $pageSize = 25): self
    {
        return new self([], 0, $page, $pageSize, 1, [], $engine, self::INVALID);
    }

    /** Whether the rows are an answer, fresh or stale. */
    public function answered(): bool
    {
        return $this->status === self::OK || $this->status === self::STALE;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(fn (array $row): string => (string) ($row['id'] ?? ''), $this->rows);
    }

    /** @return list<array{value: string, label: string, count: int}> */
    public function facet(string $key): array
    {
        return $this->facets[$key] ?? [];
    }

    public function stale(): bool
    {
        return $this->status === self::STALE;
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, page: int, page_size: int, last_page: int, facets: array<string, mixed>, engine: string, status: string} */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'total' => $this->total,
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'last_page' => $this->lastPage,
            'facets' => $this->facets,
            'engine' => $this->engine,
            'status' => $this->status,
        ];
    }

    /** @param  array<string, mixed>  $data  what toArray() gave */
    public static function fromArray(array $data, string $status = self::OK): self
    {
        return new self(
            array_values(array_filter((array) ($data['rows'] ?? []), is_array(...))),
            (int) ($data['total'] ?? 0),
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 25),
            (int) ($data['last_page'] ?? 1),
            (array) ($data['facets'] ?? []),
            (string) ($data['engine'] ?? self::REMOTE),
            $status,
        );
    }

    public function withStatus(string $status): self
    {
        return new self($this->rows, $this->total, $this->page, $this->pageSize, $this->lastPage, $this->facets, $this->engine, $status);
    }
}
