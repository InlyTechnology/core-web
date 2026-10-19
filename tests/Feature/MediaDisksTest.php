<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Media\ProductionFileLocked;
use Inly\CoreWeb\Tests\TestCase;

class MediaDisksTest extends TestCase
{
    protected array $features = ['media'];

    private function define(string $environment, array $config = []): array
    {
        config(['filesystems.disks' => array_diff_key(config('filesystems.disks'), ['assets' => true])]);
        config(array_merge([
            'core-web.media.bucket.bucket' => 'site',
            'core-web.media.bucket.endpoint' => 'https://ams3.digitaloceanspaces.com',
            'core-web.media.bucket.url' => 'https://cdn.inlycore.com/client/site/',
            'core-web.media.bucket.root' => 'web',
        ], $config));
        $this->app['env'] = $environment;

        MediaDisks::define($this->app);

        return config('filesystems.disks.assets');
    }

    private function withKey(): array
    {
        return ['core-web.media.bucket.key' => 'key', 'core-web.media.bucket.secret' => 'secret'];
    }

    public function test_without_a_key_the_disk_is_the_local_folder_wherever_it_runs(): void
    {
        foreach (['production', 'staging', 'local'] as $environment) {
            $disk = $this->define($environment);

            $this->assertSame('local', $disk['driver'], $environment);
            $this->assertSame(public_path('assets'), $disk['root']);
            $this->assertSame('/assets', $disk['url']);
        }
    }

    public function test_without_content_sync_there_is_no_media_file_route(): void
    {
        $this->assertFalse(Route::has('core-web.media.file'));
    }

    public function test_production_writes_the_bucket(): void
    {
        $disk = $this->define('production', $this->withKey());

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('web/assets', $disk['root']);
        $this->assertSame('https://cdn.inlycore.com/client/site', $disk['url']);
        $this->assertSame('public', $disk['visibility']);
        $this->assertSame(['CacheControl' => 'public, max-age=2592000'], $disk['options']);
        $this->assertFalse($disk['throw']);
        $this->assertSame(3, $disk['http']['connect_timeout']);
    }

    public function test_a_bucket_without_acls_gets_no_visibility(): void
    {
        $disk = $this->define('production', [...$this->withKey(), 'core-web.media.bucket.acl' => false]);

        $this->assertArrayNotHasKey('visibility', $disk);
    }

    public function test_staging_writes_a_layer_in_the_bucket(): void
    {
        $disk = $this->define('staging', $this->withKey());

        $this->assertSame(MediaDisks::LAYERED_DRIVER, $disk['driver']);
        $this->assertSame('web/_staging/assets', $disk['upper']['root']);
        $this->assertSame('web/assets', $disk['lower']['root']);
        $this->assertSame('https://cdn.inlycore.com/client/site', $disk['url'], 'Statamic counts a disk without a url as private');
        $this->assertSame('public', $disk['visibility']);
    }

    public function test_a_laptop_with_a_key_writes_a_local_layer(): void
    {
        $disk = $this->define('local', $this->withKey());

        $this->assertSame(MediaDisks::LAYERED_DRIVER, $disk['driver']);
        $this->assertSame('local', $disk['upper']['driver']);
        $this->assertSame(public_path('media-layer/assets'), $disk['upper']['root']);
        $this->assertSame('/media-layer/assets', $disk['upper']['url']);
        $this->assertSame('s3', $disk['lower']['driver']);
    }

    public function test_a_server_set_up_before_go_live_can_turn_the_layer_off(): void
    {
        $this->assertSame('s3', $this->define('staging', [...$this->withKey(), 'core-web.media.layer.enabled' => 'false'])['driver']);
        $this->assertSame(MediaDisks::LAYERED_DRIVER, $this->define('staging', [...$this->withKey(), 'core-web.media.layer.enabled' => true])['driver']);
    }

    public function test_a_disk_the_app_defines_is_left_alone(): void
    {
        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => '/elsewhere']]);

        MediaDisks::define($this->app);

        $this->assertSame('/elsewhere', config('filesystems.disks.assets.root'));
    }

    public function test_the_layered_driver_builds_a_disk_reading_through_the_layer(): void
    {
        $root = sys_get_temp_dir().'/core-web-layer-'.uniqid();
        mkdir($root.'/lower', 0777, true);
        file_put_contents($root.'/lower/production.jpg', 'prod');

        config(['filesystems.disks.layered' => [
            'driver' => MediaDisks::LAYERED_DRIVER,
            'name' => 'layered',
            'upper' => ['driver' => 'local', 'root' => $root.'/upper', 'url' => '/layer'],
            'lower' => ['driver' => 'local', 'root' => $root.'/lower', 'url' => 'https://cdn.inlycore.com/c/s'],
            'throw' => false,
        ]]);

        $disk = Storage::disk('layered');

        $this->assertSame('prod', $disk->get('production.jpg'));
        $this->assertTrue($disk->put('new.jpg', 'new'));
        try {
            $disk->delete('production.jpg');
            $this->fail('Deleting production\'s file must throw, whatever the disk\'s `throw`.');
        } catch (ProductionFileLocked) {
            $this->assertFileExists($root.'/lower/production.jpg');
        }

        $this->assertFileExists($root.'/upper/new.jpg');
        $this->assertSame('https://cdn.inlycore.com/c/s/production.jpg', $disk->url('production.jpg'));
        $this->assertSame('/layer/new.jpg', $disk->url('new.jpg'));

        exec('rm -rf '.escapeshellarg($root));
    }

    public function test_a_disk_needs_a_folder(): void
    {
        $this->expectExceptionMessage('[assets] needs a folder');

        $this->define('staging', ['core-web.media.disks' => ['assets' => '/']]);
    }

    public function test_media_check_says_when_the_app_defines_the_disk_itself(): void
    {
        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => '/elsewhere']]);
        MediaDisks::define($this->app);

        $this->artisan('media:check')
            ->expectsOutputToContain('config/filesystems.php defines it, so core-web does not')
            ->assertSuccessful();
    }

    public function test_the_media_commands_are_registered(): void
    {
        $this->define('local');

        $this->artisan('media:sync', ['direction' => 'push'])
            ->expectsOutputToContain('No bucket key here')
            ->assertFailed();

        $this->artisan('media:check')
            ->expectsOutputToContain('assets: the local folder public/assets')
            ->assertSuccessful();
    }
}
