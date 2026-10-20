<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Tests\TestCase;
use InvalidArgumentException;
use ReflectionMethod;

/** A site's `cms_sign_in` block names only what it changes; the package fills in the rest. */
class CmsSignInConfigTest extends TestCase
{
    protected array $features = ['cms_sign_in'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.driver', 'statamic');
        // Replaces the package's whole block, as a published config/core-web.php does.
        $app['config']->set('core-web.cms_sign_in', [
            'roles' => ['cms_editor' => 'editor'],
            'revoke' => ['middleware' => []],
            'core' => ['sign_in' => '/cms/sign-in'],
        ]);
    }

    public function test_a_partial_block_keeps_the_package_defaults(): void
    {
        $this->assertSame(['cms_editor' => 'editor'], config('core-web.cms_sign_in.roles'));
        $this->assertSame('production', config('core-web.cms_sign_in.site'));
        $this->assertFalse(config('core-web.cms_sign_in.local_password_sign_in'));
        $this->assertSame('admin-sso', config('core-web.cms_sign_in.path'));
        $this->assertSame('45 3 * * *', config('core-web.cms_sign_in.reconcile_schedule'));
    }

    public function test_a_partial_nested_block_keeps_its_other_keys(): void
    {
        $this->assertSame([], config('core-web.cms_sign_in.revoke.middleware'));
        $this->assertSame('api/core/cms-users/revoke', config('core-web.cms_sign_in.revoke.path'));
        $this->assertSame('core-web.cms-users.revoke', config('core-web.cms_sign_in.revoke.name'));

        $this->assertSame('/cms/sign-in', config('core-web.cms_sign_in.core.sign_in'));
        $this->assertSame('/api/app/cms-sso/client/verify', config('core-web.cms_sign_in.core.verify'));
        $this->assertSame('/api/app/cms-users/client/reconcile', config('core-web.cms_sign_in.core.reconcile'));
    }

    public function test_the_routes_command_and_schedule_follow_the_defaults(): void
    {
        $this->assertSame('admin-sso', app(Router::class)->getRoutes()->getByName('core-web.cms-sign-in')?->uri());
        $this->assertSame('api/core/cms-users/revoke', app(Router::class)->getRoutes()->getByName('core-web.cms-users.revoke')?->uri());
        $this->assertArrayHasKey('core:reconcile-cms-users', app(Kernel::class)->all());

        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event) => str_contains((string) $event->command, 'core:reconcile-cms-users'));
        $this->assertCount(1, $events);
        $this->assertSame('45 3 * * *', $events->first()->expression);
    }

    public function test_the_hand_off_route_is_always_in_the_web_group(): void
    {
        config(['core-web.cms_sign_in.middleware' => ['throttle:core-web.cms-sign-in']]);
        app(Router::class)->setRoutes(new RouteCollection);
        require __DIR__.'/../../routes/cms-sign-in.php';
        app(Router::class)->getRoutes()->refreshNameLookups();

        $this->assertSame(['web', 'throttle:core-web.cms-sign-in'], app(Router::class)->getRoutes()->getByName('core-web.cms-sign-in')?->middleware());
    }

    public function test_a_cp_provider_on_another_driver_fails_the_boot(): void
    {
        config(['auth.providers.users.driver' => 'eloquent']);

        $this->expectException(InvalidArgumentException::class);

        (new ReflectionMethod(CoreWebServiceProvider::class, 'mergeSsoUserProvider'))->invoke(app()->getProvider(CoreWebServiceProvider::class));
    }

    public function test_the_login_redirect_takes_the_site_s_sign_in_path(): void
    {
        config(['core-web.client.base_url' => 'https://core.test']);

        $this->get('/cp/auth/login')->assertRedirect('https://core.test/cms/sign-in?site=production&next=%2Fcp');
    }
}
