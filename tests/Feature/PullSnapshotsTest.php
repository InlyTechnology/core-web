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
 * `core:pull`: a local site reads production's snapshots through a read-only
 * client and keeps them pinned, whatever its own Core does meanwhile.
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
            'core-web.client.ca_bundle' => '/local/ca.pem',
            'core-web.pull.base_url' => 'https://prod.test',
            'core-web.pull.client_id' => 'pull',
            'core-web.pull.client_secret' => 'pull-secret',
        ]);

        $this->fake(['prod-1', 'prod-2'], ['local-1']);
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

    /**
     * @param  list<string>|null  $prod  null: production is down
     * @param  list<string>  $local
     */
    private function fake(?array $prod, array $local): void
    {
        $list = fn (array $ids) => Http::response([
            'data' => array_map(fn (string $id) => ['id' => $id, 'type' => 'things'], $ids),
            'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($ids)],
        ]);

        Http::swap(new Factory);
        Http::fake([
            'prod.test/oauth/token' => Http::response(['access_token' => 'prod-token', 'expires_in' => 3600]),
            'prod.test/api/v1/objects/things*' => $prod === null ? Http::response('down', 503) : $list($prod),
            'core.test/oauth/token' => Http::response(['access_token' => 'local-token', 'expires_in' => 3600]),
            'core.test/api/v1/objects/things*' => $list($local),
        ]);
    }

    /** @return list<string> */
    private function documents(): array
    {
        return array_keys((new SnapshotStore(Cache::store(), app('log')))->documents(app(SnapshotRegistry::class)->get('things')));
    }

    public function test_it_pulls_through_the_pull_client_and_pins_the_copy(): void
    {
        $this->artisan('core:pull')->expectsOutputToContain('things: 2 documents pinned from prod.test')->assertSuccessful();

        $this->assertSame(['prod-1', 'prod-2'], $this->documents());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://prod.test/oauth/token' && $request['client_id'] === 'pull');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'core.test'));
        $this->assertSame('https://core.test', config('core-web.client.base_url'));
        $this->assertSame('local', config('core-web.client.client_id'));
        $this->assertSame('/local/ca.pem', config('core-web.client.ca_bundle'));
    }

    public function test_the_pinned_copy_outlives_its_ttls_without_pulling(): void
    {
        $this->artisan('core:pull')->assertSuccessful();
        Http::swap(new Factory);
        Http::fake();

        $this->travel(30)->days();

        $this->assertSame(['prod-1', 'prod-2'], $this->documents());
        Http::assertNothingSent();
    }

    public function test_core_warm_and_pings_leave_a_pinned_copy_alone(): void
    {
        $this->artisan('core:pull')->assertSuccessful();

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
        $this->artisan('core:pull')->assertSuccessful();

        $this->artisan('core:pull', ['--release' => true])->expectsOutputToContain('things: released.')->assertSuccessful();

        $this->assertNull(Cache::get('core:things:v1:last_good'));
        $this->assertSame(['local-1'], $this->documents());
        $this->artisan('core:warm', ['type' => 'things'])->expectsOutputToContain('things: 1 documents')->assertSuccessful();
    }

    public function test_a_snapshot_that_accepts_empty_pins_an_empty_copy_and_releases_it(): void
    {
        app(SnapshotRegistry::class)->register($this->snapshot('empty')->emptyWhen(fn () => false));
        $this->fake([], ['local-1']);

        $this->artisan('core:pull', ['type' => 'empty'])->expectsOutputToContain('empty: 0 documents pinned')->assertSuccessful();
        $this->artisan('core:pull', ['type' => 'empty', '--release' => true])->expectsOutputToContain('empty: released.')->assertSuccessful();

        $this->assertNull(Cache::get('core:empty:v1:fresh'));
    }

    public function test_an_empty_pull_pins_nothing_and_keeps_its_short_ttl(): void
    {
        $this->fake([], ['local-1']);

        $this->artisan('core:pull')->expectsOutputToContain('things: nothing pinned')->assertFailed();

        $this->travel(2)->minutes();
        $this->assertSame(['local-1'], $this->documents());
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
        $this->artisan('core:pull')->assertSuccessful();
        Cache::forget('core:things:v1:fresh');
        Cache::forget('core:things:v1:last_good');

        $this->assertSame(['local-1'], $this->documents());
        $this->assertNull((new SnapshotStore(Cache::store(), app('log')))->pinned(app(SnapshotRegistry::class)->get('things')));
    }

    public function test_a_warmer_that_fails_after_pinning_is_reported_as_both(): void
    {
        app(SnapshotRegistry::class)->register($this->snapshot('warmed'), warm: function () {
            app(SnapshotStore::class)->refresh(app(SnapshotRegistry::class)->get('warmed'));

            return 1;
        });

        $this->artisan('core:pull', ['type' => 'warmed'])->expectsOutputToContain('warmed: pinned from prod.test, but its warmer exited with 1')->assertFailed();
    }

    public function test_pulling_again_replaces_the_pin(): void
    {
        $this->artisan('core:pull')->assertSuccessful();
        $this->fake(['prod-3'], ['local-1']);

        $this->artisan('core:pull', ['type' => 'things'])->expectsOutputToContain('things: 1 documents pinned')->assertSuccessful();

        $this->assertSame(['prod-3'], $this->documents());
    }

    public function test_a_failed_pull_pins_nothing_and_keeps_an_earlier_pin(): void
    {
        $this->artisan('core:pull')->assertSuccessful();
        $this->fake(null, ['local-1']);

        $this->artisan('core:pull')->expectsOutputToContain('things: nothing pinned from prod.test')->assertFailed();

        $this->assertSame(['prod-1', 'prod-2'], $this->documents());
        $this->assertNotNull((new SnapshotStore(Cache::store(), app('log')))->pinned(app(SnapshotRegistry::class)->get('things')));
    }

    public function test_a_failed_first_pull_leaves_core_warm_in_charge(): void
    {
        $this->fake(null, ['local-1']);

        $this->artisan('core:pull')->assertFailed();

        $this->assertNull((new SnapshotStore(Cache::store(), app('log')))->pinned(app(SnapshotRegistry::class)->get('things')));
    }

    public function test_a_snapshot_s_warmer_runs_on_the_pull_client_and_is_pinned(): void
    {
        app(SnapshotRegistry::class)->register($this->snapshot('warmed'), warm: function () {
            app(SnapshotStore::class)->refresh(app(SnapshotRegistry::class)->get('warmed'));

            return 0;
        });

        $this->artisan('core:pull', ['type' => 'warmed'])->expectsOutputToContain('warmed: pinned from prod.test')->assertSuccessful();

        $this->assertSame(['prod-1', 'prod-2'], array_keys(Cache::get('core:warmed:v1:last_good')));
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

    public function test_it_needs_a_source_to_pull_from(): void
    {
        config(['core-web.pull.client_secret' => '']);

        $this->artisan('core:pull')->expectsOutputToContain('CORE_PULL_CLIENT_SECRET')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->artisan('core:pull', ['type' => 'nope'])->expectsOutputToContain('No snapshot [nope] is registered')->assertFailed();
    }
}
