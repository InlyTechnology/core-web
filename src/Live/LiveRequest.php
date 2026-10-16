<?php

namespace Inly\CoreWeb\Live;

/** The one call to Core a live read makes, on the site's client token. */
final class LiveRequest
{
    /** @param  array<string, mixed>  $data  the query of a GET, the JSON body of a POST */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $data,
    ) {}

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
}
