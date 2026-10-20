<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inly\CoreWeb\ContentSync\DatabaseSyncTokenStore;
use Inly\CoreWeb\ContentSync\SyncToken;
use Inly\CoreWeb\ContentSync\SyncTokens;
use Inly\CoreWeb\ContentSync\SyncTokenStore;
use Inly\CoreWeb\ContentSync\SyncTokenStoreUnavailable;
use RuntimeException;

/** Every SyncAuthTest case again with tokens in the database, as a site behind several servers keeps them. */
class SyncAuthDatabaseTest extends SyncAuthTest
{
    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_10_20_000000_create_core_web_sync_tokens_table.php';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('database.connections.tokens', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function tokenStore(): SyncTokenStore
    {
        return new DatabaseSyncTokenStore(DB::connection(), 'core_web_sync_tokens');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['core-web.content_sync.tokens' => ['driver' => 'database']]);
        (require self::MIGRATION)->up();
    }

    public function test_a_token_issued_on_one_server_is_accepted_on_another(): void
    {
        $ann = $this->cmsUser('ann@example.com', roles: ['editor']);
        [$token, $record] = SyncTokens::fromConfig()->issue($ann, 'ann@laptop');

        $this->travel(2)->minutes();
        $used = SyncTokens::fromConfig()->use($token);

        $this->assertSame($record->id, $used?->id);
        $this->assertSame(now()->addDays(30)->toIso8601String(), $used->expiresAt->toIso8601String());
        $this->assertSame(now()->addDays(30)->toIso8601String(), SyncTokens::fromConfig()->find($record->id)?->expiresAt->toIso8601String());
        $this->assertSame(1, DB::table('core_web_sync_tokens')->where('user_id', $ann->id())->count());
        $this->assertSame([SyncTokens::hash($token)], DB::table('core_web_sync_tokens')->pluck('hash')->all());
    }

    public function test_times_round_trip_in_another_app_timezone(): void
    {
        $timezone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Stockholm']);
        date_default_timezone_set('Europe/Stockholm');

        try {
            [$token, $record] = SyncTokens::fromConfig()->issue($this->cmsUser('ann@example.com', roles: ['editor']), 'ann@laptop');
            $this->travel(2)->minutes();
            $expires = now()->addDays(30);
            $this->assertSame($expires->toIso8601String(), SyncTokens::fromConfig()->use($token)?->expiresAt->toIso8601String());

            $this->travel(30)->seconds();
            $again = SyncTokens::fromConfig()->use($token);

            $this->assertSame($expires->toIso8601String(), $again?->expiresAt->toIso8601String());
            $this->assertSame($record->createdAt->toIso8601String(), $again->createdAt->toIso8601String());
            $this->assertNotSame('+00:00', $again->expiresAt->format('P'));
            $this->assertSame($expires->copy()->utc()->format('Y-m-d H:i:s'), DB::table('core_web_sync_tokens')->value('expires_at'));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_a_named_table_and_connection_are_used_for_the_migration_and_the_tokens(): void
    {
        config(['core-web.content_sync.tokens' => ['driver' => 'database', 'connection' => 'tokens', 'table' => 'machine_tokens']]);
        (require self::MIGRATION)->up();

        [$token] = SyncTokens::fromConfig()->issue($this->cmsUser('ann@example.com', roles: ['editor']), 'ann@laptop');

        $this->assertNotNull(SyncTokens::fromConfig()->use($token));
        $this->assertSame(1, DB::connection('tokens')->table('machine_tokens')->count());
        $this->assertSame(0, DB::table('core_web_sync_tokens')->count());
        $this->assertFalse(Schema::hasTable('machine_tokens'));
    }

    public function test_running_the_migration_twice_keeps_the_table(): void
    {
        SyncTokens::fromConfig()->issue($this->cmsUser('ann@example.com', roles: ['editor']), 'ann@laptop');

        (require self::MIGRATION)->up();

        $this->assertSame(1, DB::table('core_web_sync_tokens')->count());
    }

    public function test_a_query_error_on_an_existing_table_is_not_reported_as_a_missing_table(): void
    {
        Schema::create('broken_tokens', fn (Blueprint $table) => $table->string('other'));

        $this->expectException(QueryException::class);

        (new DatabaseSyncTokenStore(DB::connection(), 'broken_tokens'))->put(new SyncToken('id', 'hash', 'ann', 'ann@laptop', now()->toImmutable(), null, now()->toImmutable()));
    }

    public function test_expired_tokens_are_pruned_from_the_table(): void
    {
        $tokens = SyncTokens::fromConfig();
        $tokens->issue($this->cmsUser('ann@example.com', roles: ['editor']), 'ann@laptop');

        $this->travel(31)->days();

        $this->assertSame([], $tokens->all());
        $this->assertSame(0, DB::table('core_web_sync_tokens')->count());
    }

    public function test_a_missing_table_names_the_migration_instead_of_falling_back_to_files(): void
    {
        Schema::drop('core_web_sync_tokens');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('core-web-sync-tokens-migration');

        SyncTokens::fromConfig()->issue($this->cmsUser('ann@example.com', roles: ['editor']), 'ann@laptop');
    }

    public function test_the_tokens_page_names_the_migration_when_the_table_is_missing(): void
    {
        Schema::drop('core_web_sync_tokens');

        $this->actingAs($this->cmsUser('boss@example.com', super: true))
            ->get(cp_route('core-web.sync.tokens'))
            ->assertOk()
            ->assertSee('The signed-in machines cannot be listed')
            ->assertSee('core-web-sync-tokens-migration')
            ->assertDontSee('core_web_sync_tokens')
            ->assertDontSee('SQLSTATE');
    }

    public function test_the_tokens_page_points_at_the_connection_without_showing_it_when_the_database_is_unreachable(): void
    {
        $path = __DIR__.'/does-not-exist.sqlite';
        config(['database.connections.gone' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
        config(['core-web.content_sync.tokens' => ['driver' => 'database', 'connection' => 'gone']]);

        $this->actingAs($this->cmsUser('boss@example.com', super: true))
            ->get(cp_route('core-web.sync.tokens'))
            ->assertOk()
            ->assertSee('the database that holds the tokens cannot be reached')
            ->assertDontSee($path)
            ->assertDontSee('does-not-exist');
    }

    public function test_a_database_that_does_not_exist_is_reported_as_unreachable(): void
    {
        config(['database.connections.gone' => ['driver' => 'sqlite', 'database' => __DIR__.'/does-not-exist.sqlite', 'prefix' => '']]);

        $this->expectException(SyncTokenStoreUnavailable::class);
        $this->expectExceptionMessage('the database cannot be reached');

        (new DatabaseSyncTokenStore(DB::connection('gone'), 'core_web_sync_tokens'))->all();
    }

    public function test_its_own_tag_publishes_only_the_tokens_migration(): void
    {
        File::ensureDirectoryExists(database_path('migrations'));
        File::put(database_path('migrations/2026_01_01_000000_create_core_snapshots_cache_table.php'), '<?php');

        try {
            $this->artisan('vendor:publish', ['--tag' => 'core-web-sync-tokens-migration'])->assertSuccessful();

            $this->assertCount(1, File::glob(database_path('migrations/*_create_core_snapshots_cache_table.php')));
            $this->assertCount(1, File::glob(database_path('migrations/*_create_core_web_sync_tokens_table.php')));
        } finally {
            File::delete(File::glob(database_path('migrations/*_create_core_*.php')));
        }
    }

    public function test_the_migration_is_published_with_the_others(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'core-web-migrations', '--force' => true])->assertSuccessful();

        $published = File::glob(database_path('migrations/*_create_core_web_sync_tokens_table.php'));

        try {
            $this->assertCount(1, $published);
        } finally {
            File::delete(File::glob(database_path('migrations/*_create_core_*.php')));
        }
    }
}
