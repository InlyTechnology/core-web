<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Inly\CoreWeb\Cloud\CloudDefaults;
use Inly\CoreWeb\Tests\TestCase;
use LogicException;
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
        $this->assertSame('half', config('statamic.static_caching.strategy'));
        $this->assertFalse(config('statamic.git.enabled'));
        $this->assertFalse(config('statamic.stache.watcher'));
        $this->assertSame('file', config('cache.stores.outpost.driver'), 'the build runs artisan before the cache table exists');
        $this->assertTrue(CloudDefaults::contentInDatabase());
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
}
