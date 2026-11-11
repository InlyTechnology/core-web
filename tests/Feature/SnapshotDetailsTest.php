<?php

namespace Inly\CoreWeb\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Search\Search;
use Inly\CoreWeb\Search\SearchResult;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotChange;
use Inly\CoreWeb\Snapshots\SnapshotExport;
use Inly\CoreWeb\Snapshots\SnapshotPage;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use LogicException;
use RuntimeException;

/** A snapshot's details: left out of the pull and read per document, cached beside the copy. */
class SnapshotDetailsTest extends TestCase
{
    use FakesCore;

    protected array $features = ['snapshots', 'search'];

    private const DETAILS = ['images', 'equipment'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.search.objects', [
            'cars' => ['object' => 'car', 'snapshot' => 'cars', 'engine' => 'remote'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** @return array<string, mixed> */
    private static function describe(): array
    {
        $describe = json_decode((string) file_get_contents(__DIR__.'/../fixtures/core-search-contract.json'), true, flags: JSON_THROW_ON_ERROR)['describe'];
        $describe['fields_available'] = [...$describe['fields_available'], ...self::DETAILS];

        return $describe;
    }

    /** @return list<array<string, mixed>> */
    private static function resources(string $equipment = 'Dragkrok'): array
    {
        return [
            ['type' => 'car', 'id' => '1', 'attributes' => ['name' => 'Octavia', 'make' => 'Škoda', 'price' => 189000, 'images' => [['url' => 'https://img.test/1.jpg']], 'equipment' => [$equipment]]],
            ['type' => 'car', 'id' => '2', 'attributes' => ['name' => 'V60', 'make' => 'Volvo', 'price' => 349000, 'images' => [['url' => 'https://img.test/2.jpg']], 'equipment' => ['Navigation']]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $resources
     * @param  array<string, mixed>  $stubs  answered before the cars
     */
    private function fakeCars(?array $resources = null, mixed $schema = null, array $stubs = []): void
    {
        $resources ??= self::resources();

        $this->fakeCore([
            ...$stubs,
            'core.test/api/v1/objects/car/schema*' => $schema ?? Http::response(['data' => ['type' => 'object-query-descriptions', 'id' => 'car', 'attributes' => self::describe()]]),
            'core.test/api/v1/objects/car/*' => self::coreObjectById('car', $resources),
            'core.test/api/v1/objects/car?*' => self::coreList($resources),
        ]);
        $this->answerSparseFieldsets();
    }

    private function snapshot(): Snapshot
    {
        $snapshot = Snapshot::make('cars')->fromObject('car')->details(self::DETAILS);
        app(SnapshotRegistry::class)->register($snapshot, pings: ['car']);

        return $snapshot;
    }

    /** @param  array<string, mixed>  $context  what cURL says of the request, as Guzzle hands it on */
    private static function failedTransfer(array $context): \Closure
    {
        return fn (HttpRequest $request) => Create::rejectionFor(new ConnectException('cURL error', $request->toPsrRequest(), null, $context));
    }

    /** A new store is a new request: nothing is memoised. */
    private static function store(): SnapshotStore
    {
        return new SnapshotStore(Cache::store(), app('log'));
    }

    private static function detailReads(?string $id = null): int
    {
        return count(Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), '/api/v1/objects/car/'.($id ?? ''))
            && (self::requestedFieldsets($request)['_'] ?? null) === self::DETAILS));
    }

    public function test_the_pull_asks_for_every_field_but_the_details(): void
    {
        $this->fakeCars();

        $rows = $this->snapshot()->pullDocuments();

        $this->assertSame(['id' => '1', 'name' => 'Octavia', 'make' => 'Škoda', 'price' => 189000], $rows['1']);
        $this->assertSame(['id' => '1', 'name' => 'Octavia', 'make' => 'Škoda', 'price' => 189000], $this->snapshot()->pullDocument('1')['1']);
    }

    public function test_a_snapshot_without_details_asks_for_every_field_and_reads_no_description(): void
    {
        $this->fakeCars();

        $rows = Snapshot::make('cars')->fromObject('car')->pullDocuments();

        $this->assertSame(['Dragkrok'], $rows['1']['equipment']);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/schema'));
    }

    public function test_a_pull_without_a_field_list_fails_and_keeps_the_last_good_copy(): void
    {
        $this->fakeCars(schema: Http::response(['data' => ['attributes' => ['fields' => []]]]));

        $this->expectException(RuntimeException::class);

        $this->snapshot()->pullDocuments();
    }

    public function test_a_document_reads_its_details_once_and_keeps_them(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();

        $first = self::store()->document($snapshot, '1');
        $again = self::store()->document($snapshot, '1');

        $this->assertSame(['id' => '1', 'name' => 'Octavia', 'make' => 'Škoda', 'price' => 189000, 'images' => [['url' => 'https://img.test/1.jpg']], 'equipment' => ['Dragkrok']], $first);
        $this->assertSame($first, $again);
        $this->assertSame(1, self::detailReads('1'));
        $this->assertArrayNotHasKey('equipment', self::store()->documents($snapshot)['1']);
        $this->assertNull(self::store()->document($snapshot, '99'));
    }

    public function test_one_request_reads_a_document_s_details_once(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        $store = self::store();

        Cache::forget($snapshot->key('details:1'));
        $store->document($snapshot, '1');
        Cache::forget($snapshot->key('details:1'));
        $store->document($snapshot, '1');

        $this->assertSame(1, self::detailReads('1'));
    }

    public function test_a_copy_kept_for_the_row_is_served_whatever_its_age_and_read_again_once_the_row_changes(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->document($snapshot, '1');

        $this->travel(901)->seconds();
        $this->assertSame(['Dragkrok'], self::store()->document($snapshot, '1')['equipment']);
        $this->assertSame(1, self::detailReads('1'));

        $resources = self::resources('Takbox');
        $resources[0]['attributes']['price'] = 179000;
        $this->fakeCars($resources);
        self::store()->refresh($snapshot);

        $this->assertSame(['Takbox'], self::store()->document($snapshot, '1')['equipment']);
        $this->assertSame(1, self::detailReads('1'));
    }

    public function test_while_core_fails_the_last_copy_is_served_and_core_is_not_asked_again(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->document($snapshot, '1');
        $resources = self::resources();
        $resources[0]['attributes']['price'] = 179000;
        $this->fakeCars($resources);
        self::store()->refresh($snapshot);

        $this->failCore();

        $stale = self::store();
        $this->assertSame(['Dragkrok'], $stale->document($snapshot, '1')['equipment']);
        $this->assertFalse($stale->servedDegraded());
        $this->assertTrue(Cache::has($snapshot->key('backoff')));

        $this->fakeCars();
        self::store()->document($snapshot, '1');
        $this->assertSame(0, self::detailReads());
    }

    public function test_with_no_copy_and_core_failing_the_document_comes_without_details_and_the_page_is_not_kept(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->documents($snapshot);
        $this->failCore();

        $store = self::store();
        $document = $store->document($snapshot, '1');

        $this->assertSame(['id' => '1', 'name' => 'Octavia', 'make' => 'Škoda', 'price' => 189000], $document);
        $this->assertTrue($store->servedDegraded());
    }

    public function test_a_document_core_no_longer_has_comes_without_details_and_is_not_asked_for_again_under_the_same_row(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->documents($snapshot);
        Cache::put($snapshot->key('details:2'), ['version' => 'old', 'at' => 0, 'data' => ['equipment' => ['Gone']]]);
        $this->fakeCars([self::resources()[0]]);

        $store = self::store();

        $this->assertSame(['id' => '2', 'name' => 'V60', 'make' => 'Volvo', 'price' => 349000], $store->document($snapshot, '2'));
        $this->assertFalse($store->servedDegraded());
        $this->assertSame([], Cache::get($snapshot->key('details:2'))['data']);

        $this->fakeCars([self::resources()[0]]);
        self::store()->document($snapshot, '2');
        $this->assertSame(0, self::detailReads());
    }

    public function test_a_document_that_carries_its_details_is_served_as_it_is(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        $document = ['id' => '1', 'name' => 'Octavia', 'images' => [], 'equipment' => ['Pinned']];
        self::store()->pin($snapshot, 'production.test', ['1' => $document]);

        $this->assertSame($document, self::store()->document($snapshot, '1'));
        $this->assertSame(0, self::detailReads());
    }

    public function test_a_pinned_copy_never_reads_details_from_this_site_s_core(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->pin($snapshot, 'production.test', ['1' => ['id' => '1', 'name' => 'Octavia']]);

        $store = self::store();

        $this->assertSame(['id' => '1', 'name' => 'Octavia'], $store->document($snapshot, '1'));
        $this->assertFalse($store->servedDegraded());
        $this->assertSame(0, self::detailReads());
    }

    public function test_a_preview_without_details_is_filled_from_the_copy_without_reading_core(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->document($snapshot, '1');
        $store = self::store();

        $previewed = $store->previewing($snapshot, null, ['1' => ['id' => '1', 'name' => 'Octavia RS']], fn () => $store->document($snapshot, '1'));

        $this->assertSame(['id' => '1', 'name' => 'Octavia RS', 'images' => [['url' => 'https://img.test/1.jpg']], 'equipment' => ['Dragkrok']], $previewed);
        $this->assertSame(1, self::detailReads());
    }

    public function test_a_preview_of_the_saved_record_reads_its_details_as_the_page_does(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        $store = self::store();
        $saved = $store->documents($snapshot)['1'];

        $previewed = $store->previewing($snapshot, null, ['1' => $saved], fn () => $store->document($snapshot, '1'));

        $this->assertSame(['Dragkrok'], $previewed['equipment']);
        $this->assertSame(1, self::detailReads('1'));
    }

    public function test_a_preview_reads_the_record_with_its_details(): void
    {
        $this->fakeCars();

        $this->assertSame(['Dragkrok'], $this->snapshot()->pullDocumentWithDetails('1')['1']['equipment']);
    }

    public function test_core_warm_reads_the_details_no_copy_holds_for_the_current_row(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->document($snapshot, '1');

        $this->artisan('core:warm', ['type' => 'cars'])->expectsOutput('cars: 2 documents, details read for 1.')->assertSuccessful();

        $this->assertSame(1, self::detailReads('2'));
        $this->assertSame(['Navigation'], self::store()->document($snapshot, '2')['equipment']);
        $this->assertSame(2, self::detailReads());
    }

    public function test_warming_details_stops_at_its_limit_and_when_core_fails(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->refresh($snapshot);

        $this->assertSame(1, self::store()->warmDetails($snapshot, limit: 1));

        Cache::flush();
        self::store()->refresh($snapshot);
        $this->failCore();

        $this->assertSame(0, self::store()->warmDetails($snapshot));
        $this->assertCount(0, Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), '/api/v1/objects/car/2')));
    }

    public function test_warming_details_reads_nothing_once_its_time_is_up(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->refresh($snapshot);

        $this->assertSame(0, self::store()->warmDetails($snapshot, seconds: Snapshot::DETAILS_TIMEOUT - 1));
        $this->assertSame(0, self::detailReads());
    }

    public function test_a_document_whose_details_fail_alone_is_skipped_and_backed_off_on_its_own(): void
    {
        $this->fakeCars(stubs: ['core.test/api/v1/objects/car/1?*' => Http::response('boom', 500)]);
        $snapshot = $this->snapshot();
        self::store()->refresh($snapshot);

        $this->assertSame(1, self::store()->warmDetails($snapshot));
        $this->assertCount(1, Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), '/api/v1/objects/car/1?')));
        $this->assertTrue(Cache::has($snapshot->key('details:1:backoff')));
        $this->assertFalse(Cache::has($snapshot->key('backoff')));

        $store = self::store();
        $this->assertSame(['Navigation'], $store->document($snapshot, '2')['equipment']);
        $this->assertArrayNotHasKey('equipment', $store->document($snapshot, '1'));
        $this->assertTrue($store->servedDegraded());
        $this->assertCount(1, Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), '/api/v1/objects/car/1?')));
    }

    public function test_a_read_that_times_out_once_connected_backs_off_only_its_document(): void
    {
        $this->fakeCars(stubs: ['core.test/api/v1/objects/car/1?*' => self::failedTransfer(['errno' => 28, 'connect_time' => 0.05])]);
        $snapshot = $this->snapshot();
        self::store()->refresh($snapshot);

        $this->assertSame(1, self::store()->warmDetails($snapshot));
        $this->assertTrue(Cache::has($snapshot->key('details:1:backoff')));
        $this->assertFalse(Cache::has($snapshot->key('backoff')));
        $this->assertFalse(Cache::has($snapshot->key('details:timeouts')));
    }

    public function test_a_third_read_timing_out_once_connected_backs_off_the_whole_snapshot(): void
    {
        $this->fakeCars(
            array_map(fn (int $id) => ['type' => 'car', 'id' => (string) $id, 'attributes' => ['name' => "Car {$id}"]], range(1, 3)),
            stubs: array_fill_keys(array_map(fn (int $id) => "core.test/api/v1/objects/car/{$id}?*", range(1, 3)), self::failedTransfer(['errno' => 28, 'connect_time' => 0.05])),
        );
        $snapshot = $this->snapshot();
        self::store()->refresh($snapshot);

        foreach (['1', '2'] as $id) {
            self::store()->document($snapshot, $id);
            $this->assertFalse(Cache::has($snapshot->key('backoff')));
        }

        self::store()->document($snapshot, '3');
        $this->assertTrue(Cache::has($snapshot->key('backoff')));
        $this->assertFalse(Cache::has($snapshot->key('details:3:backoff')));
    }

    public function test_a_read_that_cannot_connect_backs_off_the_whole_snapshot(): void
    {
        $this->fakeCars(stubs: ['core.test/api/v1/objects/car/1?*' => self::failedTransfer(['errno' => 28, 'connect_time' => 0])]);
        $snapshot = $this->snapshot();
        self::store()->refresh($snapshot);

        $this->assertSame(0, self::store()->warmDetails($snapshot));
        $this->assertTrue(Cache::has($snapshot->key('backoff')));
        $this->assertSame(0, self::detailReads('2'));
    }

    public function test_failed_reads_count_toward_the_warm_s_limit_and_three_in_a_row_stop_it(): void
    {
        $this->fakeCars(array_map(fn (int $id) => ['type' => 'car', 'id' => (string) $id, 'attributes' => ['name' => "Car {$id}"]], range(1, 6)));
        $snapshot = $this->snapshot();
        self::store()->refresh($snapshot);
        $this->failCore(500);

        $this->assertSame(0, self::store()->warmDetails($snapshot, limit: 2));
        $this->assertCount(2, Http::recorded());

        Cache::forget($snapshot->key('details:1:backoff'));
        Cache::forget($snapshot->key('details:2:backoff'));
        $this->failCore(500);

        $this->assertSame(0, self::store()->warmDetails($snapshot));
        $this->assertCount(3, Http::recorded());
        $this->assertFalse(Cache::has($snapshot->key('backoff')));
    }

    public function test_a_ping_reads_no_details_while_the_snapshot_or_the_document_is_backed_off(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->document($snapshot, '1');
        $this->fakeCars(self::resources('Takbox'));

        Cache::put($snapshot->key('details:1:backoff'), true, 60);
        self::store()->refreshOne($snapshot, '1');
        Cache::forget($snapshot->key('details:1:backoff'));
        Cache::put($snapshot->key('backoff'), true, 60);
        self::store()->refreshOne($snapshot, '1');

        $this->assertSame(0, self::detailReads());
    }

    public function test_a_ping_in_the_same_request_drops_the_details_it_has_read(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        $store = self::store();
        $store->document($snapshot, '1');
        $resources = self::resources('Takbox');
        $resources[0]['attributes']['price'] = 179000;
        $this->fakeCars($resources);

        $store->refreshOne($snapshot, '1');

        $this->assertSame(['Takbox'], $store->document($snapshot, '1')['equipment']);
    }

    public function test_the_object_s_description_is_read_once_for_every_pull(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();

        $snapshot->pullDocuments();
        $snapshot->pullDocuments();
        $snapshot->pullDocument('1');

        $this->assertCount(1, Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), '/schema')));
    }

    public function test_warming_reads_the_oldest_copies_first(): void
    {
        $this->fakeCars(array_map(fn (int $id) => ['type' => 'car', 'id' => (string) $id, 'attributes' => ['name' => "Car {$id}", 'equipment' => []]], range(1, 3)));
        $snapshot = $this->snapshot();
        self::store()->document($snapshot, '2');
        $this->travel(10)->seconds();
        self::store()->document($snapshot, '3');
        $this->travel(10)->seconds();
        self::store()->document($snapshot, '1');
        $this->travel(901)->seconds();
        self::store()->refresh($snapshot);

        self::store()->warmDetails($snapshot, limit: 2);

        $this->assertSame([1, 2, 2], [self::detailReads('1'), self::detailReads('2'), self::detailReads('3')]);
    }

    public function test_warming_reads_missing_details_first_then_the_oldest_copies(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->document($snapshot, '1');
        $this->travel(901)->seconds();
        self::store()->refresh($snapshot);

        $this->assertSame(1, self::store()->warmDetails($snapshot, limit: 1));
        $this->assertSame(1, self::detailReads('2'));
        $this->assertSame(1, self::detailReads('1'));

        $this->assertSame(1, self::store()->warmDetails($snapshot, limit: 1));
        $this->assertSame(2, self::detailReads('1'));
        $this->assertSame(0, self::store()->warmDetails($snapshot));
    }

    public function test_details_that_change_under_the_same_row_are_announced_as_a_change_to_that_document(): void
    {
        $changes = [];
        $this->fakeCars();
        $snapshot = $this->snapshot()->onChange(function (?string $partition, array $documents, ?SnapshotChange $change) use (&$changes): void {
            $changes[] = $change?->ids();
        });
        self::store()->document($snapshot, '1');
        $this->fakeCars(self::resources('Takbox'));

        $this->assertSame(0, self::store()->refreshOne($snapshot, '1'));

        $this->assertSame(['Takbox'], self::store()->document($snapshot, '1')['equipment']);
        $this->assertSame(1, self::detailReads('1'));
        $this->assertSame([null, ['1']], $changes);

        $this->travel(901)->seconds();
        $this->fakeCars(self::resources('Takräcke'));
        self::store()->refresh($snapshot);
        self::store()->warmDetails($snapshot);

        $this->assertSame(['Takräcke'], self::store()->document($snapshot, '1')['equipment']);
        $this->assertSame([null, ['1'], ['1']], $changes);
    }

    public function test_the_export_carries_the_details_the_site_has_cached(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot();
        self::store()->document($snapshot, '1');

        $page = app(SnapshotExport::class)->page($snapshot, 0, 200);

        $this->assertSame(['Dragkrok'], $page['documents'][0]['document']['equipment']);
        $this->assertArrayNotHasKey('equipment', $page['documents'][1]['document']);
        $this->assertSame(0, self::detailReads('2'));
    }

    public function test_the_export_of_a_snapshot_with_details_pages_in_smaller_pages(): void
    {
        $this->fakeCars(array_map(fn (int $id) => ['type' => 'car', 'id' => (string) $id, 'attributes' => ['name' => "Car {$id}"]], range(1, 30)));
        $snapshot = $this->snapshot();

        $this->assertSame(SnapshotExport::DETAILED_PAGE_SIZE, app(SnapshotExport::class)->page($snapshot, 0, 200)['next_cursor']);
    }

    public function test_a_remote_search_asks_for_the_rows_without_the_details(): void
    {
        $this->fakeCars();
        $this->snapshot();

        Search::for('cars')->search(['make' => 'Volvo']);

        $search = Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), '/api/v1/objects/car?') && str_contains($request->url(), 'filter'));
        $this->assertCount(1, $search);
        $this->assertSame(['name', 'make', 'city', 'fuel', 'gearbox', 'four_wheel_drive', 'price', 'plate'], self::requestedFieldsets($search->first()[0])['_']);
    }

    public function test_a_search_on_a_detail_is_never_answered_from_the_snapshot(): void
    {
        $describe = self::describe();
        $describe['fields']['equipment'] = ['type' => 'string', 'filter_key' => 'equipment'];
        $describe['filters_available'][] = 'equipment';
        $this->fakeCars(schema: Http::response(['data' => ['type' => 'object-query-descriptions', 'id' => 'car', 'attributes' => $describe]]));
        $this->snapshot();
        self::store()->refresh(app(SnapshotRegistry::class)->get('cars'));
        config(['core-web.search.objects.cars.engine' => SearchResult::LOCAL]);

        $this->assertSame([SearchResult::LOCAL, SearchResult::OK], [Search::for('cars')->search(['make' => 'Volvo'])->engine, Search::for('cars')->search(['make' => 'Volvo'])->status]);
        $this->assertSame(SearchResult::INVALID, Search::for('cars')->search(['equipment' => 'Drag'])->status);
    }

    public function test_a_page_finds_its_document_with_the_details(): void
    {
        $this->fakeCars();
        $snapshot = $this->snapshot()->pages(fn (array $document) => '/bilar/'.$document['id']);
        $page = SnapshotPage::for($snapshot, 'default');

        $this->assertSame(['Dragkrok'], $page->find('1')['equipment']);
        $this->assertSame(['Navigation'], $page->find(fn (array $document) => $document['make'] === 'Volvo')['equipment']);
    }

    public function test_a_partitioned_document_reads_its_details_in_its_own_partition_only(): void
    {
        $this->fakeCars();
        $snapshot = Snapshot::make('cars')->fromObject('car')->details(self::DETAILS)
            ->partitionBy(fn (array $document) => $document['make'] === 'Volvo' ? 'volvo' : 'other', ['volvo', 'other']);
        app(SnapshotRegistry::class)->register($snapshot);

        $this->assertSame(['Navigation'], self::store()->document($snapshot, '2', 'volvo')['equipment']);
        $this->assertNull(self::store()->document($snapshot, '2', 'other'));
        $this->assertSame(1, self::detailReads());
    }

    public function test_details_of_a_pull_of_its_own_are_read_as_it_says(): void
    {
        $snapshot = Snapshot::make('things')->pull(fn () => ['a' => ['id' => 'a']]);

        $read = $snapshot->details(['body'], fn (string $id, array $row) => ['body' => "Body of {$id}", 'other' => 'dropped']);
        $this->assertSame(['body' => 'Body of a'], $read->readDetails('a', ['id' => 'a']));

        $this->expectException(LogicException::class);
        Snapshot::make('things')->pull(fn () => [])->details(['body'])->readDetails('a', []);
    }
}
