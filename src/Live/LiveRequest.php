<?php

namespace Inly\CoreWeb\Live;

use InvalidArgumentException;

/** The one call to Core a live read makes, on the site's client token. */
final class LiveRequest
{
    /** A function key: kebab-case segments, as Core names a class under app/Functions. */
    public const FUNCTION_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*';

    /**
     * @param  array<string, mixed>  $data  the query of a GET, the JSON body of a POST
     * @param  string|null  $function  the Core function the request runs, whose answers follow Core's functions contract
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $data,
        public readonly ?string $function = null,
    ) {}

    /**
     * Runs a Core function (`GET /api/v1/functions/{key}`), the record by its
     * route key among the arguments, as Core's Functions API takes it.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function function(string $key, array $arguments = []): self
    {
        if (preg_match('~^'.self::FUNCTION_PATTERN.'$~', $key) !== 1) {
            throw new InvalidArgumentException("[{$key}] is not a Core function key.");
        }

        return new self('get', '/api/v1/functions/'.$key, self::query($arguments), $key);
    }

    /** @param  array<string, mixed>  $query */
    public static function get(string $path, array $query = []): self
    {
        return new self('get', $path, $query);
    }

    /** @param  array<string, mixed>  $body */
    public static function post(string $path, array $body = []): self
    {
        return new self('post', $path, $body);
    }

    /**
     * Booleans as `true` and `false`, as Core's Functions API reads them; a
     * query string would otherwise send `1` and nothing.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function query(array $arguments): array
    {
        return array_map(fn ($value) => match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => self::query($value),
            default => $value,
        }, $arguments);
    }
}
