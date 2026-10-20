<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\View;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\CmsSignIn\RedirectCpLoginToCore;
use Inly\CoreWeb\Content\VerifyCoreEnvelope;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Snapshots\UncacheableWhileDegraded;
use Inly\CoreWeb\Tests\TestCase;
use LogicException;
use Statamic\Facades\CP\Nav as CpNav;
use Statamic\Facades\User;
use Statamic\Tags\Loader as TagLoader;
use Statamic\Tags\TagNotFoundException;

class DormantTest extends TestCase
{
    protected array $features = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // As a site configures Statamic's users, so the CMS sign-in switch has a driver it could take over.
        $app['config']->set('auth.providers.users.driver', 'statamic');
    }

    public function test_every_feature_is_off_by_default(): void
    {
        $this->assertSame(['snapshots' => false, 'chat' => false, 'content' => false, 'live' => false, 'drafts' => false, 'checks' => false, 'forms' => false, 'cp_link' => false, 'cms_sign_in' => false], config('core-web.features'));
    }

    public function test_no_route_is_registered(): void
    {
        $routes = collect(app(Router::class)->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->getActionName(), 'Inly\\CoreWeb\\'));

        $this->assertCount(0, $routes);
    }

    public function test_no_chat_route_or_component_is_registered(): void
    {
        $this->assertFalse(app(Router::class)->has('core-web.chat.show'));
        $this->post('/!/core-web/chat/assistant/messages', ['message' => 'Hej'])->assertNotFound();
        $this->assertFalse(View::exists('core-web::components.chat'));
    }

    public function test_no_live_route_is_registered(): void
    {
        $this->assertFalse(app(Router::class)->has('core-web.live'));
        $this->get('/!/core-web/live/quote')->assertNotFound();
    }

    public function test_no_cms_sign_in_is_wired(): void
    {
        $this->assertFalse(app(Router::class)->has('core-web.cms-sign-in'));
        $this->assertFalse(app(Router::class)->has('core-web.cms-users.revoke'));
        $this->get('/admin-sso?payload=x&signature=y&key_id=z')->assertNotFound();
        $this->postJson('/api/core/cms-users/revoke', [])->assertNotFound();

        $this->assertArrayNotHasKey('core:reconcile-cms-users', app(Kernel::class)->all());
        $this->assertSame('statamic', config('auth.providers.users.driver'));
        $this->assertFalse(app(HttpKernel::class)->hasMiddleware(RedirectCpLoginToCore::class));
        $this->assertCount(0, collect(app(Schedule::class)->events())->filter(fn (Event $event) => str_contains((string) $event->command, 'core:reconcile-cms-users')));

        config(['core-web.client.base_url' => 'https://core.test']);
        $this->assertStringEndsWith('/cp/auth/login', (string) $this->get('/cp')->headers->get('Location'));
    }

    public function test_no_draft_preview_route_is_registered(): void
    {
        $this->assertFalse(app(Router::class)->has('core.preview'));
        $this->get('/core-preview/abc')->assertNotFound();
    }

    public function test_the_envelope_alias_is_registered_but_joins_no_group(): void
    {
        $this->assertSame(VerifyCoreEnvelope::class, app(Router::class)->getMiddleware()[VerifyCoreEnvelope::ALIAS] ?? null);

        foreach (app(Router::class)->getMiddlewareGroups() as $group) {
            $this->assertNotContains(VerifyCoreEnvelope::class, $group);
        }
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
        $this->assertArrayNotHasKey('core:pull', app(Kernel::class)->all());
        $this->assertArrayNotHasKey('content:check', app(Kernel::class)->all());
    }

    public function test_no_tag_is_registered(): void
    {
        $registered = true;

        try {
            app(TagLoader::class)->load('core_sitemaps', ['parser' => null, 'params' => [], 'content' => '', 'context' => [], 'tag' => 'core_sitemaps', 'tag_method' => 'index']);
        } catch (TagNotFoundException) {
            $registered = false;
        }

        $this->assertFalse($registered);
    }

    public function test_no_control_panel_link_is_added(): void
    {
        $this->withRealNav();
        config(['core-web.client.base_url' => 'https://core.test']);
        $this->actingAs(User::make()->id('dormant-super')->email('dormant-super@example.com')->makeSuper());

        $items = CpNav::build()->flatMap(fn (array $section) => $section['items'])->map->display()->all();

        $this->assertContains('Dashboard', $items);
        $this->assertNotContains('Core', $items);
    }

    public function test_no_widget_is_registered(): void
    {
        $this->assertFalse(app('statamic.widgets')->has('core_snapshot'));
    }

    public function test_no_cache_store_is_added(): void
    {
        $this->assertFalse(config()->has('cache.stores.snapshots'));
    }

    public function test_the_core_client_needs_no_switch(): void
    {
        config(['core-web.client.base_url' => 'https://core.test', 'core-web.client.client_id' => 'web', 'core-web.client.client_secret' => 'secret']);

        $this->assertTrue(app(CoreClient::class)->configured());
    }

    public function test_reading_snapshots_while_switched_off_names_the_switch(): void
    {
        config(['core-web.snapshots.store' => 'snapshots']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('core-web.features.snapshots');

        app(SnapshotStore::class);
    }

    public function test_a_switch_set_from_env_counts(): void
    {
        $this->assertFalse(CoreWebServiceProvider::enabled('chat'));

        foreach (['1', 'true', 'on', true] as $on) {
            config(['core-web.features.snapshots' => $on]);
            $this->assertTrue(CoreWebServiceProvider::enabled('snapshots'), var_export($on, true));
        }

        foreach (['0', 'false', '', null, false] as $off) {
            config(['core-web.features.snapshots' => $off]);
            $this->assertFalse(CoreWebServiceProvider::enabled('snapshots'), var_export($off, true));
        }
    }
}
