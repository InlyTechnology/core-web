<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Media\CoreCdnScheme;
use Inly\CoreWeb\Media\UrlScheme;
use Inly\CoreWeb\Tests\TestCase;

class MediaConfigTest extends TestCase
{
    protected array $features = ['media'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        putenv('CORE_MEDIA_KEY=key');
        putenv('CORE_MEDIA_SECRET=secret');

        // As a site's published config sets it: Laravel replaces the package's whole `media` key with this.
        $app['config']->set('core-web.media', [
            'bucket' => ['bucket' => 'site', 'region' => 'ams3'],
            'disks' => ['images' => 'images'],
        ]);
    }

    protected function tearDown(): void
    {
        putenv('CORE_MEDIA_KEY');
        putenv('CORE_MEDIA_SECRET');

        parent::tearDown();
    }

    public function test_a_site_block_keeps_the_package_defaults_it_leaves_out(): void
    {
        $this->assertSame('site', config('core-web.media.bucket.bucket'));
        $this->assertSame('key', config('core-web.media.bucket.key'));
        $this->assertSame('public, max-age=2592000', config('core-web.media.bucket.cache_control'));
        $this->assertSame('_staging', config('core-web.media.layer.prefix'));
        $this->assertSame(['https://cdn.inlycore.com'], config('core-web.media.cdn.origins'));
        $this->assertInstanceOf(CoreCdnScheme::class, app(UrlScheme::class));
    }

    public function test_the_site_disks_are_taken_whole(): void
    {
        $this->assertSame(['images' => 'images'], config('core-web.media.disks'));
        $this->assertTrue(config()->has('filesystems.disks.images'));
        $this->assertFalse(config()->has('filesystems.disks.assets'));
    }
}
