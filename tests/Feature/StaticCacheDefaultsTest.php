<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\StaticCaching\SiteScopedInvalidator;
use Inly\CoreWeb\StaticCaching\StaticCacheDefaults;
use Inly\CoreWeb\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Statamic\Facades\Site;
use Statamic\StaticCaching\DefaultInvalidator;
use Statamic\StaticCaching\Invalidator;

class StaticCacheDefaultsTest extends TestCase
{
    private const ENV = 'STATAMIC_BACKGROUND_RECACHE';

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

    /** @param  array<string, mixed>  $config */
    private function recacheIn(string $environment, string $queue, array $config = []): mixed
    {
        $this->app['env'] = $environment;
        config([
            'queue.default' => $queue,
            'queue.connections.deferred' => ['driver' => 'deferred'],
            'queue.connections.background' => ['driver' => 'background'],
            'queue.connections.null' => ['driver' => 'null'],
            'statamic.static_caching.background_recache' => false,
            'statamic.static_caching.warm_queue_connection' => null,
            ...$config,
        ]);

        StaticCacheDefaults::applyRecache($this->app);

        return config('statamic.static_caching.background_recache');
    }

    public function test_production_renders_pages_again_where_a_worker_takes_them(): void
    {
        $this->assertTrue($this->recacheIn('production', 'redis'));
        $this->assertTrue($this->recacheIn('production', 'database'));
    }

    public function test_a_connection_without_a_worker_forgets_pages_instead(): void
    {
        foreach (['sync', 'null', 'deferred', 'background', 'undefined'] as $queue) {
            $this->assertFalse($this->recacheIn('production', $queue), $queue);
        }
    }

    public function test_other_environments_forget_pages_whatever_the_queue(): void
    {
        $this->assertFalse($this->recacheIn('staging', 'redis'));
        $this->assertFalse($this->recacheIn('local', 'redis'));
    }

    public function test_the_warm_connection_decides_over_the_default_one(): void
    {
        $this->assertTrue($this->recacheIn('production', 'sync', ['statamic.static_caching.warm_queue_connection' => 'redis']));
        $this->assertFalse($this->recacheIn('production', 'redis', ['statamic.static_caching.warm_queue_connection' => 'sync']));
    }

    public function test_the_env_naming_it_stands(): void
    {
        $this->setEnv('false');
        $this->assertFalse($this->recacheIn('production', 'redis', ['core-web.static_cache.background_recache' => true]));

        $this->setEnv('true');
        $this->assertTrue($this->recacheIn('staging', 'sync', ['statamic.static_caching.background_recache' => true]));
    }

    public function test_the_core_web_setting_applies_in_every_environment(): void
    {
        $this->assertFalse($this->recacheIn('production', 'redis', ['core-web.static_cache.background_recache' => false]));
        $this->assertTrue($this->recacheIn('staging', 'sync', ['core-web.static_cache.background_recache' => true]));
    }

    public function test_the_site_config_turning_it_on_stands(): void
    {
        $this->assertTrue($this->recacheIn('staging', 'sync', ['statamic.static_caching.background_recache' => true]));
    }

    public function test_a_cached_config_keeps_what_it_resolved(): void
    {
        $this->app->instance('config_loaded_from_cache', true);

        $this->assertFalse($this->recacheIn('production', 'redis'));

        config(['statamic.static_caching.invalidation.rules' => 'all']);
        StaticCacheDefaults::scopeInvalidation($this->app);
        $this->assertNull(config('statamic.static_caching.invalidation.class'));
    }

    protected function inProductionWithAWorker($app): void
    {
        $app['env'] = 'production';
        // TestCase sets the default connection to sync after this runs.
        $app['config']->set('statamic.static_caching.warm_queue_connection', 'redis');
    }

    #[DefineEnvironment('inProductionWithAWorker')]
    public function test_a_site_boots_with_pages_rendered_again_in_production(): void
    {
        $this->assertTrue(config('statamic.static_caching.background_recache'));
    }

    public function test_the_suite_boots_forgetting_pages(): void
    {
        $this->assertFalse(config('statamic.static_caching.background_recache'));
    }

    /** @param  array<string, mixed>  $config */
    private function invalidatorClass(array $config = []): mixed
    {
        config(['statamic.static_caching.invalidation' => ['class' => null, 'rules' => 'all'], ...$config]);

        StaticCacheDefaults::scopeInvalidation($this->app);

        return config('statamic.static_caching.invalidation.class');
    }

    public function test_a_multisite_install_on_the_all_rule_keeps_a_save_to_its_site(): void
    {
        $this->assertSame(SiteScopedInvalidator::class, $this->invalidatorClass());
    }

    public function test_the_class_and_rules_a_site_names_stand(): void
    {
        $this->assertSame(DefaultInvalidator::class, $this->invalidatorClass(['statamic.static_caching.invalidation.class' => DefaultInvalidator::class]));
        $this->assertNull($this->invalidatorClass(['statamic.static_caching.invalidation.rules' => []]));
    }

    public function test_a_single_site_keeps_statamic_s_invalidator(): void
    {
        Site::setSites(['default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE']]);

        $this->assertNull($this->invalidatorClass());
    }

    protected function onTwoSitesWithTheAllRule($app): void
    {
        $app['config']->set('statamic.static_caching.invalidation.rules', 'all');
        $app->booting(fn () => Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://other.test/', 'locale' => 'sv_SE'],
        ]));
    }

    #[DefineEnvironment('onTwoSitesWithTheAllRule')]
    public function test_a_multisite_install_boots_with_the_site_scoped_invalidator(): void
    {
        $this->assertSame(SiteScopedInvalidator::class, config('statamic.static_caching.invalidation.class'));
        $this->assertInstanceOf(SiteScopedInvalidator::class, app(Invalidator::class));
    }
}
