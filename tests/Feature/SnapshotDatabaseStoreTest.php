<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\TestCase;
use InvalidArgumentException;
use Orchestra\Testbench\Attributes\WithEnv;

/** The `database` snapshot store (Laravel Cloud): its own tables, apart from a database default cache that cache:clear empties. */
#[WithEnv('CORE_SNAPSHOT_STORE', 'database')]
class SnapshotDatabaseStoreTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'database');
        $app['config']->set('cache.stores.database', ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['core-web.snapshots.store' => 'snapshots']);

        (require __DIR__.'/../../database/migrations/2026_09_29_000000_create_core_snapshots_cache_table.php')->up();

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration');
        });
    }

    public function test_the_provider_adds_a_database_store_on_its_own_tables(): void
    {
        $this->assertSame([
            'driver' => 'database',
            'connection' => null,
            'table' => 'core_snapshots_cache',
            'lock_connection' => null,
            'lock_table' => 'core_snapshots_cache_locks',
        ], config('cache.stores.snapshots'));
        $this->assertInstanceOf(DatabaseStore::class, SnapshotStore::repository()->getStore());
    }

    public function test_a_snapshot_is_stored_in_its_own_table_and_outlives_cache_clear(): void
    {
        $pulls = 0;
        $snapshot = Snapshot::make('things')->pull(function () use (&$pulls): array {
            $pulls++;

            return ['a' => ['id' => 'a']];
        });

        $this->assertSame(1, app(SnapshotStore::class)->refresh($snapshot));
        Cache::put('other', true);

        $this->assertGreaterThan(0, DB::table('core_snapshots_cache')->count());
        $this->assertSame(1, DB::table('cache')->count(), 'only the default store\'s own entry');

        $this->artisan('cache:clear')->assertSuccessful();
        $this->app->forgetScopedInstances();

        $this->assertSame(0, DB::table('cache')->count(), 'the default store was cleared');
        $this->assertSame(['a'], array_keys(app(SnapshotStore::class)->documents($snapshot)));
        $this->assertSame(1, $pulls, 'read back from the snapshot store, not pulled again');
    }

    public function test_the_write_lock_lives_in_the_snapshot_lock_table(): void
    {
        $snapshot = Snapshot::make('things');
        $lock = SnapshotStore::repository()->lock($snapshot->key('writing'), 5);

        $this->assertTrue($lock->get());
        $this->assertSame(1, DB::table('core_snapshots_cache_locks')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());
        $this->assertFalse(SnapshotStore::repository()->lock($snapshot->key('writing'), 5)->get());

        $lock->release();

        $this->assertSame(0, DB::table('core_snapshots_cache_locks')->count());
    }

    public function test_the_migration_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'core-web-migrations', '--force' => true])->assertSuccessful();

        $published = File::glob(database_path('migrations/*_create_core_snapshots_cache_table.php'));

        $this->assertCount(1, $published);
        File::delete($published);
    }

    public function test_an_unknown_store_driver_is_refused(): void
    {
        $stores = config('cache.stores');
        unset($stores['snapshots']);
        config(['cache.stores' => $stores, 'core-web.snapshots.store_driver' => 'redis']);

        $this->expectException(InvalidArgumentException::class);

        (new CoreWebServiceProvider($this->app))->register();
    }
}
