<?php

namespace Inly\CoreWeb\Live;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inly\CoreWeb\Client\CoreClient;
use InvalidArgumentException;
use Throwable;

/**
 * Runs the live reads named in core-web.live.reads: validates the input,
 * makes the read's one call to Core with the site's client token, and maps
 * Core's answer to a LiveResult. Livewire components and controllers call
 * read() directly; the browser reaches it through LiveController.
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

        if ($ttl === 0) {
            return $this->call($name, $read, $input);
        }

        $key = 'core_web_live:'.$name.':'.sha1(serialize(self::sorted($input)));

        if (($kept = $this->kept($key)) !== null) {
            return $kept;
        }

        $result = $this->call($name, $read, $input);

        if ($result->successful() && $read->keep((array) $result->data)) {
            $this->keepFor($key, (array) $result->data, $ttl);
        }

        return $result;
    }

    /** A kept answer, with the seconds it has left; a cache that fails reads as a miss. */
    protected function kept(string $key): ?LiveResult
    {
        try {
            $kept = $this->store()->get($key);
        } catch (Throwable $e) {
            Log::warning('Core live read cache failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }

        if (! is_array($kept) || ! is_array($kept['data'] ?? null) || ($left = (int) ($kept['expires'] ?? 0) - now()->getTimestamp()) <= 0) {
            return null;
        }

        return LiveResult::ok($kept['data'], $left);
    }

    /** @param  array<string, mixed>  $data */
    protected function keepFor(string $key, array $data, int $ttl): void
    {
        try {
            $this->store()->put($key, ['data' => $data, 'expires' => now()->getTimestamp() + $ttl], $ttl);
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

            $response = $this->core->sendResponse(
                $request->method,
                $request->path,
                $request->data,
                ['Accept' => 'application/json'],
                (int) config('core-web.live.timeouts.read', 5),
                (int) config('core-web.live.timeouts.connect', 3),
            );

            if ($response->status() === 404) {
                return LiveResult::notFound();
            }

            if ($response->status() === 422) {
                return LiveResult::invalid(self::errors($response->json('errors')));
            }

            $data = $response->successful() && is_array($json = $response->json()) ? $read->present($json, $input) : null;

            if ($data === null) {
                Log::warning('Core live read failed.', ['read' => $name, 'status' => $response->status()]);

                return LiveResult::unavailable();
            }

            return LiveResult::ok($data, $read->keep($data) ? max(0, $read->ttl()) : 0);
        } catch (Throwable $e) {
            Log::warning('Core live read failed.', ['read' => $name, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return LiveResult::unavailable();
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
     * Core's validation messages by field, strings only.
     *
     * @return array<string, list<string>>
     */
    protected static function errors(mixed $errors): array
    {
        $out = [];

        foreach (is_array($errors) ? $errors : [] as $field => $messages) {
            $out[(string) $field] = array_values(array_filter((array) $messages, 'is_string'));
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected static function sorted(array $input): array
    {
        ksort($input);

        return array_map(fn ($value) => is_array($value) ? self::sorted($value) : $value, $input);
    }
}
