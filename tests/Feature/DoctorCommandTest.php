<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Inly\CoreWeb\StaticCaching\SiteScopedInvalidator;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\StaticCaching\DefaultInvalidator;

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

    public function test_a_local_machine_may_run_without_a_core(): void
    {
        $this->app['env'] = 'local';
        config(['core-web.client.base_url' => '']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('warn Core client: no CORE_URL here')
            ->assertSuccessful();
    }

    public function test_a_local_machine_pointed_at_a_remote_core_fails(): void
    {
        $this->app['env'] = 'local';
        config(['core-web.client.base_url' => 'https://core.example']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Core client: CORE_URL points this local checkout at https://core.example. Remove it')
            ->assertFailed();
    }

    public function test_a_local_machine_may_name_a_local_backoffice(): void
    {
        $this->app['env'] = 'local';
        config(['core-web.client.base_url' => 'https://backoffice.test']);

        $this->artisan('core-web:doctor')->expectsOutputToContain('ok   Core client: https://backoffice.test');
    }

    public function test_a_local_machine_may_name_a_core_on_its_network(): void
    {
        $this->app['env'] = 'local';

        foreach (['http://host.docker.internal:8000', 'https://192.168.1.20', 'https://backoffice.ddev.site', 'http://127.0.0.2', 'https://backoffice.test.'] as $url) {
            config(['core-web.client.base_url' => $url]);
            $this->artisan('core-web:doctor')->expectsOutputToContain("ok   Core client: {$url}");
        }
    }

    public function test_a_half_override_fails(): void
    {
        $this->app['env'] = 'staging';
        config(['core-web.client.base_url' => 'https://staging-core.example', 'core-web.client.client_id' => '']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Core client: core.client_id (or CORE_CLIENT_ID) not set')
            ->assertFailed();
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

    public function test_proposals_on_the_default_disk_on_cloud_fail(): void
    {
        $this->app['env'] = 'production';
        $this->onCloudWithDrafts(['core-web.drafts.proposals.disk' => null]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Proposals: kept on the default disk')
            ->assertFailed();
    }

    public function test_proposals_on_a_local_disk_on_cloud_fail(): void
    {
        $this->app['env'] = 'production';
        $this->onCloudWithDrafts(['core-web.drafts.proposals.disk' => 'local']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Proposals: the local disk is local')
            ->assertFailed();
    }

    public function test_proposals_in_the_private_bucket_on_cloud_pass(): void
    {
        $this->app['env'] = 'production';
        $this->onCloudWithDrafts(['filesystems.disks.private' => ['driver' => 's3', 'bucket' => 'fls-private'], 'core-web.drafts.proposals.disk' => 'private']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('ok   Proposals: kept on the private disk, in the private bucket')
            ->assertSuccessful();
    }

    public function test_proposals_on_the_public_bucket_on_cloud_fail(): void
    {
        $this->app['env'] = 'production';
        $this->onCloudWithDrafts([
            'filesystems.disks.media' => ['driver' => 's3', 'bucket' => 'fls-public'],
            'filesystems.disks.drafts' => ['driver' => 's3', 'bucket' => 'fls-public', 'root' => 'drafts'],
            'core-web.drafts.proposals.disk' => 'media',
        ]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Proposals: the media disk is on the public bucket')
            ->assertFailed();

        config(['core-web.drafts.proposals.disk' => 'drafts']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Proposals: the drafts disk is on the public bucket')
            ->assertFailed();

        config(['filesystems.disks.staged' => ['driver' => 'scoped', 'disk' => 'media', 'prefix' => 'staged'], 'core-web.drafts.proposals.disk' => 'staged']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Proposals: the staged disk is on the public bucket')
            ->assertFailed();
    }

    public function test_proposals_on_a_scoped_disk_are_judged_by_the_disk_it_is_scoped_to(): void
    {
        $this->app['env'] = 'production';
        $this->onCloudWithDrafts([
            'filesystems.disks.private' => ['driver' => 's3', 'bucket' => 'fls-private'],
            'filesystems.disks.staged' => ['driver' => 'scoped', 'disk' => 'private', 'prefix' => 'staged'],
            'core-web.drafts.proposals.disk' => 'staged',
        ]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('ok   Proposals: kept on the staged disk, in the private bucket')
            ->assertSuccessful();

        config(['filesystems.disks.staged.disk' => 'local']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Proposals: the staged disk is local')
            ->assertFailed();

        config(['filesystems.disks.staged.disk' => ['driver' => 's3', 'bucket' => 'fls-public'], 'filesystems.disks.media' => ['driver' => 's3', 'bucket' => 'fls-public']]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Proposals: the staged disk is on the public bucket')
            ->assertFailed();
    }

    public function test_proposals_on_another_bucket_on_cloud_are_a_warning(): void
    {
        $this->app['env'] = 'production';
        $this->onCloudWithDrafts(['filesystems.disks.own' => ['driver' => 's3', 'bucket' => 'own-bucket'], 'core-web.drafts.proposals.disk' => 'own']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('warn Proposals: kept on the own disk, which is not the private bucket Cloud attaches')
            ->assertSuccessful();
    }

    public function test_a_proposals_disk_that_is_not_defined_fails_on_any_host(): void
    {
        $this->app['env'] = 'production';
        config(['core-web.features.drafts' => true, 'core-web.drafts.proposals.disk' => 'missing']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Proposals: core-web.drafts.proposals.disk names missing, which is not a defined disk')
            ->assertFailed();

        config(['filesystems.disks.staged' => ['driver' => 'scoped', 'disk' => 'missing', 'prefix' => 'staged'], 'core-web.drafts.proposals.disk' => 'staged']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('fail Proposals: core-web.drafts.proposals.disk names staged, scoped to missing, which is not a defined disk')
            ->assertFailed();
    }

    /** @param  array<string, mixed>  $config */
    private function onCloudWithDrafts(array $config): void
    {
        config(['core-web.features.drafts' => true, 'core-web.features.cloud' => true, 'core-web.cloud.detected' => true, ...$config]);
    }

    public function test_the_static_cache_off_on_a_server_is_a_warning(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('warn Static cache: off, as STATAMIC_STATIC_CACHING_STRATEGY or the site\'s config chose')
            ->assertSuccessful();
    }

    public function test_a_save_rendering_pages_again_names_the_queue_a_worker_must_take(): void
    {
        $this->onACachedServer(['statamic.static_caching.background_recache' => true]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain("ok   Static cache: a save renders its pages again on the redis connection's default queue (needs a worker on that queue)")
            ->assertSuccessful();

        config(['statamic.static_caching.warm_queue' => 'warm']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain("ok   Static cache: a save renders its pages again on the redis connection's warm queue")
            ->assertSuccessful();
    }

    public function test_a_failover_connection_names_the_connection_its_renders_land_on(): void
    {
        $this->onACachedServer([
            'statamic.static_caching.background_recache' => true,
            'statamic.static_caching.warm_queue_connection' => 'failover',
            'queue.connections.failover' => ['driver' => 'failover', 'connections' => ['database', 'deferred']],
            'queue.connections.database' => ['driver' => 'database', 'queue' => 'renders'],
        ]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain("ok   Static cache: a save renders its pages again on the database connection's renders queue")
            ->assertSuccessful();
    }

    public function test_rendering_pages_again_without_a_worker_is_a_warning(): void
    {
        $this->onACachedServer(['statamic.static_caching.background_recache' => true, 'statamic.static_caching.warm_queue_connection' => 'sync']);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('warn Static cache: background_recache is on, but the sync connection (sync) runs its jobs without a worker')
            ->assertSuccessful();
    }

    public function test_a_save_forgetting_its_pages_passes(): void
    {
        $this->onACachedServer(['statamic.static_caching.background_recache' => false]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain("ok   Static cache: a save forgets its pages, and each one's next visitor renders it")
            ->assertSuccessful();
    }

    public function test_a_multisite_install_on_the_all_rule_names_its_invalidator(): void
    {
        $this->onACachedServer(['statamic.static_caching.invalidation' => ['class' => SiteScopedInvalidator::class, 'rules' => 'all']]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain("ok   Static cache: a save on one site reaches that site's pages")
            ->assertSuccessful();

        config(['statamic.static_caching.invalidation.class' => DefaultInvalidator::class]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain('ok   Static cache: a save is invalidated by '.DefaultInvalidator::class.", the site's own choice")
            ->assertSuccessful();

        config(['statamic.static_caching.invalidation.class' => null]);

        $this->artisan('core-web:doctor')
            ->expectsOutputToContain("warn Static cache: statamic.static_caching.invalidation.class names no class, so a save on one site reaches every site's pages")
            ->assertSuccessful();
    }

    /** @param  array<string, mixed>  $config */
    private function onACachedServer(array $config): void
    {
        $this->app['env'] = 'production';
        config(['statamic.static_caching.strategy' => 'half', ...$config]);
    }

    public function test_it_is_registered_once_a_feature_is_on(): void
    {
        $this->assertArrayHasKey('core-web:doctor', app(Kernel::class)->all());
    }
}
