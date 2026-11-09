<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\StaticCaching\DefaultStrategy;
use Inly\CoreWeb\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Statamic\Events\EntrySaved;
use Statamic\StaticCaching\Invalidate;

class DefaultStrategyTest extends TestCase
{
    private const ENV = 'STATAMIC_STATIC_CACHING_STRATEGY';

    protected array $features = [];

    protected function tearDown(): void
    {
        $this->setEnv(null);

        parent::tearDown();
    }

    private function setEnv(?string $value): void
    {
        if ($value === null) {
            putenv(self::ENV);
            unset($_ENV[self::ENV], $_SERVER[self::ENV]);

            return;
        }

        putenv(self::ENV."={$value}");
        $_ENV[self::ENV] = $_SERVER[self::ENV] = $value;
    }

    private function applyIn(string $environment, mixed $strategy = null): mixed
    {
        $this->app['env'] = $environment;
        config(['statamic.static_caching.strategy' => $strategy]);

        DefaultStrategy::apply($this->app);

        return config('statamic.static_caching.strategy');
    }

    public function test_a_server_caches_pages_without_an_env_line(): void
    {
        $this->assertSame('half', $this->applyIn('production'));
        $this->assertSame('half', $this->applyIn('staging'));
    }

    public function test_local_machines_and_tests_keep_the_site_strategy(): void
    {
        $this->assertNull($this->applyIn('local'));
        $this->assertNull($this->applyIn('testing'));
        $this->assertSame('half', $this->applyIn('local', 'half'));
    }

    public function test_the_strategy_the_site_names_stands(): void
    {
        $this->assertSame('full', $this->applyIn('production', 'full'), 'set in the site config');

        $this->setEnv('null');
        $this->assertNull($this->applyIn('production'), 'turned off in the env');
    }

    public function test_a_cached_config_keeps_what_it_resolved(): void
    {
        $this->app->instance('config_loaded_from_cache', true);

        $this->assertNull($this->applyIn('production'));
    }

    protected function onAServer($app): void
    {
        $app['env'] = 'production';
    }

    #[DefineEnvironment('onAServer')]
    public function test_a_site_on_a_server_boots_with_pages_cached(): void
    {
        $this->assertSame('half', config('statamic.static_caching.strategy'));
        $this->assertContains(Invalidate::class.'@refreshEntry', $this->app['events']->getRawListeners()[EntrySaved::class] ?? [], 'a save flushes the pages');
    }

    public function test_the_suite_boots_without_a_static_cache(): void
    {
        $this->assertNull(config('statamic.static_caching.strategy'));
    }
}
