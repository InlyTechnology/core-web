<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\Snapshots\RefreshSnapshotJob;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotChange;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\StaticCaching\SiteCache;
use Inly\CoreWeb\Tests\TestCase;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;

/**
 * The cache layers every Core snapshot shares: a fresh copy, the last good
 * copy behind it and a short backoff after a failed or empty pull. Core is a
 * closure here; what it returns (or throws) is set per test.
 */
class SnapshotStoreTest extends TestCase
{
    /** @var array<string, array<string, mixed>>|RuntimeException */
    private array|RuntimeException $core = [];

    private int $pulls = 0;

    /** @var list<array{?string, list<string>}> */
    private array $changes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->core = ['a' => ['id' => 'a', 'site' => 'default'], 'b' => ['id' => 'b', 'site' => 'other']];
    }

    private function snapshot(): Snapshot
    {
        return Snapshot::make('things')
            ->ttl(fresh: 900, stale: 86400, retry: 60)
            ->pull(function (): array {
                $this->pulls++;

                if ($this->core instanceof RuntimeException) {
                    throw $this->core;
                }

                return $this->core;
            })
            ->onChange(function (?string $partition, array $documents): void {
                $this->changes[] = [$partition, array_keys($documents)];
            });
    }

    private function partitioned(): Snapshot
    {
        return $this->snapshot()->partitionBy(
            fn (array $document) => in_array($document['site'] ?? null, ['default', 'other'], true) ? $document['site'] : null,
            ['default', 'other'],
        );
    }

    /** A new store is a new request: nothing is memoised. */
    private function store(?LoggerInterface $logger = null): SnapshotStore
    {
        return new SnapshotStore(Cache::store(), $logger ?? app('log'));
    }

    public function test_a_cold_read_pulls_once_and_later_reads_hit_the_cache(): void
    {
        $snapshot = $this->snapshot();

        $this->assertSame(['a', 'b'], array_keys($this->store()->documents($snapshot)));
        $this->assertSame(['a', 'b'], array_keys($this->store()->documents($snapshot)));

        $read = $this->store()->read($snapshot);
        $this->assertFalse($read->stale);
        $this->assertFalse($read->degraded);
        $this->assertSame(1, $this->pulls);
        $this->assertIsString(Cache::get('core:things:v1:fresh'), 'the fresh key marks the stored copy current');
        $this->assertSame(['a', 'b'], array_keys(Cache::get('core:things:v1:last_good')));
    }

    public function test_a_copy_stored_inline_under_the_fresh_key_is_still_read(): void
    {
        $snapshot = $this->snapshot();
        Cache::put($snapshot->key('fresh'), ['a' => ['id' => 'a']], 900);

        $read = $this->store()->read($snapshot);

        $this->assertSame(['a'], array_keys($read->documents));
        $this->assertFalse($read->stale);
        $this->assertSame(0, $this->pulls);
    }

    public function test_a_fresh_marker_without_its_copy_is_cold(): void
    {
        $snapshot = $this->snapshot();
        Cache::put($snapshot->key('fresh'), 'gone', 900);

        $this->assertSame(['a', 'b'], array_keys($this->store()->documents($snapshot)));
        $this->assertSame(1, $this->pulls);
    }

    public function test_a_copy_lost_under_its_marker_is_written_again_by_the_next_pull(): void
    {
        $snapshot = $this->snapshot();
        $this->store()->refresh($snapshot);
        Cache::forget($snapshot->key('last_good'));

        $this->assertSame(['a', 'b'], array_keys($this->store()->documents($snapshot)), 'the read pulls, as on a cold cache');
        $this->assertSame(['a', 'b'], array_keys(Cache::get($snapshot->key('last_good'))), 'and stores the copy again');
    }

    public function test_a_read_is_kept_for_the_rest_of_the_request(): void
    {
        $snapshot = $this->snapshot();
        $store = $this->store();
        $store->documents($snapshot);

        Cache::flush();

        $this->assertSame(['a', 'b'], array_keys($store->documents($snapshot)));
        $this->assertSame(1, $this->pulls);
    }

    public function test_a_render_never_pulls_while_a_last_good_copy_exists(): void
    {
        $snapshot = $this->snapshot();
        $this->store()->refresh($snapshot);
        Cache::forget($snapshot->key('fresh'));
        $this->core['c'] = ['id' => 'c'];

        $read = $this->store()->read($snapshot);

        $this->assertSame(['a', 'b'], array_keys($read->documents));
        $this->assertTrue($read->stale);
        $this->assertSame(1, $this->pulls, 'the warm job refreshes, not the render');
    }

    public function test_an_outage_keeps_serving_the_last_good_copy_and_backs_off(): void
    {
        $snapshot = $this->snapshot();
        $this->store()->refresh($snapshot);
        Cache::forget($snapshot->key('fresh'));
        $this->core = new RuntimeException('secret-token in a message');
        $logger = $this->spy(LoggerInterface::class);

        $this->assertNull($this->store($logger)->refresh($snapshot));

        $this->assertTrue(Cache::has($snapshot->key('backoff')));
        $read = $this->store()->read($snapshot);
        $this->assertSame(['a', 'b'], array_keys($read->documents));
        $this->assertTrue($read->stale);
        $this->assertFalse($read->degraded);
        $logger->shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context === ['snapshot' => 'things', 'reason' => 'exception:RuntimeException'],
        );
    }

    public function test_nothing_cached_and_core_down_is_degraded_and_backs_off(): void
    {
        $snapshot = $this->snapshot();
        $this->core = new RuntimeException('down');

        $read = $this->store()->read($snapshot);

        $this->assertSame([], $read->documents);
        $this->assertTrue($read->degraded);
        $this->assertTrue($this->store()->read($snapshot)->degraded);
        $this->assertSame(1, $this->pulls, 'the backoff holds the next render off core');
    }

    public function test_an_empty_pull_never_replaces_the_last_good_copy(): void
    {
        $snapshot = $this->snapshot();
        $this->store()->refresh($snapshot);
        $this->core = [];

        $this->assertNull($this->store()->refresh($snapshot));
        Cache::forget($snapshot->key('fresh'));

        $this->assertTrue(Cache::has($snapshot->key('backoff')));
        $this->assertSame(['a', 'b'], array_keys($this->store()->documents($snapshot)));
    }

    public function test_an_empty_pull_on_a_cold_cache_is_kept_briefly(): void
    {
        $snapshot = $this->snapshot();
        $this->core = [];

        $this->assertSame(0, $this->store()->refresh($snapshot));

        $read = $this->store()->read($snapshot);
        $this->assertSame([], $read->documents);
        $this->assertFalse($read->degraded);
        $this->assertNull(Cache::get($snapshot->key('last_good')));
    }

    public function test_a_cold_empty_pull_announces_nothing_until_a_good_pull_does(): void
    {
        $snapshot = $this->snapshot();
        $this->core = [];

        $this->store()->refresh($snapshot);

        $this->assertSame([], $this->changes);
        $this->assertNull(Cache::get($snapshot->key('fingerprint')));

        $this->core = ['a' => ['id' => 'a']];
        $this->store()->refresh($snapshot);

        $this->assertSame([[null, ['a']]], $this->changes);
    }

    public function test_a_cold_read_while_another_render_pulls_is_degraded_without_pulling(): void
    {
        $snapshot = $this->snapshot();
        $pulling = Cache::lock($snapshot->key('pulling'), 10);
        $this->assertTrue($pulling->get());

        $read = $this->store()->read($snapshot);

        $this->assertSame([], $read->documents);
        $this->assertTrue($read->degraded);
        $this->assertSame(0, $this->pulls);

        $pulling->release();
        $this->assertSame(['a', 'b'], array_keys($this->store()->documents($snapshot)));
        $this->assertSame(1, $this->pulls);
        $this->assertTrue(Cache::lock($snapshot->key('pulling'), 10)->get(), 'the winner releases the lock');
    }

    public function test_the_emptiness_guard_is_the_snapshot_s_own(): void
    {
        $snapshot = $this->snapshot()->emptyWhen(fn (array $documents) => ! isset($documents['a']));
        $this->store()->refresh($snapshot);
        unset($this->core['a']);

        $this->assertNull($this->store()->refresh($snapshot), 'b alone counts as empty');
        $this->assertSame(['a', 'b'], array_keys(Cache::get($snapshot->key('last_good'))));
    }

    public function test_a_partitioned_snapshot_pulls_once_and_stores_each_partition(): void
    {
        $snapshot = $this->partitioned();
        $this->core['x'] = ['id' => 'x', 'site' => 'trucks'];

        $this->assertSame(['b'], array_keys($this->store()->documents($snapshot, 'other')));
        $this->assertSame(['a'], array_keys($this->store()->documents($snapshot, 'default')));
        $this->assertSame(1, $this->pulls);
        $this->assertSame(['b'], array_keys(Cache::get('core:things:v1:other:last_good')));
        $this->assertSame(['a'], array_keys(Cache::get('core:things:v1:default:last_good')));
    }

    public function test_an_empty_partition_is_empty_not_degraded(): void
    {
        $snapshot = $this->partitioned();
        unset($this->core['b']);

        $read = $this->store()->read($snapshot, 'other');

        $this->assertSame([], $read->documents);
        $this->assertFalse($read->degraded);
    }

    public function test_a_partition_must_be_declared(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->read($this->partitioned(), 'trucks');
    }

    public function test_the_version_moves_every_key(): void
    {
        $snapshot = $this->snapshot()->version(2);
        $this->store()->refresh($snapshot);

        $this->assertNotNull(Cache::get('core:things:v2:fresh'));
        $this->assertIsArray(Cache::get('core:things:v2:last_good'));
        $this->assertNull(Cache::get('core:things:v1:fresh'));
    }

    public function test_only_a_changed_partition_is_announced(): void
    {
        $snapshot = $this->partitioned();

        $this->store()->refresh($snapshot);
        $this->assertSame([['default', ['a']], ['other', ['b']]], $this->changes, 'the first pull announces every partition');

        $this->changes = [];
        $this->store()->refresh($snapshot);
        $this->assertSame([], $this->changes, 'an unchanged pull announces nothing');

        $this->core['b']['name'] = 'renamed';
        $this->store()->refresh($snapshot);
        $this->assertSame([['other', ['b']]], $this->changes);
    }

    public function test_the_fingerprint_decides_what_counts_as_a_change(): void
    {
        $snapshot = $this->snapshot()->fingerprintWith(fn (array $documents) => implode(',', array_keys($documents)));
        $this->store()->refresh($snapshot);
        $this->changes = [];

        $this->core['a']['viewed_at'] = now()->toIso8601String();
        $this->store()->refresh($snapshot);
        $this->assertSame([], $this->changes, 'a field outside the fingerprint');

        $this->core['c'] = ['id' => 'c'];
        $this->store()->refresh($snapshot);
        $this->assertSame([[null, ['a', 'b', 'c']]], $this->changes);
    }

    public function test_a_failing_listener_is_retried_on_the_next_pull(): void
    {
        $fail = true;
        $snapshot = $this->snapshot()->onChange(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('flush failed');
            }
        });

        $this->assertSame(2, $this->store()->refresh($snapshot), 'the pull itself is stored');
        $fail = false;
        $this->changes = [];
        $this->store()->refresh($snapshot);

        $this->assertSame([[null, ['a', 'b']]], $this->changes);
    }

    public function test_a_failed_or_empty_pull_announces_nothing(): void
    {
        $snapshot = $this->snapshot();
        $this->store()->refresh($snapshot);
        $this->changes = [];

        $this->core = [];
        $this->store()->refresh($snapshot);
        $this->core = new RuntimeException('down');
        $this->store()->refresh($snapshot);

        $this->assertSame([], $this->changes);
    }

    public function test_a_changed_partition_flushes_its_own_site(): void
    {
        $cache = $this->spy(SiteCache::class);
        $snapshot = $this->partitioned()->onChange(SiteCache::flushOnChange());

        $this->store()->refresh($snapshot);
        $this->core['b']['name'] = 'renamed';
        $this->store()->refresh($snapshot);

        $cache->shouldHaveReceived('flush')->with(\Mockery::on(fn (SiteObject $site) => $site->handle() === 'default'))->once();
        $cache->shouldHaveReceived('flush')->with(\Mockery::on(fn (SiteObject $site) => $site->handle() === 'other'))->twice();
    }

    public function test_an_unpartitioned_snapshot_flushes_the_default_site(): void
    {
        $cache = $this->spy(SiteCache::class);

        $this->store()->refresh($this->snapshot()->onChange(SiteCache::flushOnChange()));

        $cache->shouldHaveReceived('flush')->with(\Mockery::on(fn (SiteObject $site) => $site->handle() === Site::default()->handle()))->once();
    }

    public function test_core_warm_pulls_a_registered_type_once(): void
    {
        app(SnapshotRegistry::class)->register($this->snapshot());

        $this->artisan('core:warm', ['type' => 'things'])->expectsOutputToContain('things: 2 documents')->assertSuccessful();

        $this->assertSame(1, $this->pulls);
        $this->assertIsArray(Cache::get('core:things:v1:last_good'));
    }

    public function test_core_warm_without_a_type_warms_every_snapshot(): void
    {
        $warmed = [];
        app(SnapshotRegistry::class)
            ->register($this->snapshot())
            ->register(Snapshot::make('others'), warm: function () use (&$warmed): int {
                $warmed[] = 'others';

                return 0;
            });

        $this->artisan('core:warm')->assertSuccessful();

        $this->assertSame(1, $this->pulls);
        $this->assertSame(['others'], $warmed);
    }

    public function test_core_warm_fails_on_a_failed_pull_or_an_unknown_type(): void
    {
        app(SnapshotRegistry::class)->register($this->snapshot());
        $this->core = new RuntimeException('down');

        $this->artisan('core:warm', ['type' => 'things'])->expectsOutputToContain('pull failed')->assertFailed();
        $this->artisan('core:warm', ['type' => 'nothing'])->assertFailed();
    }

    public function test_core_warm_keeps_no_warmed_snapshot_in_memory(): void
    {
        app(SnapshotRegistry::class)->register($this->snapshot());
        $store = app(SnapshotStore::class);

        $this->artisan('core:warm', ['type' => 'things'])->assertSuccessful();
        Cache::flush();

        $store->read($this->snapshot());

        $this->assertSame(2, $this->pulls, 'the read after warming goes back to the cache');
    }

    public function test_the_refresh_job_warms_its_type(): void
    {
        app(SnapshotRegistry::class)->register($this->snapshot());

        (new RefreshSnapshotJob('things'))->handle(app(SnapshotRegistry::class), app(SnapshotStore::class));
        $this->assertSame(1, $this->pulls);

        $this->core = new RuntimeException('down');
        $this->expectException(RuntimeException::class);
        (new RefreshSnapshotJob('things'))->handle(app(SnapshotRegistry::class), app(SnapshotStore::class));
    }

    /** Core's single-object read for the tests below: what `$this->one` holds for an id. */
    private array $one = [];

    private int $singleReads = 0;

    private function single(Snapshot $snapshot): Snapshot
    {
        return $snapshot->pullOne(function (string $id): array {
            $this->singleReads++;

            if (($this->one[$id] ?? null) instanceof RuntimeException) {
                throw $this->one[$id];
            }

            return isset($this->one[$id]) ? [$this->one[$id]['id'] => $this->one[$id]] : [];
        });
    }

    public function test_a_ping_for_one_object_patches_it_into_the_fresh_copy(): void
    {
        $snapshot = $this->single($this->partitioned());
        $this->store()->refresh($snapshot);
        $this->changes = [];
        $this->one['a'] = ['id' => 'a', 'site' => 'default', 'name' => 'new'];
        $this->one['c'] = ['id' => 'c', 'site' => 'other'];

        $this->assertSame(1, $this->store()->refreshOne($snapshot, 'a', 'a'));
        $this->assertSame(1, $this->store()->refreshOne($snapshot, 'c', 'c'));

        $this->assertSame(1, $this->pulls, 'no full pull');
        $this->assertSame(2, $this->singleReads);
        $this->assertSame('new', $this->store()->documents($snapshot, 'default')['a']['name']);
        $this->assertSame(['b', 'c'], array_keys($this->store()->documents($snapshot, 'other')));
        $this->assertSame([['default', ['a']], ['other', ['b', 'c']]], $this->changes, 'only the partitions that moved');
    }

    public function test_an_object_core_no_longer_answers_for_or_deleted_leaves_the_snapshot(): void
    {
        $this->core['c'] = ['id' => 'c', 'site' => 'other'];
        $snapshot = $this->single($this->partitioned());
        $this->store()->refresh($snapshot);

        $this->assertSame(0, $this->store()->refreshOne($snapshot, 'core-1', 'a'));
        $this->assertSame([], $this->store()->documents($snapshot, 'default'));

        $this->assertSame(0, $this->store()->refreshOne($snapshot, 'core-2', 'b', deleted: true));
        $this->assertSame(['c'], array_keys($this->store()->documents($snapshot, 'other')));
        $this->assertSame(1, $this->singleReads, 'a deletion reads nothing');
        $this->assertSame(1, $this->pulls);

        // A patch that would empty the snapshot is left to a full pull and its empty rule.
        $this->store()->refreshOne($snapshot, 'core-3', 'c', deleted: true);
        $this->assertSame(2, $this->pulls);
    }

    public function test_what_a_patch_cannot_be_trusted_with_pulls_everything(): void
    {
        // Cold: nothing to patch.
        $snapshot = $this->single($this->snapshot());
        $this->assertSame(2, $this->store()->refreshOne($snapshot, 'a', 'a'));
        $this->assertSame(1, $this->pulls);

        // Only the last good copy: a patch would pass it off as fresh.
        Cache::forget($snapshot->key('fresh'));
        $this->store()->refreshOne($snapshot, 'a', 'a');
        $this->assertSame(2, $this->pulls);

        // No single-object read, or a removal without the key it is stored under.
        $this->store()->refreshOne($this->snapshot(), 'a', 'a');
        $this->store()->refreshOne($snapshot, 'a', null, deleted: true);
        $this->assertSame(4, $this->pulls);
        $this->assertSame(0, $this->singleReads);
    }

    public function test_a_failed_single_read_keeps_the_cached_copy(): void
    {
        $snapshot = $this->single($this->snapshot());
        $this->store()->refresh($snapshot);
        $this->one['a'] = new RuntimeException('down');

        $this->assertNull($this->store()->refreshOne($snapshot, 'a', 'a'));
        $this->assertSame(['a', 'b'], array_keys($this->store()->documents($snapshot)));
        $this->assertSame(1, $this->pulls);
    }

    public function test_the_refresh_job_patches_the_object_a_ping_names(): void
    {
        app(SnapshotRegistry::class)->register($this->single($this->snapshot()));
        (new RefreshSnapshotJob('things'))->handle(app(SnapshotRegistry::class), app(SnapshotStore::class));
        $job = new RefreshSnapshotJob('things', '17', 'saved', 'A');
        $job->handle(app(SnapshotRegistry::class), $this->store());

        $this->assertSame('things:17:saved', $job->uniqueId());
        $this->assertSame(1, $this->pulls);
        $this->assertSame(1, $this->singleReads);
        $this->assertSame(['b'], array_keys($this->store()->documents(app(SnapshotRegistry::class)->get('things'))), 'Core answers nothing for 17, so the document it named (A, any case) goes');
        $this->one['17'] = ['id' => 'x'];
        (new RefreshSnapshotJob('things', '17', 'saved', 'x'))->handle(app(SnapshotRegistry::class), $this->store());
        $this->assertSame(['b', 'x'], array_keys($this->store()->documents(app(SnapshotRegistry::class)->get('things'))));
    }

    public function test_a_pull_that_changed_nothing_writes_nothing_but_the_fresh_marker(): void
    {
        $snapshot = $this->snapshot();
        $this->store()->refresh($snapshot);
        $this->changes = [];
        // Were the documents written again, the pull's copy would replace this one.
        Cache::put($snapshot->key('last_good'), ['untouched' => ['id' => 'untouched']], 86400);
        Cache::forget($snapshot->key('fresh'));

        $this->assertSame(2, $this->store()->refresh($snapshot));

        $this->assertSame(['untouched'], array_keys(Cache::get($snapshot->key('last_good'))));
        $read = $this->store()->read($snapshot);
        $this->assertFalse($read->stale, 'the fresh marker is renewed');
        $this->assertSame([], $this->changes);
    }

    public function test_a_changed_pull_is_written_and_a_reordered_one_too(): void
    {
        $snapshot = $this->snapshot();
        $this->store()->refresh($snapshot);

        $this->core = ['b' => $this->core['b'], 'a' => $this->core['a']];
        $this->store()->refresh($snapshot);
        $this->assertSame(['b', 'a'], array_keys(Cache::get($snapshot->key('last_good'))));

        $this->core['c'] = ['id' => 'c'];
        $this->store()->refresh($snapshot);
        $this->assertSame(['b', 'a', 'c'], array_keys(Cache::get($snapshot->key('last_good'))));
    }

    public function test_an_unchanged_copy_is_written_again_before_its_last_good_copy_could_expire(): void
    {
        $snapshot = $this->snapshot();
        $this->store()->refresh($snapshot);
        Cache::put($snapshot->key('last_good'), ['untouched' => ['id' => 'untouched']], 86400);

        $this->travel(11)->hours();
        $this->store()->refresh($snapshot);
        $this->assertSame(['untouched'], array_keys(Cache::get($snapshot->key('last_good'))));

        $this->travel(2)->hours();
        $this->store()->refresh($snapshot);
        $this->assertSame(['a', 'b'], array_keys(Cache::get($snapshot->key('last_good'))), 'past half the stale lifetime (a day here)');
    }

    public function test_a_listener_is_told_what_changed_since_it_was_last_told(): void
    {
        $seen = [];
        $snapshot = $this->partitioned()->onChange(function (?string $partition, array $documents, ?SnapshotChange $change) use (&$seen): void {
            $seen[] = [$partition, $change?->ids(), $change?->before('b')['name'] ?? null, $change?->after('b')['name'] ?? null];
        });

        $this->store()->refresh($snapshot);
        $this->assertSame([['default', null, null, null], ['other', null, null, null]], $seen, 'the first pull has nothing to compare with');

        $seen = [];
        $this->core['b']['name'] = 'renamed';
        $this->core['c'] = ['id' => 'c', 'site' => 'other'];
        $this->store()->refresh($snapshot);
        $this->assertSame([['other', ['b', 'c'], null, 'renamed']], $seen);

        $seen = [];
        unset($this->core['c']);
        $this->store()->refresh($snapshot);
        $this->assertSame([['other', ['c'], null, null]], $seen, 'a removed document');
    }

    public function test_after_a_failed_listener_what_changed_is_unknown(): void
    {
        $fail = true;
        $seen = [];
        $snapshot = $this->snapshot()->onChange(function (?string $partition, array $documents, ?SnapshotChange $change) use (&$fail, &$seen): void {
            $seen[] = $change?->ids();

            if ($fail) {
                throw new RuntimeException('flush failed');
            }
        });
        $this->store()->refresh($snapshot);
        $fail = false;
        $this->store()->refresh($snapshot);
        $this->assertSame([null, null], $seen, 'retried on an unchanged pull, without a change set');

        $fail = true;
        $this->core['a']['name'] = 'one';
        $this->store()->refresh($snapshot);
        $fail = false;
        $this->core['b']['name'] = 'two';
        $this->store()->refresh($snapshot);

        $this->assertSame([null, null, ['a'], null], $seen, 'the missed change to a is not lost in a diff against b');
    }

    public function test_a_ping_for_an_object_that_reads_as_stored_writes_nothing(): void
    {
        $snapshot = $this->single($this->snapshot());
        $this->store()->refresh($snapshot);
        $this->changes = [];
        Cache::put($snapshot->key('last_good'), ['a' => ['id' => 'a', 'site' => 'default'], 'b' => ['id' => 'b', 'site' => 'other'], 'sentinel' => []], 86400);
        $this->one['a'] = ['id' => 'a', 'site' => 'default'];

        $this->assertSame(0, $this->store()->refreshOne($snapshot, 'a', 'a'));
        $this->assertArrayHasKey('sentinel', Cache::get($snapshot->key('last_good')));
        $this->assertSame([], $this->changes);
    }

    public function test_a_ping_for_an_object_that_is_not_this_snapshot_s_writes_nothing(): void
    {
        $snapshot = $this->single($this->snapshot());
        $this->store()->refresh($snapshot);
        $this->changes = [];
        Cache::put($snapshot->key('last_good'), ['a' => ['id' => 'a', 'site' => 'default'], 'b' => ['id' => 'b', 'site' => 'other'], 'sentinel' => []], 86400);

        // pullOne() keeps nothing of the object (another catalog's model): its key was never stored here.
        $this->assertSame(0, $this->store()->refreshOne($snapshot, 'core-9', 'z'));

        $this->assertArrayHasKey('sentinel', Cache::get($snapshot->key('last_good')));
        $this->assertSame(1, $this->pulls, 'no full pull');
        $this->assertSame([], $this->changes);
    }

    public function test_a_patch_tells_the_listener_exactly_what_changed(): void
    {
        $seen = [];
        $snapshot = $this->single($this->snapshot())->onChange(function (?string $partition, array $documents, ?SnapshotChange $change) use (&$seen): void {
            $seen[] = $change?->ids();
        });
        $this->store()->refresh($snapshot);
        $seen = [];
        $this->one['a'] = ['id' => 'a', 'site' => 'default', 'name' => 'new'];

        $this->store()->refreshOne($snapshot, 'a', 'a');
        $this->store()->refreshOne($snapshot, 'core-2', 'b', deleted: true);

        $this->assertSame([['a'], ['b']], $seen);
        $this->assertSame(['a'], array_keys($this->store()->documents($snapshot)));
    }

    public function test_a_change_to_named_pages_flushes_only_those_once_the_first_pull_was_announced(): void
    {
        $cache = $this->spy(SiteCache::class);
        $snapshot = $this->single($this->partitioned())
            ->dependents(fn (array $doc, string $id) => ['/things', "/things/{$id}"])
            ->onChange(SiteCache::flushOnChange());

        $this->store()->refresh($snapshot);
        $this->one['b'] = ['id' => 'b', 'site' => 'other', 'name' => 'renamed'];
        $this->store()->refreshOne($snapshot, 'b', 'b');

        $cache->shouldHaveReceived('flush')->twice();
        $cache->shouldHaveReceived('flushPaths')->with(\Mockery::on(fn (SiteObject $site) => $site->handle() === 'other'), ['/things', '/things/b'])->once();
    }

    public function test_the_fresh_marker_never_outlives_the_copy_it_vouches_for(): void
    {
        $snapshot = $this->snapshot()->ttl(fresh: 7200, stale: 3600);
        $this->store()->refresh($snapshot);

        $this->travel(3601)->seconds();

        $this->assertNull(Cache::get($snapshot->key('fresh')));
        $this->assertNull(Cache::get($snapshot->key('last_good')));
    }
}
