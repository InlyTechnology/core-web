<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Media\CpThumbnails;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Statamic\Widgets\Loader;

/** A media disk behind the CDN, as on Laravel Cloud: its files are local here, its URLs are the CDN's. */
class CpThumbnailsTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const CDN = 'https://cdn.inlycore.com/site/storage/assets';

    protected array $features = ['media'];

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(MediaDisks::defines('assets'));

        $this->scratch = sys_get_temp_dir().'/core-web-thumbs-'.uniqid();
        File::ensureDirectoryExists($this->scratch);
        File::copy(__DIR__.'/../fixtures/pixel.png', $this->scratch.'/hero.png');
        File::put($this->scratch.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        File::put($this->scratch.'/clip.mp4', 'clip');
        File::put($this->scratch.'/clip.webm', 'clip');
        // Sparse: a size past the CDN's frame limit without writing the bytes.
        $handle = fopen($this->scratch.'/long.mp4', 'w');
        ftruncate($handle, 100_000_000);
        fclose($handle);

        config(['filesystems.disks.assets.root' => $this->scratch, 'filesystems.disks.assets.url' => self::CDN]);
        Storage::forgetDisk('assets');
        AssetContainer::make('assets')->disk('assets')->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_the_control_panel_s_thumbnails_come_from_the_cdn(): void
    {
        $assets = collect($this->browse('assets'))->keyBy('basename');

        $this->assertSame(self::CDN.'/hero.png?width=400&height=400&fit=contain&format=auto', $assets['hero.png']['thumbnail']);
        $this->assertSame(self::CDN.'/hero.png', $assets['hero.png']['preview']);
        $this->assertSame(self::CDN.'/clip.mp4?mode=frame&time=0&width=400&height=400&fit=cover', $assets['clip.mp4']['thumbnail']);
        $this->assertSame(self::CDN.'/logo.svg', $assets['logo.svg']['thumbnail'], 'An SVG is its own thumbnail.');

        $show = $this->getJson(cp_route('assets.show', base64_encode('assets::hero.png')))->assertOk()->json('data');
        $this->assertSame($assets['hero.png']['thumbnail'], $show['thumbnail']);
    }

    public function test_a_video_the_cdn_cannot_frame_keeps_statamic_s_thumbnail(): void
    {
        $assets = collect($this->browse('assets'))->keyBy('basename');

        foreach (['clip.webm', 'long.mp4'] as $video) {
            $this->assertArrayHasKey($video, $assets->all());
            $this->assertStringStartsNotWith(self::CDN, (string) ($assets[$video]['thumbnail'] ?? ''), $video);
        }
    }

    public function test_the_thumbnail_route_listings_build_sends_them_to_the_cdn(): void
    {
        $this->actingAs($this->super())
            ->get(cp_route('assets.thumbnails.show', ['encoded_asset' => base64_encode('assets::hero.png'), 'size' => 'small']))
            ->assertRedirect(self::CDN.'/hero.png?width=400&height=400&fit=contain&format=auto');
    }

    public function test_where_the_cdn_does_not_serve_the_disk_glide_makes_the_thumbnail(): void
    {
        config(['core-web.media.cdn.origins' => []]);

        $assets = collect($this->browse('assets'))->keyBy('basename');

        $this->assertStringContainsString('/thumbnails/', $assets['hero.png']['thumbnail']);
        $this->assertNull(CpThumbnails::url(Asset::find('assets::hero.png')));
    }

    public function test_a_disk_the_app_defines_itself_keeps_glide(): void
    {
        config(['filesystems.disks.own' => ['driver' => 'local', 'root' => $this->scratch, 'url' => self::CDN]]);
        AssetContainer::make('own')->disk('own')->save();

        $hero = collect($this->browse('own'))->firstWhere('basename', 'hero.png');

        $this->assertStringContainsString('/thumbnails/', $hero['thumbnail']);
    }

    public function test_a_private_container_keeps_glide(): void
    {
        config(['filesystems.disks.assets.url' => null]);
        Storage::forgetDisk('assets');

        $this->assertNull(CpThumbnails::url(Asset::find('assets::hero.png')));
    }

    public function test_the_missing_alt_text_widget_shows_the_cdn_s_thumbnail(): void
    {
        $this->widget();
        $this->app->terminate();

        $this->assertStringContainsString(e(self::CDN.'/hero.png?width=400&height=400&fit=contain&format=auto'), $this->widget());
    }

    private function widget(): string
    {
        return (string) app(Loader::class)->load('missing_alt_text', ['type' => 'missing_alt_text', 'container' => 'assets'])->html();
    }

    /** @return list<array<string, mixed>> */
    private function browse(string $container): array
    {
        return $this->actingAs($this->super())->getJson(cp_route('assets.browse.index').'/folders/'.$container)->assertOk()->json('data');
    }

    private function super(): UserContract
    {
        return User::find('boss') ?? tap(User::make()->id('boss')->email('boss@example.com')->makeSuper())->save();
    }
}
