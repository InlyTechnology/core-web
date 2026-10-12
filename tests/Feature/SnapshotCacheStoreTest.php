<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Cache\FileStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\TestCase;

/** Snapshots live in their own cache store, which a deploy's cache:clear leaves alone. */
class SnapshotCacheStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/snapshots'.(ParallelTesting::token() ? '_'.ParallelTesting::token() : ''));
        config([
            'core-web.snapshots.store' => 'snapshots',
            'cache.stores.snapshots.path' => $this->path,
            'cache.stores.snapshots.lock_path' => $this->path,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    public function test_the_provider_adds_the_snapshots_file_store(): void
    {
        $this->assertSame('file', config('cache.stores.snapshots.driver'));
        $this->assertInstanceOf(FileStore::class, SnapshotStore::repository()->getStore());
    }

    public function test_a_snapshot_outlives_cache_clear(): void
    {
        $this->assertSame('file', config('cache.stores.snapshots.driver'));

        $pulls = 0;
        $snapshot = Snapshot::make('things')->pull(function () use (&$pulls): array {
            $pulls++;

            return ['a' => ['id' => 'a']];
        });

        $this->assertSame(1, app(SnapshotStore::class)->refresh($snapshot));
        Cache::put('other', true);

        $this->artisan('cache:clear')->assertSuccessful();
        $this->app->forgetScopedInstances();

        $this->assertNull(Cache::get('other'), 'the default store was cleared');
        $this->assertSame(['a'], array_keys(app(SnapshotStore::class)->documents($snapshot)));
        $this->assertSame(1, $pulls, 'read back from the snapshot store, not pulled again');
    }
}
