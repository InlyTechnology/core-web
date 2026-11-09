<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Search\Search;
use Inly\CoreWeb\Search\SearchResult;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;

/** A site's search of a Core object: the query its description allows, answered from the snapshot or by Core. */
class SearchTest extends TestCase
{
    use FakesCore;

    protected array $features = ['snapshots', 'search'];

    private const LIST = 'core.test/api/v1/objects/car?*';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.search.objects', [
            'cars' => ['object' => 'car', 'snapshot' => 'cars'],
            'remote-cars' => ['object' => 'car'],
        ]);
    }

    /** @return array<string, mixed> */
    private static function contract(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../fixtures/core-search-contract.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private static function resources(): array
    {
        return array_map(fn (array $row): array => [
            'type' => 'car',
            'id' => (string) $row['id'],
            'attributes' => array_diff_key($row, ['id' => true]),
        ], self::contract()['rows']);
    }

    /**
     * A Core answering the contract's description and rows: a list asking
     * for a filter or a facet gets `$search`, the full pull every row.
     *
     * @param  array<string, mixed>  $stubs
     */
    private function fakeCars(mixed $search = null, array $stubs = []): void
    {
        $this->fakeCore([
            'core.test/api/v1/objects/car/schema*' => Http::response(['data' => ['type' => 'object-query-descriptions', 'id' => 'car', 'attributes' => self::contract()['describe']]]),
            'core.test/api/v1/objects/car/*' => self::coreObjectById('car', self::resources()),
            ...$stubs,
            self::LIST => function (HttpRequest $request) use ($search) {
                if (! self::searches($request)) {
                    return self::coreList(self::resources());
                }

                return $search instanceof \Closure ? $search($request) : ($search ?? self::remoteAnswer());
            },
        ]);
    }

    private static function remoteAnswer(): mixed
    {
        return Http::response([
            'data' => [self::resources()[2]],
            'meta' => ['current_page' => 1, 'per_page' => 25, 'last_page' => 1, 'total' => 1, 'facets' => ['make' => [['value' => 'Volvo', 'label' => 'Volvo', 'count' => 1]]]],
        ]);
    }

    /** Whether a list request is a search rather than the snapshot's pull. */
    private static function searches(HttpRequest $request): bool
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return isset($query['filter']) || isset($query['facets']) || isset($query['sort']);
    }

    private function registerSnapshot(): void
    {
        app(SnapshotRegistry::class)->register(Snapshot::make('cars')->fromObject('car'));
    }

    private function searchesSent(): int
    {
        return count(Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), '/api/v1/objects/car?') && self::searches($request)));
    }

    public function test_an_unknown_search_is_a_configuration_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Search::for('boats');
    }

    public function test_a_site_naming_only_its_objects_keeps_the_other_defaults(): void
    {
        $this->assertSame(60, config('core-web.search.ttl'));
        $this->assertSame(5000, config('core-web.search.local_limit'));
        $this->assertSame(['connect' => 3, 'read' => 5], config('core-web.search.timeouts'));
    }

    public function test_the_query_keeps_only_what_the_description_offers(): void
    {
        $this->fakeCars();

        $query = Search::for('cars')->query(['q' => 'elbil', 'make' => 'Volvo', 'price_min' => '200000', 'colour' => 'red', 'sort' => 'secret', 'page' => '2']);

        $this->assertSame('elbil', $query->term);
        $this->assertSame(['make' => ['Volvo'], 'price' => ['>=200000']], $query->filters);
        $this->assertNull($query->sort);
        $this->assertSame(2, $query->page);
    }

    public function test_the_description_is_read_once_and_kept(): void
    {
        $this->fakeCars();

        Search::for('cars')->schema();
        Search::for('remote-cars')->schema();

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://core.test/api/v1/objects/car/schema');
    }

    public function test_a_snapshot_holding_every_row_answers_without_core(): void
    {
        $this->fakeCars();
        $this->registerSnapshot();

        $result = Search::for('cars')->search(['q' => 'skoda', 'sort' => '-price'], facets: ['fuel']);

        $this->assertSame(SearchResult::LOCAL, $result->engine);
        $this->assertSame(SearchResult::OK, $result->status);
        $this->assertSame(['2', '6', '1'], $result->ids());
        $this->assertSame('Enyaq', $result->rows[0]['name']);
        $this->assertSame(['Bensin', 'Diesel', 'El'], array_column($result->facet('fuel'), 'value'));
        $this->assertSame(0, $this->searchesSent());
    }

    public function test_a_filter_only_core_runs_goes_to_core(): void
    {
        $this->fakeCars();
        $this->registerSnapshot();

        $result = Search::for('cars')->run(Search::for('cars')->query(['affordable' => 'true']));

        $this->assertSame(SearchResult::REMOTE, $result->engine);
        Http::assertSent(function (HttpRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/api/v1/objects/car?')
                && ($query['filter']['affordable'] ?? null) === ['true']
                && $query['meta'] === 'compact'
                && $request->hasHeader('Authorization', 'Bearer token');
        });
    }

    public function test_more_rows_than_the_local_limit_go_to_core(): void
    {
        config(['core-web.search.local_limit' => 3]);
        $this->fakeCars();
        $this->registerSnapshot();

        $this->assertSame(SearchResult::REMOTE, Search::for('cars')->search(['make' => 'Volvo'])->engine);
    }

    public function test_a_snapshot_counted_over_the_limit_is_not_read_again(): void
    {
        config(['core-web.search.local_limit' => 3]);
        $this->fakeCars();
        $this->registerSnapshot();
        $snapshot = app(SnapshotRegistry::class)->get('cars');

        Search::for('cars')->search(['make' => 'Volvo']);
        $key = 'core_web_search_rows:cars:'.app(SnapshotStore::class)->version($snapshot);

        $this->assertSame(7, Cache::store(config('core-web.search.store'))->get($key));

        // A count says more than the snapshot holds, so the engine trusts the count rather than reading the rows.
        config(['core-web.search.local_limit' => 50]);
        Cache::store(config('core-web.search.store'))->put($key, 51, 60);

        $this->assertSame(SearchResult::REMOTE, Search::for('cars')->search(['make' => 'Volvo'])->engine);
    }

    public function test_a_search_s_own_engine_wins(): void
    {
        $this->fakeCars();
        $this->registerSnapshot();

        config(['core-web.search.objects.cars.engine' => 'remote']);
        $this->assertSame(SearchResult::REMOTE, Search::for('cars')->search(['make' => 'Volvo'])->engine);

        config(['core-web.search.objects.cars.engine' => 'local']);
        $refused = Search::for('cars')->search(['affordable' => 'true']);
        $this->assertSame(SearchResult::INVALID, $refused->status);
        $this->assertSame(1, $this->searchesSent());
    }

    public function test_core_s_answer_is_read_as_rows_and_kept_per_query(): void
    {
        $this->fakeCars();

        $first = Search::for('remote-cars')->search(['make' => 'Volvo'], facets: ['make']);
        $again = Search::for('remote-cars')->search(['make' => 'Volvo'], facets: ['make']);

        $this->assertSame(['3'], $first->ids());
        $this->assertSame('XC60 Recharge', $first->rows[0]['name']);
        $this->assertSame([['value' => 'Volvo', 'label' => 'Volvo', 'count' => 1]], $first->facet('make'));
        $this->assertSame(1, $first->total);
        $this->assertEquals($first, $again);
        $this->assertSame(1, $this->searchesSent());
    }

    public function test_the_last_answer_is_served_stale_while_core_fails(): void
    {
        config(['core-web.search.retry_after' => 0]);
        $this->fakeCars();
        Search::for('remote-cars')->search(['make' => 'Volvo']);

        $this->travel(61)->seconds();
        $this->fakeCars(Http::response('down', 503));

        $stale = Search::for('remote-cars')->search(['make' => 'Volvo']);
        $none = Search::for('remote-cars')->search(['make' => 'Škoda']);

        $this->assertSame(SearchResult::STALE, $stale->status);
        $this->assertSame(['3'], $stale->ids());
        $this->assertSame(SearchResult::UNAVAILABLE, $none->status);
        $this->assertFalse($none->answered());
    }

    public function test_core_failing_is_not_asked_again_at_once(): void
    {
        $calls = 0;
        $this->fakeCars(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('timed out');
        });

        Search::for('remote-cars')->search(['make' => 'Volvo']);
        $second = Search::for('remote-cars')->search(['make' => 'Škoda']);

        $this->assertSame(SearchResult::UNAVAILABLE, $second->status);
        $this->assertSame(1, $calls);
    }

    public function test_a_server_error_is_not_asked_again_at_once(): void
    {
        $calls = 0;
        $this->fakeCars(function () use (&$calls) {
            $calls++;

            return Http::response('boom', 500);
        });

        Search::for('remote-cars')->search(['make' => 'Volvo']);
        $second = Search::for('remote-cars')->search(['make' => 'Škoda']);

        $this->assertSame(SearchResult::UNAVAILABLE, $second->status);
        $this->assertSame(1, $calls);
    }

    public function test_an_answer_in_a_shape_core_does_not_give_is_read_without_failing(): void
    {
        $this->fakeCars(Http::response([
            'data' => [['type' => 'car', 'id' => ['3'], 'attributes' => 'XC60']],
            'meta' => ['total' => 1, 'facets' => ['make' => [['value' => ['Volvo']], 'Volvo', ['value' => ':empty', 'label' => 'Tomt', 'count' => 2]]]],
        ]));

        $result = Search::for('remote-cars')->search(['make' => 'Volvo'], facets: ['make']);

        $this->assertSame(SearchResult::OK, $result->status);
        $this->assertSame([['id' => '']], $result->rows);
        $this->assertSame([['value' => ':empty', 'label' => 'Empty', 'count' => 2]], $result->facet('make'));
    }

    public function test_a_description_in_a_shape_core_does_not_give_is_not_kept(): void
    {
        $describe = self::contract()['describe'];
        $describe['fields']['make']['filter_key'] = ['make'];
        $this->fakeCore([
            'core.test/api/v1/objects/car/schema*' => Http::response(['data' => ['type' => 'object-query-descriptions', 'id' => 'car', 'attributes' => $describe]]),
        ]);

        $this->assertNull(Search::for('remote-cars')->schema());
        $this->assertNull(Cache::store(config('core-web.search.store'))->get('core_web_search_schema:car'));
    }

    public function test_a_query_core_refuses_is_invalid_and_not_kept(): void
    {
        $this->fakeCars(Http::response(['errors' => [['status' => '400']]], 400));

        $this->assertSame(SearchResult::INVALID, Search::for('remote-cars')->search(['make' => 'Volvo'])->status);
        $this->assertSame(SearchResult::INVALID, Search::for('remote-cars')->search(['make' => 'Volvo'])->status);
        $this->assertSame(2, $this->searchesSent());
    }

    public function test_without_a_description_nothing_is_searched(): void
    {
        $this->fakeCore();

        $result = Search::for('cars')->search(['make' => 'Volvo']);

        $this->assertSame(SearchResult::UNAVAILABLE, $result->status);
        $this->assertSame([], Search::for('cars')->query(['make' => 'Volvo'])->filters);
    }

    public function test_the_snapshot_holds_every_row_as_the_engines_read_one(): void
    {
        $this->fakeCars();
        $this->registerSnapshot();

        $documents = app(SnapshotStore::class)->read(app(SnapshotRegistry::class)->get('cars'))->documents;

        $this->assertSame(['1', '2', '3', '4', '5', '6', '7'], array_map(strval(...), array_keys($documents)));
        $this->assertSame(['id' => '2', 'name' => 'Enyaq'], array_intersect_key($documents['2'], ['id' => 1, 'name' => 1]));
        $this->assertSame('car', app(SnapshotRegistry::class)->get('cars')->coreType());
    }

    public function test_a_ping_reads_one_row_again(): void
    {
        $this->fakeCars();
        $snapshot = Snapshot::make('cars')->fromObject('car');

        $this->assertSame(['3'], array_map(strval(...), array_keys($snapshot->pullDocument('3'))));
        $this->assertSame([], $snapshot->pullDocument('99'));
    }

    public function test_a_list_core_gives_in_part_fails_the_pull(): void
    {
        $this->fakeCore([
            self::LIST => Http::response(['data' => array_fill(0, 100, self::resources()[0]), 'meta' => ['total' => 250]]),
        ]);

        $this->expectException(RuntimeException::class);

        Snapshot::make('cars')->fromObject('car')->pullDocuments();
    }
}
