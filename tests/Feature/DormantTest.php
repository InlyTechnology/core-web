<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Router;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Snapshots\UncacheableWhileDegraded;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Tags\Loader as TagLoader;
use Throwable;

class DormantTest extends TestCase
{
    protected array $features = [];

    public function test_every_feature_is_off_by_default(): void
    {
        $this->assertSame(['snapshots' => false], config('core-web.features'));
    }

    public function test_no_route_is_registered(): void
    {
        $routes = collect(app(Router::class)->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->getActionName(), 'Inly\\CoreWeb\\'));

        $this->assertCount(0, $routes);
    }

    public function test_nothing_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event) => str_contains((string) $event->command, 'core:warm'));

        $this->assertCount(0, $events);
    }

    public function test_no_middleware_joins_statamic_s_group(): void
    {
        $this->assertNotContains(UncacheableWhileDegraded::class, app(Router::class)->getMiddlewareGroups()['statamic.web'] ?? []);
    }

    public function test_no_command_is_registered(): void
    {
        $this->assertArrayNotHasKey('core:warm', app(Kernel::class)->all());
    }

    public function test_no_tag_is_registered(): void
    {
        $registered = true;

        try {
            app(TagLoader::class)->load('core_sitemaps', ['parser' => null, 'params' => [], 'content' => '', 'context' => [], 'tag' => 'core_sitemaps', 'tag_method' => 'index']);
        } catch (Throwable) {
            $registered = false;
        }

        $this->assertFalse($registered);
    }

    public function test_no_cache_store_is_added(): void
    {
        $this->assertFalse(config()->has('cache.stores.snapshots'));
    }

    public function test_the_core_client_is_still_configured(): void
    {
        config(['core-web.client.base_url' => 'https://core.test']);

        $this->assertInstanceOf(CoreClient::class, app(CoreClient::class));
        $this->assertSame('https://core.test', config('core-web.client.base_url'));
    }
}
