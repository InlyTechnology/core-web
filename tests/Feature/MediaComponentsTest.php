<?php

namespace Inly\CoreWeb\Tests\Feature;

use Exception;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Tests\TestCase;
use RuntimeException;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Image;
use Statamic\Tags\Tags;

class MediaComponentsTest extends TestCase
{
    protected array $features = ['media'];

    private const CDN = 'https://cdn.inlycore.com/client/site/assets';

    public function test_a_cdn_image_gets_transforms_a_srcset_its_box_and_a_placeholder(): void
    {
        $html = Blade::render('<x-core-img :src="$src" :widths="[480, 960]" sizes="50vw" ratio="4/3" alt="A car" class="w-full" />', ['src' => self::CDN.'/car.jpg']);

        $this->assertStringContainsString('src="'.e(self::CDN.'/car.jpg?format=auto').'"', $html);
        $this->assertStringContainsString(e(self::CDN.'/car.jpg?width=480&height=360&format=auto').' 480w, ', $html);
        $this->assertStringContainsString(e(self::CDN.'/car.jpg?width=960&height=720&format=auto').' 960w"', $html);
        $this->assertStringContainsString('sizes="50vw"', $html);
        $this->assertStringContainsString('alt="A car"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('data-placeholder="blur"', $html);
        $this->assertStringContainsString('--placeholder: url(&#039;'.e(self::CDN.'/car.jpg?width=24&height=18&format=auto&quality=30'), $html);
        $this->assertStringContainsString('aspect-ratio: 1.3333;', $html);
        $this->assertStringContainsString('class="w-full"', $html);
    }

    public function test_a_url_the_cdn_does_not_serve_renders_as_is(): void
    {
        $html = Blade::render('<x-core-img src="https://example.com/a.jpg" width="400" />');

        $this->assertStringContainsString('src="https://example.com/a.jpg"', $html);
        $this->assertStringNotContainsString('data-placeholder', $html);
        $this->assertStringNotContainsString('srcset', $html);
    }

    public function test_a_core_file_brings_its_alt_and_focal_point_and_a_caller_s_style_wins(): void
    {
        $file = ['original_url' => self::CDN.'/a.jpg', 'altText' => 'From Core', 'focalPoint' => ['x' => 30, 'y' => 70, 'z' => 1]];

        $html = Blade::render('<x-core-img :src="$file" placeholder="none" />', ['file' => $file]);
        $this->assertStringContainsString('alt="From Core"', $html);
        $this->assertStringContainsString('object-position: 30% 70%;', $html);
        $this->assertStringNotContainsString('data-placeholder', $html);

        $html = Blade::render('<x-core-img :src="$file" placeholder="none" style="object-position: top" alt="" />', ['file' => $file]);
        $this->assertStringContainsString('style="object-position: top;"', $html);
        $this->assertStringContainsString('alt=""', $html);
    }

    public function test_nothing_renders_without_a_source(): void
    {
        $this->assertSame('', trim(Blade::render('<x-core-img :src="null" /><x-core-video :src="null" /><x-core-media :src="[]" />')));
        $this->assertSame('', trim(Blade::render('<x-core-video src="'.self::CDN.'/a.jpg" />')), 'a picture is not a video');
    }

    public function test_a_cdn_video_plays_silent_versions_for_both_screens_with_a_frame_poster(): void
    {
        $html = Blade::render('<x-core-video :src="$src" alt="Hero" />', ['src' => self::CDN.'/hero.mov']);

        $this->assertStringContainsString('autoplay="autoplay" data-autoplay="data-autoplay" muted="muted" loop="loop" playsinline="playsinline" preload="metadata"', $html);
        $this->assertStringContainsString('poster="'.e(self::CDN.'/hero.mov?mode=frame&time=0&width=1280').'"', $html);
        $this->assertStringContainsString('<source src="'.e(self::CDN.'/hero.mov?mode=video&width=720&audio=false').'" media="(max-width: 767px)" type="video/mp4">', $html);
        $this->assertStringContainsString('<source src="'.e(self::CDN.'/hero.mov?mode=video&width=1280&audio=false').'" type="video/mp4">', $html);
        $this->assertStringContainsString('aria-label="Hero"', $html);
        $this->assertStringContainsString('data-placeholder="blur"', $html);
        $this->assertStringContainsString(e("--placeholder: url('".self::CDN."/hero.mov?mode=frame&time=0&width=24');"), $html);
    }

    public function test_a_video_placeholder_follows_its_poster_and_can_be_turned_off(): void
    {
        $own = Blade::render('<x-core-video :src="$src" :poster="$poster" />', ['src' => self::CDN.'/talk.mp4', 'poster' => self::CDN.'/still.jpg']);
        $this->assertStringContainsString(e(self::CDN.'/still.jpg?width=24&format=auto&quality=30'), $own);

        $timed = Blade::render('<x-core-video :src="$src" poster-time="3" />', ['src' => self::CDN.'/talk.mp4']);
        $this->assertStringContainsString(e(self::CDN.'/talk.mp4?mode=frame&time=3&width=24'), $timed);

        $none = Blade::render('<x-core-media :src="$src" placeholder="none" />', ['src' => self::CDN.'/talk.mp4']);
        $this->assertStringContainsString('poster=', $none);
        $this->assertStringNotContainsString('data-placeholder', $none);

        $foreign = Blade::render('<x-core-video :src="$src" poster="https://example.com/still.jpg" />', ['src' => self::CDN.'/talk.mp4']);
        $this->assertStringNotContainsString('data-placeholder', $foreign, 'a poster the CDN does not serve would download twice');

        config(['core-web.media.videos.poster_time' => null]);
        $noFrame = Blade::render('<x-core-video :src="$src" />', ['src' => self::CDN.'/talk.mp4']);
        $this->assertStringNotContainsString('poster=', $noFrame);
        $this->assertStringNotContainsString('data-placeholder', $noFrame);
    }

    public function test_a_video_with_sound_and_its_own_poster(): void
    {
        $html = Blade::render('<x-core-video :src="$src" :muted="false" :autoplay="false" controls :poster="$poster" />', [
            'src' => self::CDN.'/talk.mp4',
            'poster' => self::CDN.'/still.jpg',
        ]);

        $this->assertStringNotContainsString('autoplay', $html);
        $this->assertStringNotContainsString('audio=false', $html);
        $this->assertStringContainsString('poster="'.e(self::CDN.'/still.jpg?width=1280&format=auto').'"', $html);
    }

    public function test_a_video_the_cdn_does_not_serve_plays_the_file(): void
    {
        $html = Blade::render('<x-core-video :src="$file" />', ['file' => ['url' => '/assets/loop.webm', 'mime_type' => 'video/webm']]);

        $this->assertStringContainsString('<source src="/assets/loop.webm" type="video/webm">', $html);
        $this->assertStringNotContainsString('poster=', $html);
        $this->assertStringNotContainsString('data-placeholder', $html);
    }

    public function test_core_media_picks_the_component_and_gives_each_only_its_options(): void
    {
        $image = Blade::render('<x-core-media :src="$src" :widths="[320]" poster-time="2" class="cover" />', ['src' => self::CDN.'/a.jpg']);
        $this->assertStringStartsWith('<img', trim($image));
        $this->assertStringContainsString('320w', $image);
        $this->assertStringNotContainsString('poster-time', $image);

        $video = Blade::render('<x-core-media :src="$src" :widths="[320]" poster-time="2" class="cover" />', ['src' => self::CDN.'/a.mp4']);
        $this->assertStringStartsWith('<video', trim($video));
        $this->assertStringContainsString('time=2', $video);
        $this->assertStringNotContainsString('widths', $video);
        $this->assertStringContainsString('class="cover"', $video);
    }

    public function test_a_statamic_asset_brings_its_alt_and_focus(): void
    {
        $root = sys_get_temp_dir().'/core-web-media-'.uniqid();
        File::ensureDirectoryExists($root);
        File::copy(__DIR__.'/../fixtures/pixel.png', $root.'/pixel.png');
        config(['filesystems.disks.test_assets' => ['driver' => 'local', 'root' => $root, 'url' => self::CDN]]);

        try {
            $asset = Asset::make()->container(AssetContainer::make('test_assets')->disk('test_assets'))->path('pixel.png')->set('alt', 'A pixel')->set('focus', '25-75-1');

            $html = Blade::render('<x-core-media :src="$asset" placeholder="none" />', ['asset' => $asset]);

            $this->assertStringContainsString('src="'.e(self::CDN.'/pixel.png?format=auto').'"', $html);
            $this->assertStringContainsString('alt="A pixel"', $html);
            $this->assertStringContainsString('object-position: 25% 75%;', $html);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_an_asset_added_in_the_layer_renders_from_the_layer(): void
    {
        $root = sys_get_temp_dir().'/core-web-media-layer-'.uniqid();
        File::ensureDirectoryExists($root.'/lower');
        File::ensureDirectoryExists($root.'/upper');
        File::copy(__DIR__.'/../fixtures/pixel.png', $root.'/lower/production.png');
        File::copy(__DIR__.'/../fixtures/pixel.png', $root.'/upper/staging.png');
        config(['filesystems.disks.layered_assets' => [
            'driver' => MediaDisks::LAYERED_DRIVER,
            'name' => 'layered_assets',
            'upper' => ['driver' => 'local', 'root' => $root.'/upper', 'url' => '/media-layer/assets'],
            'lower' => ['driver' => 'local', 'root' => $root.'/lower', 'url' => self::CDN],
            'url' => self::CDN,
        ]]);

        try {
            $container = AssetContainer::make('layered_assets')->disk('layered_assets');

            $html = Blade::render('<x-core-img :src="$a" /><x-core-img :src="$b" />', [
                'a' => Asset::make()->container($container)->path('production.png'),
                'b' => Asset::make()->container($container)->path('staging.png'),
            ]);

            $this->assertStringContainsString('src="'.e(self::CDN.'/production.png?format=auto').'"', $html);
            $this->assertStringContainsString('src="/media-layer/assets/staging.png"', $html);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_a_local_asset_gets_a_srcset_of_the_glide_presets_narrower_than_itself(): void
    {
        $this->withLocalAssets(function (callable $asset): void {
            $html = Blade::render('<x-core-img :src="$asset" />', ['asset' => $asset('photo.jpg', 1200)]);

            $this->assertStringContainsString('src="/assets/photo.jpg"', $html);
            $this->assertMatchesRegularExpression('#srcset="[^"]*p=w640[^"]* 640w, [^"]*p=w1080[^"]* 1080w, /assets/photo\.jpg 1200w"#', $html);
            $this->assertStringNotContainsString('w1600', $html);
            $this->assertStringContainsString('sizes="100vw"', $html);

            $html = Blade::render('<x-core-img :src="$asset" sizes="50vw" />', ['asset' => $asset('photo.jpg', 1200)]);
            $this->assertStringContainsString('sizes="50vw"', $html);
        });
    }

    public function test_glide_presets_are_not_offered_for_an_svg_a_url_or_an_image_the_cdn_serves(): void
    {
        $this->withLocalAssets(function (callable $asset): void {
            File::put(config('filesystems.disks.local_assets.root').'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>');

            $html = Blade::render('<x-core-img :src="$svg" /><x-core-img src="/assets/photo.jpg" /><x-core-img :src="$cdn" placeholder="none" />', [
                'svg' => $asset('logo.svg'),
                'cdn' => Asset::make()->container(AssetContainer::make('cdn_assets')->disk('cdn_assets'))->path('photo.jpg'),
            ]);

            $this->assertStringNotContainsString('srcset', $html);
            $this->assertStringNotContainsString('sizes', $html);
            $this->assertStringContainsString('src="'.e(self::CDN.'/photo.jpg?format=auto').'"', $html);
        });
    }

    public function test_a_failing_glide_render_leaves_the_plain_image(): void
    {
        $this->withLocalAssets(function (callable $asset): void {
            app('statamic.tags')['glide'] = FailingGlideTag::class;

            $html = Blade::render('<x-core-img :src="$asset" />', ['asset' => $asset('photo.jpg', 1200)]);

            $this->assertStringContainsString('src="/assets/photo.jpg"', $html);
            $this->assertStringNotContainsString('srcset', $html);
            $this->assertStringNotContainsString('sizes', $html);
        });
    }

    public function test_a_glide_render_the_tag_swallows_leaves_the_plain_image(): void
    {
        $this->withLocalAssets(function (callable $asset): void {
            Image::shouldReceive('manipulate')->andThrow(new Exception('Glide is down'));

            $html = Blade::render('<x-core-img :src="$asset" />', ['asset' => $asset('photo.jpg', 1200)]);

            $this->assertStringContainsString('src="/assets/photo.jpg"', $html);
            $this->assertStringNotContainsString('srcset', $html);
            $this->assertStringNotContainsString('sizes', $html);
        });
    }

    /** @param  callable(callable(string, int=): \Statamic\Contracts\Assets\Asset): void  $test */
    private function withLocalAssets(callable $test): void
    {
        $root = sys_get_temp_dir().'/core-web-media-glide-'.uniqid();
        File::ensureDirectoryExists($root);
        config([
            'filesystems.disks.local_assets' => ['driver' => 'local', 'root' => $root, 'url' => '/assets'],
            'filesystems.disks.cdn_assets' => ['driver' => 'local', 'root' => $root, 'url' => self::CDN],
            'statamic.assets.image_manipulation.presets' => [
                'w1600' => ['w' => 1600],
                'w640' => ['w' => 640],
                'w1080' => ['w' => 1080],
                'card' => ['w' => 400, 'h' => 300],
            ],
        ]);
        $container = AssetContainer::make('local_assets')->disk('local_assets');

        try {
            $test(function (string $path, int $width = 0) use ($root, $container) {
                if ($width > 0) {
                    $image = imagecreatetruecolor($width, (int) round($width * 2 / 3));
                    imagejpeg($image, $root.'/'.$path);
                }

                return Asset::make()->container($container)->path($path);
            });
        } finally {
            File::deleteDirectory($root);
        }
    }
}

class FailingGlideTag extends Tags
{
    protected static $handle = 'glide';

    public function index(): string
    {
        throw new RuntimeException('Glide is down');
    }
}
