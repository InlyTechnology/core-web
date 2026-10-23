<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Cloud\CloudDefaults;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Tests\TestCase;

class CloudMediaTest extends TestCase
{
    protected array $features = ['cloud', 'media'];

    /** As a Peak site's config/filesystems.php ships them, loaded before the providers register. */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('core-web.features.cloud', true);
        $app['config']->set('core-web.features.media', true);
        $app['config']->set('core-web.cloud.detected', true);
        $app['config']->set('core-web.media.bucket.cloud', json_encode([[
            'disk' => 'media',
            'access_key_id' => 'cloud-key',
            'access_key_secret' => 'cloud-secret',
            'bucket' => 'fls-site',
            'endpoint' => 'https://abc.r2.cloudflarestorage.com',
            'url' => 'https://fls-site.laravel.cloud',
        ]]));
        $app['config']->set('filesystems.disks.favicons', ['driver' => 'local', 'root' => public_path('favicons')]);
        $app['config']->set('filesystems.disks.social_images', ['driver' => 'local', 'root' => public_path('social_images')]);
        // A site that keeps its Files container local for another host.
        $app['config']->set('core-web.media.disks.files', 'files');
        $app['config']->set('filesystems.disks.files', ['driver' => 'local', 'root' => public_path('files')]);
    }

    public function test_on_cloud_a_media_disk_the_site_keeps_local_is_on_the_bucket(): void
    {
        $this->assertTrue(MediaDisks::defines('files'));
        $this->assertSame(['s3', 'fls-site'], [config('filesystems.disks.files.driver'), config('filesystems.disks.files.bucket')]);
    }

    public function test_on_cloud_peaks_local_disks_are_media_disks_on_the_bucket(): void
    {
        foreach (CloudDefaults::PEAK_DISKS as $disk) {
            $this->assertTrue(MediaDisks::defines($disk));
            $this->assertSame($disk, config("core-web.media.disks.{$disk}"));
            $this->assertSame(['s3', 'fls-site'], [config("filesystems.disks.{$disk}.driver"), config("filesystems.disks.{$disk}.bucket")]);
        }

        $this->assertSame('assets', config('core-web.media.disks.assets'));
    }

    public function test_a_peak_disk_the_site_points_elsewhere_is_left_alone(): void
    {
        config(['filesystems.disks.favicons' => ['driver' => 's3', 'bucket' => 'own'], 'core-web.media.disks' => ['assets' => 'assets']]);

        CloudDefaults::moveLocalDisksToBucket($this->app['config']);

        $this->assertSame('own', config('filesystems.disks.favicons.bucket'));
        $this->assertSame(['assets' => 'assets'], config('core-web.media.disks'));
    }

    public function test_a_folder_the_site_already_names_is_kept(): void
    {
        config(['filesystems.disks.favicons' => ['driver' => 'local', 'root' => '/x'], 'core-web.media.disks' => ['favicons' => 'icons']]);

        CloudDefaults::moveLocalDisksToBucket($this->app['config']);

        $this->assertSame('icons', config('core-web.media.disks.favicons'));
        $this->assertNull(config('filesystems.disks.favicons'));
    }

    public function test_peaks_favicon_links_redirect_to_the_bucket(): void
    {
        // The package has no S3 client installed; a local disk with the bucket's URL stands in for it.
        config(['app.maintenance.driver' => 'file', 'filesystems.disks.favicons' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => 'https://fls-site.laravel.cloud/favicons']]);

        $this->get('/favicons/default/favicon.ico')->assertRedirect('https://fls-site.laravel.cloud/favicons/default/favicon.ico');
        $this->get('/favicons/../.env')->assertNotFound();
    }

    public function test_a_cached_config_keeps_its_disks(): void
    {
        $this->app->instance('config_loaded_from_cache', true);
        config(['filesystems.disks.favicons' => ['driver' => 'local', 'root' => '/x']]);

        (fn () => $this->fireAppCallbacks($this->bootingCallbacks))->call($this->app);

        $this->assertSame('local', config('filesystems.disks.favicons.driver'));
    }
}
