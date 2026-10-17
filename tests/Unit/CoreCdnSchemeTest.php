<?php

namespace Inly\CoreWeb\Tests\Unit;

use Inly\CoreWeb\Media\CoreCdnScheme;
use Inly\CoreWeb\Media\PassthroughScheme;
use PHPUnit\Framework\TestCase;

class CoreCdnSchemeTest extends TestCase
{
    private const CDN = 'https://cdn.inlycore.com';

    public function test_its_ranges_match_the_core_cdn_contract(): void
    {
        $contract = json_decode((string) file_get_contents(__DIR__.'/../fixtures/core-cdn-v1-contract.json'), true);

        $this->assertSame($contract['image']['dimension'], CoreCdnScheme::IMAGE_DIMENSION);
        $this->assertSame($contract['image']['fit'], CoreCdnScheme::IMAGE_FITS);
        $this->assertSame($contract['image']['format'], CoreCdnScheme::IMAGE_FORMATS);
        $this->assertSame($contract['image']['quality'], CoreCdnScheme::QUALITY);
        $this->assertSame($contract['image']['dpr'], CoreCdnScheme::DPR);
        $this->assertSame($contract['video']['dimension'], CoreCdnScheme::VIDEO_DIMENSION);
    }

    public function test_it_serves_only_urls_under_one_of_its_origins(): void
    {
        $scheme = new CoreCdnScheme([self::CDN.'/', 'https://media.example.com', '']);

        $this->assertTrue($scheme->serves(self::CDN.'/client/site/a.jpg'));
        $this->assertTrue($scheme->serves('https://media.example.com/a.jpg'));
        $this->assertFalse($scheme->serves('https://cdn.inlycore.com.evil.test/a.jpg'));
        $this->assertFalse($scheme->serves('/assets/a.jpg'));
        $this->assertFalse((new CoreCdnScheme([]))->serves(self::CDN.'/a.jpg'));
    }

    public function test_image_params_are_clamped_filtered_and_merged_with_the_query(): void
    {
        $url = (new CoreCdnScheme(self::CDN))->image(self::CDN.'/c/s/a.jpg?v=2', [
            'width' => 9000, 'height' => 0, 'fit' => 'stretch', 'format' => 'auto', 'quality' => 300, 'dpr' => 2, 'trim' => true,
        ]);

        $this->assertSame(self::CDN.'/c/s/a.jpg?v=2&width=5000&height=1&trim=border&format=auto&quality=100&dpr=2', $url);
    }

    public function test_svgs_and_foreign_urls_are_untouched(): void
    {
        $scheme = new CoreCdnScheme(self::CDN);

        $this->assertSame(self::CDN.'/c/s/logo.svg', $scheme->image(self::CDN.'/c/s/logo.svg', ['width' => 100]));
        $this->assertSame('https://example.com/a.jpg', $scheme->image('https://example.com/a.jpg', ['width' => 100]));
        $this->assertSame('https://example.com/a.mp4', $scheme->video('https://example.com/a.mp4', ['width' => 100]));
        $this->assertNull($scheme->frame('https://example.com/a.mp4', 1));
    }

    public function test_video_and_frame_urls(): void
    {
        $scheme = new CoreCdnScheme(self::CDN);

        $this->assertSame(self::CDN.'/v.mp4?mode=video&width=2000&audio=false', $scheme->video(self::CDN.'/v.mp4', ['width' => 3200, 'audio' => false]));
        $this->assertSame(self::CDN.'/v.mp4?mode=video&width=10', $scheme->video(self::CDN.'/v.mp4', ['width' => 1]));
        $this->assertSame(self::CDN.'/v.mp4?mode=frame&time=1.5&width=640&height=360&fit=cover', $scheme->frame(self::CDN.'/v.mp4', 1.5, 640, 360));
        $this->assertSame(self::CDN.'/v.mp4?mode=frame&time=0', $scheme->frame(self::CDN.'/v.mp4', 0));
    }

    public function test_the_passthrough_scheme_changes_nothing(): void
    {
        $scheme = new PassthroughScheme;

        $this->assertFalse($scheme->serves(self::CDN.'/a.jpg'));
        $this->assertSame(self::CDN.'/a.jpg', $scheme->image(self::CDN.'/a.jpg', ['width' => 10]));
        $this->assertNull($scheme->frame(self::CDN.'/a.mp4', 0));
    }
}
