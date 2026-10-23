<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Inly\CoreWeb\Cloud\CloudDefaults;
use Inly\CoreWeb\Cloud\ImportContentCommand;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Tests\TestCase;
use LogicException;
use ReflectionClass;
use Statamic\Eloquent\Entries\UuidEntryModel;
use Statamic\Eloquent\ServiceProvider as EloquentDriver;

class CloudTest extends TestCase
{
    protected array $features = ['cloud'];

    /** @var array<string, string|false> env values a test changed, as they were before */
    private array $originalEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            $this->setEnv($key, $value === false ? null : $value);
        }

        parent::tearDown();
    }

    /** In an app the config is loaded before providers register, where a test's defineEnvironment comes after. */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('core-web.features.cloud', true);
        $app['config']->set('core-web.cloud.detected', true);
        // As a site's published config/statamic/eloquent-driver.php names its models.
        $app['config']->set('statamic.eloquent-driver.entries', ['driver' => 'file', 'model' => UuidEntryModel::class]);
    }

    private function setEnv(string $key, ?string $value): void
    {
        $this->originalEnv[$key] ??= getenv($key);

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    public function test_on_cloud_what_outlives_a_deploy_is_in_the_database(): void
    {
        // phpunit.xml sets SESSION_DRIVER, and Statamic's AddonTestCase puts users on the file repository after providers register.
        $this->setEnv('SESSION_DRIVER', null);
        CloudDefaults::apply($this->app);

        $this->assertSame('eloquent', config('statamic.eloquent-driver.entries.driver'));
        $this->assertSame('eloquent', config('statamic.eloquent-driver.collection_trees.driver'));
        $this->assertSame('eloquent', config('statamic.eloquent-driver.assets.driver'));
        $this->assertSame('eloquent', config('statamic.users.repository'));
        $this->assertSame('database', config('session.driver'));
        $this->assertSame('database', config('core-web.snapshots.store_driver'));
        $this->assertSame('database', config('core-web.content_sync.tokens.driver'));
        $this->assertSame('half', config('statamic.static_caching.strategy'));
        $this->assertFalse(config('statamic.git.enabled'));
        $this->assertTrue(config('statamic.revisions.enabled'));
        $this->assertTrue(config('statamic.editions.pro'));
        $this->assertFalse(config('statamic.stache.watcher'));
        $this->assertSame('file', config('cache.stores.outpost.driver'), 'the build runs artisan before the cache table exists');
        $this->assertTrue(CloudDefaults::contentInDatabase());
    }

    public function test_glide_caches_kept_in_one_container_are_turned_off(): void
    {
        foreach (['hybrid', true] as $mode) {
            config(['statamic.assets.image_manipulation.cache' => $mode]);

            CloudDefaults::apply($this->app);

            $this->assertFalse(config('statamic.assets.image_manipulation.cache'), 'a rendition in one container\'s public/img is missing on the next');
            $this->assertFalse(config('statamic.assets.image_manipulation.generate_presets_on_upload'));
        }
    }

    public function test_a_glide_cache_on_a_disk_of_its_own_is_kept(): void
    {
        config(['statamic.assets.image_manipulation.cache' => 'glide']);

        CloudDefaults::apply($this->app);

        $this->assertSame('glide', config('statamic.assets.image_manipulation.cache'));
    }

    public function test_definitions_stay_files_from_git(): void
    {
        $this->assertNotSame('eloquent', config('statamic.eloquent-driver.collections.driver'));
        $this->assertNotSame('eloquent', config('statamic.eloquent-driver.blueprints.driver'));
    }

    public function test_a_value_the_environment_sets_wins(): void
    {
        $this->setEnv('CACHE_STORE', 'redis');
        config(['cache.default' => 'redis']);

        CloudDefaults::apply($this->app);

        $this->assertSame('redis', config('cache.default'));
    }

    public function test_the_env_vars_value_is_used_where_the_published_config_hardcodes_the_key(): void
    {
        $this->setEnv('STATAMIC_USERS_REPOSITORY', 'eloquent');
        config(['statamic.users.repository' => 'file']);

        CloudDefaults::apply($this->app);

        $this->assertSame('eloquent', config('statamic.users.repository'));
    }

    public function test_import_refuses_without_the_drivers_tables(): void
    {
        $this->artisan('content:import')
            ->expectsOutputToContain('Missing tables: entries')
            ->assertFailed();
    }

    public function test_import_names_the_uuid_and_jsonb_columns_the_stock_migrations_make(): void
    {
        $postgres = fn (string $table): array => match ($table) {
            'entries' => [['name' => 'id', 'type_name' => 'uuid'], ['name' => 'origin_id', 'type_name' => 'uuid'], ['name' => 'slug', 'type_name' => 'varchar'], ['name' => 'data', 'type_name' => 'jsonb']],
            'trees' => [['name' => 'tree', 'type_name' => 'json'], ['name' => 'settings', 'type_name' => 'JSONB']],
            'revisions' => [['name' => 'key', 'type_name' => 'uuid']],
            default => [['name' => 'data', 'type_name' => 'json']],
        };

        $this->assertSame(['entries.id (uuid)', 'entries.origin_id (uuid)', 'entries.data (jsonb)', 'trees.settings (jsonb)'], ImportContentCommand::wrongColumns($postgres));
    }

    public function test_core_webs_copies_of_the_drivers_migrations_cover_every_content_table_without_uuid_or_jsonb(): void
    {
        $files = ServiceProvider::pathsToPublish(CoreWebServiceProvider::class, 'core-web-cloud-migrations');
        $source = implode("\n", array_map('file_get_contents', array_keys($files)));

        $this->assertNotEmpty($files);
        $this->assertDoesNotMatchRegularExpression('/->(uuid|jsonb)\(/', $source);

        foreach (['entries', 'trees', 'global_set_variables', 'taxonomy_terms', 'assets_meta', 'form_submissions', 'revisions', 'tokens'] as $table) {
            $this->assertStringContainsString("prefix('{$table}')", $source);
        }

        foreach ($files as $from => $to) {
            $this->assertSame(basename($from), basename($to), 'published under the driver\'s own name');
        }
    }

    public function test_core_webs_copies_differ_from_the_drivers_only_in_ids_and_json(): void
    {
        $driver = dirname((new ReflectionClass(EloquentDriver::class))->getFileName(), 2).'/database/migrations';

        foreach (array_keys(ServiceProvider::pathsToPublish(CoreWebServiceProvider::class, 'core-web-cloud-migrations')) as $file) {
            $name = basename($file);
            $original = file_exists("{$driver}/{$name}") ? "{$driver}/{$name}" : "{$driver}/entries/{$name}";
            $expected = str_replace(['->jsonb(', "->uuid('id')", "->uuid('origin_id')"], ['->json(', "->string('id')", "->string('origin_id')"], file_get_contents($original));

            $this->assertSame($expected, preg_replace('/^ *\/\/.*\n/m', '', file_get_contents($file)), $name);
        }
    }

    public function test_import_refuses_before_writing_when_an_entry_id_is_not_a_string(): void
    {
        config(['statamic.eloquent-driver.entries.driver' => 'eloquent']);
        $schema = Schema::getFacadeRoot();

        foreach (['entries', 'trees', 'global_set_variables', 'taxonomy_terms', 'assets_meta', 'form_submissions', 'revisions', 'tokens'] as $table) {
            $schema->create($table, function (Blueprint $blueprint) use ($table): void {
                $table === 'entries' ? $blueprint->id() : $blueprint->string('id');
                $blueprint->json('data')->nullable();
            });
        }

        $this->artisan('content:import')
            ->expectsOutputToContain('entries.id (integer)')
            ->assertFailed();
    }

    public function test_off_cloud_nothing_changes(): void
    {
        config(['core-web.cloud.detected' => false, 'session.driver' => 'file']);

        CloudDefaults::apply($this->app);

        $this->assertSame('file', config('session.driver'));
    }

    public function test_cloud_is_what_laravel_cloud_says(): void
    {
        config(['core-web.cloud.detected' => null]);
        $this->assertFalse(CloudDefaults::onCloud());

        $_SERVER['LARAVEL_CLOUD'] = '1';

        try {
            $this->assertTrue(CloudDefaults::onCloud());
        } finally {
            unset($_SERVER['LARAVEL_CLOUD']);
        }
    }

    public function test_without_the_drivers_config_the_content_drivers_are_left_alone(): void
    {
        config(['statamic.eloquent-driver' => [], 'statamic.users.repository' => 'file']);

        CloudDefaults::apply($this->app);

        $this->assertNull(config('statamic.eloquent-driver.entries.driver'));
        $this->assertFalse(CloudDefaults::contentInDatabase());
    }

    public function test_a_driver_registered_first_is_refused(): void
    {
        // Marked loaded rather than registered: a real register leaves Statamic boot callbacks behind for later tests.
        (fn () => $this->loadedProviders[EloquentDriver::class] = true)->call($this->app);

        $this->expectException(LogicException::class);

        CloudDefaults::apply($this->app);
    }

    public function test_deploy_refuses_on_cloud_with_content_on_the_disk(): void
    {
        config(['statamic.eloquent-driver.entries.driver' => 'file']);

        $this->artisan('core-web:deploy')->assertFailed();
    }

    public function test_deploy_runs_the_steps_and_the_sites_own(): void
    {
        config(['core-web.cloud.detected' => false, 'core-web.cloud.deploy' => ['env']]);

        Artisan::call('core-web:deploy');
        $output = Artisan::output();

        foreach (['migrate', 'statamic:stache:refresh', 'statamic:static:clear', 'statamic:search:update', 'env'] as $step) {
            $this->assertStringContainsString($step, $output);
        }

        $this->assertStringNotContainsString('core:warm', $output, 'only with the snapshots feature');
    }

    public function test_import_refuses_while_entries_read_content_files(): void
    {
        config(['statamic.eloquent-driver.entries.driver' => 'file']);

        $this->artisan('content:import')->assertFailed();
    }

    public function test_import_takes_users_files_only_into_an_empty_eloquent_users_table(): void
    {
        $directory = sys_get_temp_dir().'/core-web-users-'.uniqid();
        mkdir($directory);
        config(['statamic.stache.stores.users.directory' => $directory, 'statamic.users.repository' => 'eloquent']);
        Schema::create('users', function (Blueprint $blueprint): void {
            $blueprint->uuid('id');
            $blueprint->string('email');
        });
        $command = app(ImportContentCommand::class);

        try {
            $this->assertSame('', $command->usersSkipReason(), 'a site with no users/ files has nothing to import');

            file_put_contents($directory.'/editor@example.com.yaml', "name: Editor\n");
            $this->assertNull($command->usersSkipReason());

            Schema::getConnection()->table('users')->insert(['id' => 'a', 'email' => 'sso@example.com']);
            $this->assertStringContainsString('already holds accounts', $command->usersSkipReason());

            Schema::getConnection()->table('users')->delete();
            config(['core-web.features.cms_sign_in' => true]);
            $this->assertStringContainsString('Core sign-in is on', $command->usersSkipReason());

            config(['statamic.users.repository' => 'file']);
            $this->assertSame('', $command->usersSkipReason(), 'file users stay files');
        } finally {
            array_map('unlink', glob($directory.'/*'));
            rmdir($directory);
        }
    }

    public function test_the_first_boot_commands_of_a_feature_that_is_off_succeed_and_say_so(): void
    {
        $this->artisan('content:check')->expectsOutputToContain('checks feature is off')->assertSuccessful();
        $this->artisan('media:sync push --force --no-interaction')->expectsOutputToContain('media feature is off')->assertSuccessful();
        $this->artisan('media:check')->expectsOutputToContain('media feature is off')->assertSuccessful();
    }

    public function test_entry_order_refuses_an_unknown_collection(): void
    {
        $this->artisan('core-web:entry-order', ['collection' => 'nope'])->assertFailed();
    }

    public function test_an_import_step_runs_its_command_without_queueing_or_indexing(): void
    {
        config(['queue.default' => 'database', 'statamic.search.indexes' => ['default' => ['driver' => 'local', 'searchables' => 'all']]]);
        $seen = [];
        Artisan::command('core-web-test:probe', function () use (&$seen) {
            $seen = [config('queue.default'), config('statamic.eloquent-driver.collections.update_entry_order_connection'), config('statamic.search.indexes')];
        });

        $this->artisan('core-web:import-step', ['line' => 'core-web-test:probe'])->assertSuccessful();
        $this->assertSame(['sync', 'sync', []], $seen);

        config(['statamic.search.indexes' => ['default' => ['driver' => 'local']]]);
        $this->artisan('core-web:import-step', ['line' => 'core-web-test:probe', '--with-search' => true])->assertSuccessful();
        $this->assertSame(['default' => ['driver' => 'local']], $seen[2]);
    }

    public function test_an_import_step_passes_on_the_exit_code(): void
    {
        Artisan::command('core-web-test:fail', fn () => 3);

        $this->artisan('core-web:import-step', ['line' => 'core-web-test:fail'])->assertExitCode(3);
    }

    public function test_every_import_step_goes_through_import_step(): void
    {
        $process = ImportContentCommand::stepProcess(['statamic:eloquent:import-entries']);

        $this->assertSame(['core-web:import-step', 'statamic:eloquent:import-entries --no-interaction', '--no-interaction'], array_slice($process, 2));
        $this->assertContains('--with-search', ImportContentCommand::stepProcess(['statamic:search:update', '--all'], search: true));
    }

    public function test_only_indexes_that_outlive_the_container_are_rebuilt_after_an_import(): void
    {
        config(['statamic.search.indexes' => [
            'default' => ['driver' => 'local'],
            'products' => ['driver' => 'algolia'],
            'off' => ['driver' => 'null'],
            'implicit' => [],
        ]]);

        $this->assertSame(['products'], ImportContentCommand::lastingSearchIndexes());
    }
}
