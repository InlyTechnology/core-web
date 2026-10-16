<?php

namespace Inly\CoreWeb\Live;

/** A live read's outcome: `ok` with the data, or why there is none. */
final class LiveResult
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const NOT_FOUND = 'not_found';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, list<string>>  $errors
     */
    private function __construct(
        public readonly string $status,
        public readonly ?array $data = null,
        public readonly array $errors = [],
        public readonly int $ttl = 0,
        public readonly bool $stale = false,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function ok(array $data, int $ttl = 0): self
    {
        return new self(self::OK, $data, ttl: $ttl);
    }

    /**
     * The last good answer, served while Core fails; never kept by the browser.
     *
     * @param  array<string, mixed>  $data
     */
    public static function stale(array $data): self
    {
        return new self(self::OK, $data, stale: true);
    }

    /** @param  array<string, list<string>>  $errors */
    public static function invalid(array $errors): self
    {
        return new self(self::INVALID, errors: $errors);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function successful(): bool
    {
        return $this->status === self::OK;
    }

    /** The HTTP status the relay answers with. */
    public function httpStatus(): int
    {
        return match ($this->status) {
            self::OK => 200,
            self::INVALID => 422,
            self::NOT_FOUND => 404,
            default => 503,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return match ($this->status) {
            self::OK => ['data' => $this->data],
            self::INVALID => ['error' => self::INVALID, 'errors' => $this->errors],
            default => ['error' => $this->status],
        };
    }
}
