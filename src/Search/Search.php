<?php

namespace Inly\CoreWeb\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * One of the site's searches of a Core object, by the name
 * core-web.search.objects gives it: its query read from a page address
 * against the object's `/schema` description, and its answer from the
 * engine that can give it. The local engine searches the object's snapshot
 * when the snapshot holds every row (at most core-web.search.local_limit)
 * and the query keeps to the rules Core's search contract publishes; Core's
 * Objects API answers the rest. Both answer the same query the same way.
 */
final class Search
{
    private ?SearchSchema $schema = null;

    private bool $described = false;

    /** @param  array<string, mixed>  $config */
    private function __construct(public readonly string $name, private readonly array $config) {}

    public static function for(string $name): self
    {
        if (! CoreWebServiceProvider::enabled('search')) {
            throw new LogicException('Switch on core-web\'s `search` feature to search a Core object.');
        }

        $config = config("core-web.search.objects.{$name}");

        if (! is_array($config) || ! is_string($config['object'] ?? null) || $config['object'] === '') {
            throw new InvalidArgumentException("No search [{$name}] in core-web.search.objects.");
        }

        return new self($name, $config);
    }

    /** The object's lookup key in Core. */
    public function object(): string
    {
        return $this->config['object'];
    }

    /** The object's description, null while Core has never given one. */
    public function schema(): ?SearchSchema
    {
        if (! $this->described) {
            $this->schema = app(RemoteEngine::class)->schema($this->object());
            $this->described = true;
        }

        return $this->schema;
    }

    /**
     * The query a page address asks for (`?q=elbil&make=Volvo&sort=-price`),
     * keeping only what the object's description offers. Without a
     * description it asks for nothing.
     *
     * @param  array<string, mixed>  $params
     */
    public function query(array $params): SearchQuery
    {
        $schema = $this->schema();

        return $schema === null ? new SearchQuery : SearchQuery::fromUrl($params, $schema);
    }

    /**
     * The answer to a page address's query, with the facets and page size
     * the page counts and shows.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $facets
     */
    public function search(array $params, array $facets = [], ?int $pageSize = null): SearchResult
    {
        $query = $this->query($params)->withFacets($facets);

        return $this->run($pageSize === null ? $query : $query->withPage($query->page, $pageSize));
    }

    public function run(SearchQuery $query): SearchResult
    {
        $schema = $this->schema();

        if ($schema === null) {
            return SearchResult::unavailable(SearchResult::REMOTE, $query->page, $query->pageSize ?? 25);
        }

        $engine = $this->config['engine'] ?? config('core-web.search.engine');

        if ($engine !== SearchResult::REMOTE) {
            $local = $this->local($schema, $query);

            if ($local instanceof SearchResult) {
                return $local;
            }

            if ($engine === SearchResult::LOCAL) {
                Log::info('Core search cannot run here.', ['search' => $this->name, 'reason' => $local]);

                return SearchResult::invalid(SearchResult::LOCAL, $query->page, $query->pageSize ?? $schema->defaultPageSize());
            }
        }

        return app(RemoteEngine::class)->run($this->object(), $query);
    }

    /** The local engine's answer, or why it cannot give one. */
    private function local(SearchSchema $schema, SearchQuery $query): SearchResult|string
    {
        $snapshot = $this->snapshot();

        if (is_string($snapshot)) {
            return $snapshot;
        }

        $engine = new LocalEngine($schema);

        if (($refusal = $engine->refusal($query)) !== null) {
            return $refusal;
        }

        $store = app(SnapshotStore::class);
        $limit = max(0, (int) config('core-web.search.local_limit', 5000));
        $counted = $this->counted($snapshot, $store->version($snapshot));

        // A copy known to be too big is not read again on every search only to be sent to Core.
        if ($counted !== null && $counted > $limit) {
            return "the snapshot holds more than {$limit} rows";
        }

        $read = $store->read($snapshot);

        if ($read->degraded) {
            return 'the snapshot holds nothing';
        }

        $this->count($snapshot, $store->version($snapshot), count($read->documents));

        if (count($read->documents) > $limit) {
            return "the snapshot holds more than {$limit} rows";
        }

        return $engine->run($query, $read->documents, $read->stale);
    }

    /** How many rows this version of the snapshot holds, when a search counted them. */
    private function counted(Snapshot $snapshot, ?string $version): ?int
    {
        try {
            $count = $version === null ? null : Cache::store(config('core-web.search.store'))->get(self::countKey($snapshot, $version));
        } catch (Throwable) {
            return null;
        }

        return is_int($count) ? $count : null;
    }

    private function count(Snapshot $snapshot, ?string $version, int $count): void
    {
        if ($version === null) {
            return;
        }

        try {
            Cache::store(config('core-web.search.store'))->put(self::countKey($snapshot, $version), $count, 86400);
        } catch (Throwable) {
            // A store that fails only costs the shortcut.
        }
    }

    private static function countKey(Snapshot $snapshot, string $version): string
    {
        return 'core_web_search_rows:'.$snapshot->type.':'.$version;
    }

    /** The snapshot holding the object's rows, or why there is none to search. */
    private function snapshot(): Snapshot|string
    {
        $type = $this->config['snapshot'] ?? null;

        if (! is_string($type) || $type === '') {
            return 'the search names no snapshot';
        }

        $registry = app(SnapshotRegistry::class);

        if (! $registry->has($type)) {
            return "no snapshot [{$type}] is registered";
        }

        $snapshot = $registry->get($type);

        // A partition holds some of the rows, and the local engine answers only over all of them.
        return $snapshot->partitions() === [null] ? $snapshot : "the snapshot [{$type}] is partitioned";
    }
}
