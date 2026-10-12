<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Snapshots\UncacheableWhileDegraded;
use Inly\CoreWeb\StaticCaching\SiteScopedInvalidator;
use Inly\CoreWeb\Tests\TestCase;
use Orchestra\Testbench\Attributes\WithEnv;
use RuntimeException;
use Statamic\StaticCaching\Middleware\Cache as StaticCache;

class ServiceProviderTest extends TestCase
{
    public function test_core_warm_is_scheduled_nightly(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event) => str_contains($event->command, 'core:warm'));

        $this->assertCount(1, $events);
        $this->assertSame('15 3 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertTrue($events->first()->runInBackground);
    }

    public function test_the_degraded_guard_runs_inside_statamic_s_static_cache(): void
    {
        $group = app(Router::class)->getMiddlewareGroups()['statamic.web'] ?? [];

        $this->assertContains(UncacheableWhileDegraded::class, $group);
        $this->assertGreaterThan(array_search(StaticCache::class, $group, true), array_search(UncacheableWhileDegraded::class, $group, true));
    }

    public function test_a_page_read_from_a_degraded_snapshot_is_uncacheable(): void
    {
        $next = fn () => response('page');
        $guard = new UncacheableWhileDegraded;

        $this->assertFalse($guard->handle(Request::create('/'), $next)->headers->has('X-Statamic-Uncacheable'));

        app(SnapshotStore::class)->read(Snapshot::make('down')->pull(fn () => throw new RuntimeException('down')));

        $this->assertSame('true', $guard->handle(Request::create('/'), $next)->headers->get('X-Statamic-Uncacheable'));
    }

    public function test_the_site_scoped_invalidator_is_bound_with_the_configured_rules(): void
    {
        config(['statamic.static_caching.invalidation.rules' => 'all']);

        $this->assertInstanceOf(SiteScopedInvalidator::class, app(SiteScopedInvalidator::class));
    }

    #[WithEnv('CORE_URL', 'https://core.test')]
    #[WithEnv('CORE_CLIENT_ID', 'web')]
    #[WithEnv('CORE_CLIENT_SECRET', 'client-secret')]
    #[WithEnv('CORE_CA_BUNDLE', '/tmp/ca.pem')]
    public function test_the_defaults_read_the_core_env_family(): void
    {
        $this->assertSame('https://core.test', config('core-web.client.base_url'));
        $this->assertSame('web', config('core-web.client.client_id'));
        $this->assertSame('client-secret', config('core-web.client.client_secret'));
        $this->assertSame('/tmp/ca.pem', config('core-web.client.ca_bundle'));
        $this->assertSame('file', config('core-web.snapshots.store_driver'));
    }

    public function test_the_config_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'core-web-config', '--force' => true])->assertSuccessful();

        $this->assertFileExists(config_path('core-web.php'));
        unlink(config_path('core-web.php'));
    }
}
