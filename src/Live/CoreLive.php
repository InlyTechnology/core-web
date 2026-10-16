<?php

namespace Inly\CoreWeb\Live;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Client\TokenRefused;
use InvalidArgumentException;
use Throwable;

/**
 * Runs the live reads named in core-web.live.reads: validates the input,
 * makes the read's one call to Core with the site's client token, and maps
 * Core's answer to a LiveResult. Livewire components and controllers call
 * read() directly; the browser reaches the reads that are public() through
 * LiveController.
 */
class CoreLive
{
    /** Read names: lowercase slugs, as they appear in the relay's URL. */
    public const NAME_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(protected CoreClient $core) {}

    /** Whether the site named a read. */
    public static function has(string $name): bool
    {
        return array_key_exists($name, (array) config('core-web.live.reads', []));
    }

    /** Whether the site named a read and opened it to pages' scripts. */
    public function isPublic(string $name): bool
    {
        return self::has($name) && $this->resolve($name)->public();
    }

    /** @param  array<string, mixed>  $input */
    public function read(string $name, array $input = []): LiveResult
    {
        $read = $this->resolve($name);
        $validator = Validator::make($input, $read->rules());

        if ($validator->fails()) {
            return LiveResult::invalid(array_map(array_values(...), $validator->errors()->toArray()));
        }

        $input = $validator->validated();
        $ttl = max(0, $read->ttl());
        $key = 'core_web_live:'.$name.':'.sha1(serialize(self::sorted($input)));
        $kept = $ttl === 0 ? null : $this->kept($key);

        if ($kept !== null && ($left = $kept['expires'] - now()->getTimestamp()) > 0) {
            return LiveResult::ok($kept['data'], $left);
        }

        // Core failed a moment ago: answer at once rather than have every visitor wait out the timeout again.
        $result = $this->down($name) ? LiveResult::unavailable() : $this->call($name, $read, $input);

        if ($result->successful()) {
            if ($ttl === 0 || ! $read->keep((array) $result->data)) {
                return $result;
            }

            $this->keepFor($key, (array) $result->data, $ttl, max(0, $read->staleFor()));

            return LiveResult::ok((array) $result->data, $ttl);
        }

        // Core failed: the last good answer, within staleFor(), rather than none.
        if ($result->status === LiveResult::UNAVAILABLE && $kept !== null) {
            return LiveResult::stale($kept['data']);
        }

        return $result;
    }

