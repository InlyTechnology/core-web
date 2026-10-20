<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\TestCase;

/**
 * What `core:pull` pins stays pinned, whatever the site's own Core does
 * meanwhile, until `--release`; the pull itself is in SnapshotPullThroughSiteTest.
 */
class PullSnapshotsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'core-web.client.base_url' => 'https://core.test',
            'core-web.client.client_id' => 'local',
            'core-web.client.client_secret' => 'local-secret',
        ]);

        $this->fake(['local-1']);
        app(SnapshotRegistry::class)->register($this->snapshot());
    }

    private function snapshot(string $type = 'things'): Snapshot
    {
        return Snapshot::make($type)
            ->ttl(fresh: 900, stale: 3600, retry: 60)
            ->pull(fn (): array => collect(iterator_to_array(CoreClient::fromConfig()->objects('things'), false))
                ->mapWithKeys(fn (array $document) => [$document['data']['id'] => $document['data']])
                ->all())
            ->pullOne(fn (string $id): array => [$id => ['id' => $id, 'patched' => true]]);
    }

    /** @param  list<string>  $local  what the site's own Core holds */
    private function fake(array $local): void
    {
        Http::swap(new Factory);
        Http::fake([
            'core.test/oauth/token' => Http::response(['access_token' => 'local-token', 'expires_in' => 3600]),
            'core.test/api/v1/objects/things*' => Http::response([
                'data' => array_map(fn (string $id) => ['id' => $id, 'type' => 'things'], $local),
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($local)],
            ]),
        ]);
    }

    /** @param  list<string>  $ids */
    private function pin(array $ids = ['prod-1', 'prod-2'], string $type = 'things'): void
    {
        $documents = collect($ids)->mapWithKeys(fn (string $id) => [$id => ['id' => $id]])->all();
        $store = new SnapshotStore(Cache::store(), app('log'));
        $snapshot = app(SnapshotRegistry::class)->get($type);

        $store->pin($snapshot, 'prod.test', $documents);
        $store->forget($snapshot);
    }

    /** @return list<string> */
    private function documents(): array
    {
        return array_keys((new SnapshotStore(Cache::store(), app('log')))->documents(app(SnapshotRegistry::class)->get('things')));
    }

    public function test_the_pinned_copy_outlives_its_ttls_without_pulling(): void
    {
        $this->pin();
        Http::swap(new Factory);
        Http::fake();

        $this->travel(30)->days();

        $this->assertSame(['prod-1', 'prod-2'], $this->documents());
        Http::assertNothingSent();
    }

    public function test_core_warm_and_pings_leave_a_pinned_copy_alone(): void
    {
        $this->pin();

        $this->artisan('core:warm', ['type' => 'things'])->expectsOutputToContain('things: pinned by core:pull from prod.test')->assertSuccessful();
        $store = new SnapshotStore(Cache::store(), app('log'));
        $snapshot = app(SnapshotRegistry::class)->get('things');

        $this->assertSame(2, $store->refresh($snapshot));
        $this->assertSame(0, $store->refreshOne($snapshot, 'prod-1', 'prod-1'));
        $this->assertSame(['prod-1', 'prod-2'], $this->documents());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'core.test'));
    }

    public function test_release_forgets_the_pulled_copy_and_reads_the_site_s_own_core_again(): void
    {
        $this->pin();

        $this->artisan('core:pull', ['--release' => true])->expectsOutputToContain('things: released.')->assertSuccessful();

        $this->assertNull(Cache::get('core:things:v1:last_good'));
        $this->assertSame(['local-1'], $this->documents());
        $this->artisan('core:warm', ['type' => 'things'])->expectsOutputToContain('things: 1 documents')->assertSuccessful();
    }

    public function test_release_clears_a_standing_backoff_so_the_next_read_pulls(): void
    {
        $this->pin();
        Cache::put('core:things:v1:backoff', true, 60);

        $this->artisan('core:pull', ['--release' => true])->assertSuccessful();

        $this->assertSame(['local-1'], $this->documents());
    }

    public function test_a_snapshot_that_accepts_empty_pins_an_empty_copy_and_releases_it(): void
    {
        app(SnapshotRegistry::class)->register($this->snapshot('empty')->emptyWhen(fn () => false));

        $this->pin([], 'empty');
        $this->assertNotNull((new SnapshotStore(Cache::store(), app('log')))->pinned(app(SnapshotRegistry::class)->get('empty')));
        $this->artisan('core:pull', ['type' => 'empty', '--release' => true])->expectsOutputToContain('empty: released.')->assertSuccessful();

        $this->assertNull(Cache::get('core:empty:v1:fresh'));
    }

    public function test_a_pull_that_started_before_the_pin_does_not_overwrite_it(): void
    {
        $racing = true;
        $snapshot = Snapshot::make('racing')->ttl(fresh: 900, stale: 3600, retry: 60)->pull(function () use (&$racing, &$snapshot): array {
            if (! $racing) {
                return ['prod' => ['id' => 'prod']];
            }

            $racing = false;
            $other = new SnapshotStore(Cache::store(), app('log'));
            $other->pinning('prod.test', fn () => $other->refresh($snapshot));

            return ['local' => ['id' => 'local']];
        });

        (new SnapshotStore(Cache::store(), app('log')))->refresh($snapshot);

        $this->assertSame(['prod'], array_keys(Cache::get('core:racing:v1:last_good')));
    }

    public function test_a_pin_missing_its_copy_is_dropped_and_the_site_pulls_as_usual(): void
    {
        $this->pin();
        Cache::forget('core:things:v1:fresh');
        Cache::forget('core:things:v1:last_good');

        $this->assertSame(['local-1'], $this->documents());
        $this->assertNull((new SnapshotStore(Cache::store(), app('log')))->pinned(app(SnapshotRegistry::class)->get('things')));
    }

    public function test_it_runs_only_locally(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;

            $this->artisan('core:pull')->expectsOutputToContain('runs only where APP_ENV is local')->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_release_runs_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('core:pull', ['--release' => true])->expectsOutputToContain('things: not pinned.')->assertSuccessful();
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->artisan('core:pull', ['type' => 'nope'])->expectsOutputToContain('No snapshot [nope] is registered')->assertFailed();
    }
}
