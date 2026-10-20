<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inly\CoreWeb\ContentSync\SyncTokens;
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
        $this->assertNull(DB::table('core_web_sync_tokens')->where('hash', $token)->first());
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
        $this->expectExceptionMessage('core-web-migrations');

        SyncTokens::fromConfig()->issue($this->cmsUser('ann@example.com', roles: ['editor']), 'ann@laptop');
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