    /**
     * The answer kept for an input, fresh or past its ttl() but within
     * staleFor(); a cache that fails reads as a miss.
     *
     * @return array{data: array<string, mixed>, expires: int}|null
     */
    protected function kept(string $key): ?array
    {
        try {
            $kept = $this->store()->get($key);
        } catch (Throwable $e) {
            Log::warning('Core live read cache failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }

        return is_array($kept) && is_array($kept['data'] ?? null) && is_int($kept['expires'] ?? null) ? $kept : null;
    }

    /** @param  array<string, mixed>  $data */
    protected function keepFor(string $key, array $data, int $ttl, int $staleFor): void
    {
        try {
            $this->store()->put($key, ['data' => $data, 'expires' => now()->getTimestamp() + $ttl], $ttl + $staleFor);
        } catch (Throwable $e) {
            Log::warning('Core live read cache failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $input */
    protected function call(string $name, LiveRead $read, array $input): LiveResult
    {
        if (! $this->core->configured()) {
            return LiveResult::unavailable();
        }

        try {
            if (($request = $read->request($input)) === null) {
                return LiveResult::notFound();
            }

            // Built from visitor input: a dot segment would walk the site's token up Core's API once a proxy normalises it.
            if (preg_match('~(^|/)\.{1,2}(/|$)~', rawurldecode($request->path)) === 1) {
                throw new InvalidArgumentException("The live read [{$name}] asked for a path with a dot segment.");
            }

            // A raw `?` or `#` would let visitor input end the path and write its own query; a backslash or control character is no path at all.
            if (preg_match('~[?#\\\\\x00-\x20\x7f]~', $request->path) === 1) {
                throw new InvalidArgumentException("The live read [{$name}] asked for a path with a query, fragment or unencoded character; rawurlencode the input it carries.");
            }

            $response = $this->core->sendResponse(
                $request->method,
                $request->path,
                $request->data,
                ['Accept' => 'application/json'],
                max(1, (int) config('core-web.live.timeouts.read', 5)),
                max(1, (int) config('core-web.live.timeouts.connect', 3)),
            );

            if ($response->status() === 404) {
                return LiveResult::notFound();
            }

            if ($response->status() === 422) {
                return LiveResult::invalid(self::errors($response->json('errors'), $read->rules()));
            }

            $data = $response->successful() && is_array($json = $response->json()) ? $read->present($json, $input) : null;

            if ($data === null) {
                Log::warning('Core live read failed.', ['read' => $name, 'status' => $response->status()]);

                return $this->failed($name, self::coreDown($response->status()));
            }

            return LiveResult::ok($data);
        } catch (InvalidArgumentException $e) {
            Log::warning('Core live read refused.', ['read' => $name, 'message' => $e->getMessage()]);

            return LiveResult::unavailable();
        } catch (ConnectionException|TokenRefused $e) {
            Log::warning('Core live read failed.', ['read' => $name, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->failed($name, true);
        } catch (Throwable $e) {
            Log::warning('Core live read failed.', ['read' => $name, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->failed($name, false);
        }
    }

    /** Whether a status says Core itself is down; a 500 can be one visitor's input, which must not switch the read off for all. */
    protected static function coreDown(int $status): bool
    {
        return in_array($status, [502, 503, 504], true);
    }

    /** Unavailable, marking Core down for the read for core-web.live.retry_after seconds when Core itself failed. */
    protected function failed(string $name, bool $coreDown): LiveResult
    {
        $seconds = max(0, (int) config('core-web.live.retry_after', 30));

        if ($coreDown && $seconds > 0) {
            try {
                $this->store()->put('core_web_live_down:'.$name, true, $seconds);
            } catch (Throwable) {
                // A store that fails only costs the shortcut.
            }
        }

        return LiveResult::unavailable();
    }

    protected function down(string $name): bool
    {
        try {
            return $this->store()->get('core_web_live_down:'.$name) === true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function resolve(string $name): LiveRead
    {
        if (! self::has($name)) {
            throw new InvalidArgumentException("core-web.live.reads names no read [{$name}].");
        }

        $class = config("core-web.live.reads.{$name}");
        $read = is_string($class) ? app($class) : null;

        if (! $read instanceof LiveRead) {
            throw new InvalidArgumentException("core-web.live.reads.{$name} must name a ".LiveRead::class.', not ['.(is_string($class) ? $class : get_debug_type($class)).'].');
        }

        return $read;
    }

    protected function store(): Repository
    {
        return Cache::store(config('core-web.live.store'));
    }

    /**
     * Core's validation messages for the fields the visitor sent (the read's
     * rules), strings only: Core judged a body the site built, and the rest
     * can name fields the visitor never saw.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, list<string>>
     */
    protected static function errors(mixed $errors, array $rules): array
    {
        $fields = array_map(fn (string $key): string => explode('.', $key)[0], array_keys($rules));
        $out = [];

        foreach (is_array($errors) ? $errors : [] as $field => $messages) {
            if (in_array(explode('.', (string) $field)[0], $fields, true)) {
                $out[(string) $field] = array_values(array_filter((array) $messages, 'is_string'));
            }
        }

        return $out;
    }

    /**
     * The input as a cache key sees it: keys sorted, scalars as strings, so a
     * query string's "12" and server code's 12 keep one answer.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected static function sorted(array $input): array
    {
        ksort($input);

        return array_map(fn ($value) => match (true) {
            is_array($value) => self::sorted($value),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => $value,
        }, $input);
    }
}
