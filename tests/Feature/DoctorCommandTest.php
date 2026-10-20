<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Inly\CoreWeb\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    protected array $features = ['snapshots', 'cms_sign_in'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.driver', 'statamic');
        $app['config']->set('core-web.client.base_url', 'https://core.test');
        $app['config']->set('core-web.client.client_id', 'client');
        $app['config']->set('core-web.client.client_secret', 'secret');
        $app['config']->set('core-web.ping.secret', '');
        $app['config']->set('queue.default', 'redis');
    }

    public function test_a_configured_site_passes(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('ok   Core client: https://core.test')
            ->expectsOutputToContain('ok   Queue: redis')
            ->expectsOutputToContain('ok   Schedule: core:warm')
            ->expectsOutputToContain('ok   Schedule: core:reconcile-cms-users')
            ->assertSuccessful();
    }

    public function test_a_missing_client_fails(): void
    {
        $this->app['env'] = 'production';
        config(['core-web.client.client_secret' => '']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Core client: CORE_CLIENT_SECRET not set, which snapshots, cms_sign_in need')
            ->assertFailed();
    }

    public function test_a_local_machine_may_lean_on_the_remote_core(): void
    {
        $this->app['env'] = 'local';
        config(['core-web.client.base_url' => '', 'core-web.remote.base_url' => 'https://core.example', 'core-web.remote.client_id' => 'remote', 'core-web.remote.client_secret' => 'x']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('ok   Core client: none here, the remote Core (https://core.example) stands in')
            ->assertSuccessful();
    }

    public function test_a_sync_queue_fails_on_a_server(): void
    {
        $this->app['env'] = 'production';
        config(['queue.default' => 'sync']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Queue: QUEUE_CONNECTION is sync')
            ->assertFailed();
    }

    public function test_a_sync_queue_is_a_warning_on_staging(): void
    {
        $this->app['env'] = 'staging';
        config(['queue.default' => 'sync']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('warn Queue: QUEUE_CONNECTION is sync')
            ->assertSuccessful();
    }

    public function test_the_shared_ping_secret_is_a_warning(): void
    {
        $this->app['env'] = 'production';
        config(['core-web.ping.secret' => 'shared']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('warn Pings: WEBSITE_SYNC_SECRET is set')
            ->assertSuccessful();
    }

    public function test_committed_cms_accounts_fail(): void
    {
        $this->app['env'] = 'production';
        config([
            'statamic.git.enabled' => true,
            'statamic.git.paths' => [base_path('content'), base_path('users')],
            'statamic.stache.stores.users.directory' => base_path('users'),
        ]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail CMS accounts: statamic.git.paths commits the users folder')
            ->assertFailed();
    }

    public function test_it_is_registered_once_a_feature_is_on(): void
    {
        $this->assertArrayHasKey('core-web:doctor', app(Kernel::class)->all());
    }
}
