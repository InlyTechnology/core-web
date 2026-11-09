<?php

namespace Inly\CoreWeb\Live;

use Illuminate\Http\Client\Response;

/**
 * Core's answer to a live read's request, read by the contract of what was
 * asked: a route of the Core app's own, or a Core function. CoreLive maps it
 * to a LiveResult without knowing which.
 */
final class CoreAnswer
{
    public const OK = 'ok';

    public const NOT_FOUND = 'not_found';

    public const INVALID = 'invalid';

    /** Core has no answer for now, and said why: still working, cannot answer yet, or past a limit. */
    public const PENDING = 'pending';

    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>|null  $json  the body of an `ok` answer
     * @param  array<string, list<string>>  $errors  Core's messages per input, of an `invalid` answer
     * @param  int  $maxAge  seconds Core lets an `ok` answer be kept
     * @param  bool  $coreDown  whether a `pending` or `failed` answer holds for every input, so the read is switched off
     * @param  int|null  $retryAfter  seconds Core asked to wait, for how long a read is switched off
     */
    private function __construct(
        public readonly string $kind,
        public readonly int $status,
        public readonly ?array $json = null,
        public readonly array $errors = [],
        public readonly int $maxAge = 0,
        public readonly bool $coreDown = false,
        public readonly ?int $retryAfter = null,
    ) {}

    /** An answer from a route of the Core app's own: Laravel's 404 and 422, and nothing to say about caching. */
    public static function fromRoute(Response $response): self
    {
        $status = $response->status();

        return match (true) {
            $status === 404 => new self(self::NOT_FOUND, $status),
            $status === 422 => new self(self::INVALID, $status, errors: self::fieldErrors($response->json('errors'))),
            $response->successful() && is_array($json = $response->json()) => new self(self::OK, $status, $json),
            default => new self(self::FAILED, $status, coreDown: self::gatewayDown($status)),
        };
    }

    /**
     * An answer from Core's Functions API: errors as a list naming their
     * input, `Cache-Control` from the function's cacheFor(), and the
     * `Retry-After` of a limit or an outage.
     */
    public static function fromFunction(Response $response): self
    {
        $status = $response->status();
        $retryAfter = self::retryAfter($response->header('Retry-After'));

        return match (true) {
            // 204: nothing for the input.
            in_array($status, [204, 404], true) => new self(self::NOT_FOUND, $status),
            $status === 422 => new self(self::INVALID, $status, errors: self::functionErrors($response->json('errors'))),
            // Still working (202) or cannot answer yet (409): nothing for this input, for now.
            in_array($status, [202, 409], true) => new self(self::PENDING, $status),
            // Past the function's rate limit, which every visitor shares on the site's token.
            $status === 429 => new self(self::PENDING, $status, coreDown: true, retryAfter: $retryAfter),
            $response->successful() && is_array($json = $response->json()) => new self(self::OK, $status, $json, maxAge: self::maxAge($response->header('Cache-Control'))),
            default => new self(self::FAILED, $status, coreDown: self::gatewayDown($status), retryAfter: $retryAfter),
        };
    }

    /** Whether a status says Core itself is down; a 500 can be one visitor's input, which must not switch the read off for all. */
    private static function gatewayDown(int $status): bool
    {
        return in_array($status, [502, 503, 504], true);
    }

    /**
     * Laravel's validation messages, `{field: [message, …]}`, strings only.
     *
     * @return array<string, list<string>>
     */
    private static function fieldErrors(mixed $errors): array
    {
        $out = [];

        foreach (is_array($errors) ? $errors : [] as $field => $messages) {
            $out[(string) $field] = array_values(array_filter((array) $messages, 'is_string'));
        }

        return $out;
    }

    /**
     * A function's 422: a list of errors, each naming its input under
     * `source.parameter`.
     *
     * @return array<string, list<string>>
     */
    private static function functionErrors(mixed $errors): array
    {
        $out = [];

        foreach (is_array($errors) ? $errors : [] as $error) {
            $field = is_array($error) ? ($error['source']['parameter'] ?? null) : null;

            if (is_string($field) && is_string($error['title'] ?? null)) {
                $out[$field][] = $error['title'];
            }
        }

        return $out;
    }

    /** Seconds an answer may be kept: its `max-age`, never one sent `no-store` or `no-cache`. */
    private static function maxAge(string $cacheControl): int
    {
        $cacheControl = strtolower($cacheControl);

        if (str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'no-cache') || preg_match('~(?:^|[,\s])max-age="?(\d+)~', $cacheControl, $match) !== 1) {
            return 0;
        }

        return (int) $match[1];
    }

    /** A Retry-After in seconds, capped at five minutes so one answer cannot switch a read off for long; null when none was sent. */
    private static function retryAfter(string $header): ?int
    {
        return ctype_digit(trim($header)) ? min((int) trim($header), 300) : null;
    }
}
