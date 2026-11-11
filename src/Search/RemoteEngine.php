<?php

namespace Inly\CoreWeb\Search;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Client\TokenRefused;
use Throwable;

/**
 * Searches an object on Core's Objects API with the site's client token, and
 * reads the object's `/schema` description. Each answer is kept (a search for
 * core-web.search.ttl seconds per query, a description for schema_ttl), and
 * when Core fails the last one kept within core-web.search.stale_for is
 * served instead.
 */
class RemoteEngine
{
    public function __construct(protected CoreClient $core) {}

    /** @param  list<string>  $fields  the fields each row carries; none named for every field */
    public function run(string $object, SearchQuery $query, array $fields = []): SearchResult
    {
        $key = 'core_web_search:'.$object.':'.$query->key().($fields === [] ? '' : ':'.sha1(implode(',', $fields)));
        $kept = $this->kept($key);
        $page = $query->page;
        $size = $query->pageSize ?? 25;

        if ($kept !== null && $kept['expires'] > now()->getTimestamp()) {
            return SearchResult::fromArray($kept['result']);
        }

        // Core failed a moment ago: answer at once rather than have every visitor wait out the timeout again.
        $result = $this->down($object) ? null : $this->call($object, $query, $fields);

        if ($result instanceof SearchResult) {
            $this->keep($key, $result->toArray(), (int) config('core-web.search.ttl', 60));

            return $result;
        }

        if ($result === false) {
            return SearchResult::invalid(SearchResult::REMOTE, $page, $size);
        }

        return $kept === null
            ? SearchResult::unavailable(SearchResult::REMOTE, $page, $size)
            : SearchResult::fromArray($kept['result'], SearchResult::STALE);
    }

    /**
     * The object's `/schema` description, the last one kept while Core cannot
     * give a fresh one, null when Core never gave one.
     */
    public function schema(string $object): ?SearchSchema
    {
        $key = 'core_web_search_schema:'.$object;
        $kept = $this->kept($key);

        if ($kept !== null && $kept['expires'] > now()->getTimestamp()) {
            return SearchSchema::fromDescribe($kept['result']);
        }

        $attributes = $this->down($object) ? null : $this->describe($object);
        $schema = $attributes === null ? null : self::readSchema($object, $attributes);

        if ($schema !== null) {
            $this->keep($key, $attributes, (int) config('core-web.search.schema_ttl', 900));

            return $schema;
        }

        return $kept === null ? null : SearchSchema::fromDescribe($kept['result']);
    }

    /**
     * The description read through every rule a search asks of it, so one
     * Core answers in a shape it does not expect is never kept.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function readSchema(string $object, array $attributes): ?SearchSchema
    {
        try {
            $schema = SearchSchema::fromDescribe($attributes);

            foreach (array_keys($schema->fields()) as $field) {
                $key = (string) ($schema->fields()[$field]['filter_key'] ?? $field);
                $schema->rule($key);
                $schema->labels($key);
                $schema->type((string) $field);
            }

            $schema->synonyms();

            return $schema;
        } catch (Throwable $e) {
            Log::warning('Core search schema failed.', ['object' => $object, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    protected function describe(string $object): ?array
    {
        if (! $this->core->configured()) {
            return null;
        }

        try {
            $response = $this->send(self::path($object).'/schema');
        } catch (ConnectionException|TokenRefused $e) {
            Log::warning('Core search schema failed.', ['object' => $object, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $this->markDown($object);

            return null;
        } catch (Throwable $e) {
            Log::warning('Core search schema failed.', ['object' => $object, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }

        $attributes = $response->successful() ? $response->json('data.attributes') : null;

        if (! is_array($attributes)) {
            Log::warning('Core search schema failed.', ['object' => $object, 'status' => $response->status()]);

            if (self::failing($response)) {
                $this->markDown($object, $response);
            }

            return null;
        }

        return $attributes;
    }

    /**
     * Core's answer, false when Core refused the query, null when it failed.
     */
    /** @param  list<string>  $fields */
    protected function call(string $object, SearchQuery $query, array $fields = []): SearchResult|false|null
    {
        if (! $this->core->configured()) {
            return null;
        }

        try {
            $response = $this->send(self::path($object), $query->toApi() + ['meta' => CoreClient::ROW_META] + ($fields === [] ? [] : ['fields' => ['_' => implode(',', $fields)]]));
        } catch (ConnectionException|TokenRefused $e) {
            Log::warning('Core search failed.', ['object' => $object, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $this->markDown($object);

            return null;
        } catch (Throwable $e) {
            Log::warning('Core search failed.', ['object' => $object, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }

        if (in_array($response->status(), [400, 422], true)) {
            Log::info('Core refused a search.', ['object' => $object, 'status' => $response->status()]);

            return false;
        }

        if (! $response->successful() || ! is_array($response->json('data'))) {
            Log::warning('Core search failed.', ['object' => $object, 'status' => $response->status()]);

            if (self::failing($response)) {
                $this->markDown($object, $response);
            }

            return null;
        }

        try {
            return self::result($response, $query);
        } catch (Throwable $e) {
            Log::warning('Core search failed.', ['object' => $object, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** Whether Core is failing rather than refusing: worth not asking again for a moment. */
    protected static function failing(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    protected static function result(Response $response, SearchQuery $query): SearchResult
    {
        $meta = (array) $response->json('meta', []);
        $rows = array_values(array_map(SearchRow::from(...), array_filter((array) $response->json('data'), is_array(...))));
        $size = (int) ($meta['per_page'] ?? $query->pageSize ?? max(1, count($rows)));
        $facets = [];

        foreach ((array) ($meta['facets'] ?? []) as $key => $counts) {
            $facets[(string) $key] = array_values(array_map(
                self::facetRow(...),
                array_filter((array) $counts, fn (mixed $count): bool => is_array($count) && is_scalar($count['value'] ?? null)),
            ));
        }

        return new SearchResult(
            rows: $rows,
            total: (int) ($meta['total'] ?? count($rows)),
            page: (int) ($meta['current_page'] ?? $query->page),
            pageSize: max(1, $size),
            lastPage: max(1, (int) ($meta['last_page'] ?? 1)),
            facets: $facets,
            engine: SearchResult::REMOTE,
        );
    }

    /**
     * @param  array<string, mixed>  $count
     * @return array{value: string, label: string, count: int}
     */
    protected static function facetRow(array $count): array
    {
        $value = (string) $count['value'];
        $label = is_scalar($count['label'] ?? null) ? (string) $count['label'] : $value;

        // Labelled as the local engine labels it, in the site's language rather than Core's.
        return ['value' => $value, 'label' => $value === ':empty' ? __('Empty') : $label, 'count' => (int) ($count['count'] ?? 0)];
    }

    /** @param  array<string, mixed>  $query */
    protected function send(string $path, array $query = []): Response
    {
        return $this->core->sendResponse(
            'get',
            $path,
            $query,
            ['Accept' => 'application/vnd.api+json'],
            max(1, (int) config('core-web.search.timeouts.read', 5)),
            max(1, (int) config('core-web.search.timeouts.connect', 3)),
        );
    }

    /** The object's Objects API path; a lookup key such as `core/form` keeps its slashes. */
    protected static function path(string $object): string
    {
        return '/api/v1/objects/'.implode('/', array_map(rawurlencode(...), explode('/', trim($object, '/'))));
    }

    /** @return array{result: array<string, mixed>, expires: int}|null */
    protected function kept(string $key): ?array
    {
        try {
            $kept = $this->store()->get($key);
        } catch (Throwable $e) {
            Log::warning('Core search cache failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }

        return is_array($kept) && is_array($kept['result'] ?? null) && is_int($kept['expires'] ?? null) ? $kept : null;
    }

    /** @param  array<string, mixed>  $result */
    protected function keep(string $key, array $result, int $ttl): void
    {
        $ttl = max(0, $ttl);

        if ($ttl === 0) {
            return;
        }

        try {
            $this->store()->put($key, ['result' => $result, 'expires' => now()->getTimestamp() + $ttl], $ttl + max(0, (int) config('core-web.search.stale_for', 3600)));
        } catch (Throwable $e) {
            Log::warning('Core search cache failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }

    /** For the Retry-After Core sent (at most five minutes), else core-web.search.retry_after seconds. */
    protected function markDown(string $object, ?Response $response = null): void
    {
        $header = trim((string) $response?->header('Retry-After'));
        $seconds = ctype_digit($header) ? min((int) $header, 300) : max(0, (int) config('core-web.search.retry_after', 30));

        if ($seconds === 0) {
            return;
        }

        try {
            $this->store()->put('core_web_search_down:'.$object, true, $seconds);
        } catch (Throwable) {
            // A store that fails only costs the shortcut.
        }
    }

    public function down(string $object): bool
    {
        try {
            return $this->store()->get('core_web_search_down:'.$object) === true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function store(): Repository
    {
        return Cache::store(config('core-web.search.store'));
    }
}
