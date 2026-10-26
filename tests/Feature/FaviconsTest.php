<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Inly\CoreWeb\Favicons\CpFavicon;
use Inly\CoreWeb\Favicons\Favicons;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class FaviconsTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['favicons', 'media'];

    private const CDN = 'https://cdn.inlycore.com/client/site/favicons';

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/core-web-favicons-'.uniqid();
        File::ensureDirectoryExists($this->scratch.'/files');
        File::copy(__DIR__.'/../fixtures/pixel.png', $this->scratch.'/files/icon.png');
        File::copy(__DIR__.'/../fixtures/pixel.png', $this->scratch.'/files/icon-dark.png');
        File::put($this->scratch.'/files/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        File::put($this->scratch.'/files/icon-dark.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        config(['filesystems.disks.favicons' => ['driver' => 'local', 'root' => $this->scratch.'/files', 'url' => self::CDN]]);
        AssetContainer::make('favicons')->disk('favicons')->save();

        Blueprint::setDirectory($this->scratch.'/blueprints');
        Blueprint::make('favicons')->setNamespace('globals')->setContents(['fields' => [['import' => 'core-web::favicons']]])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $data */
    private function favicons(array $data): void
    {
        $set = GlobalSet::make('favicons')->title('Favicons');
        $set->save();
        $set->in('default')->data($data)->save();
    }

    public function test_every_size_is_a_cdn_crop_of_the_one_png(): void
    {
        $this->favicons(['favicon' => 'icon.png', 'theme_color' => '#112233']);

        $html = Blade::render('<x-core-web::favicons.head />');

        $this->assertStringContainsString('<link rel="icon" type="image/png" sizes="32x32" href="'.e(self::CDN.'/icon.png?width=32&height=32&fit=cover&format=png').'">', $html);
        $this->assertStringContainsString('<link rel="apple-touch-icon" sizes="180x180" href="'.e(self::CDN.'/icon.png?width=180&height=180&fit=cover&format=png').'">', $html);
        $this->assertStringContainsString('<link rel="manifest" href="http://default.test/site.webmanifest">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#112233">', $html);
        $this->assertStringNotContainsString('image/svg+xml', $html);
    }

    public function test_svgs_are_linked_for_light_and_dark(): void
    {
        $this->favicons(['favicon' => 'icon.png', 'favicon_svg' => 'icon.svg', 'favicon_svg_dark' => 'icon-dark.svg']);

        $html = Blade::render('<x-core-web::favicons.head />');

        $this->assertStringContainsString('<link rel="icon" type="image/svg+xml" href="'.self::CDN.'/icon.svg" media="(prefers-color-scheme: light)">', $html);
        $this->assertStringContainsString('<link rel="icon" type="image/svg+xml" href="'.self::CDN.'/icon-dark.svg" media="(prefers-color-scheme: dark)">', $html);
        $this->assertLessThan(strpos($html, 'image/svg+xml'), strpos($html, 'image/png'));
    }

    public function test_an_svg_alone_is_linked_without_media(): void
    {
        $this->favicons(['favicon_svg' => 'icon.svg']);

        $html = Blade::render('<x-core-web::favicons.head />');

        $this->assertStringContainsString('<link rel="icon" type="image/svg+xml" href="'.self::CDN.'/icon.svg">', $html);
        $this->assertStringNotContainsString('manifest', $html);
    }

    public function test_nothing_renders_without_a_global(): void
    {
        $this->assertSame('', trim(Blade::render('<x-core-web::favicons.head />')));
    }

    public function test_the_manifest_lists_the_app_icons(): void
    {
        config(['app.name' => 'Acme']);
        $this->favicons(['favicon' => 'icon.png', 'theme_color' => '#112233']);

        $this->get('/site.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'name' => 'Acme',
                'display' => 'standalone',
                'theme_color' => '#112233',
                'icons' => [
                    ['src' => self::CDN.'/icon.png?width=192&height=192&fit=cover&format=png', 'sizes' => '192x192', 'type' => 'image/png'],
                    ['src' => self::CDN.'/icon.png?width=512&height=512&fit=cover&format=png', 'sizes' => '512x512', 'type' => 'image/png'],
                ],
            ]);
    }

    public function test_a_manifest_under_a_path_no_site_has_is_not_found(): void
    {
        $this->favicons(['favicon' => 'icon.png']);

        $this->get('/nope/site.webmanifest')->assertNotFound();
    }

    public function test_favicon_ico_redirects_to_the_tab_png(): void
    {
        $this->favicons(['favicon' => 'icon.png']);

        $this->get('/favicon.ico')->assertRedirect(self::CDN.'/icon.png?width=32&height=32&fit=cover&format=png');
    }

    public function test_favicon_ico_is_not_found_without_an_icon(): void
    {
        $this->get('/favicon.ico')->assertNotFound();
    }

    public function test_a_file_the_cdn_does_not_serve_falls_back_to_glide(): void
    {
        config(['filesystems.disks.favicons.url' => '/favicons']);
        $this->favicons(['favicon' => 'icon.png']);

        $url = Favicons::png(Favicons::APPLE);

        $this->assertStringContainsString('/img/asset/', (string) $url);
        $this->assertStringContainsString('w=180&h=180&fit=crop&fm=png', (string) $url);
    }

    public function test_a_dark_png_switches_the_tab_icon_in_dark_mode(): void
    {
        $this->favicons(['favicon' => 'icon.png', 'favicon_dark' => 'icon-dark.png']);

        $html = Blade::render('<x-core-web::favicons.head />');

        $this->assertStringContainsString('href="'.e(self::CDN.'/icon.png?width=32&height=32&fit=cover&format=png').'" media="(prefers-color-scheme: light)">', $html);
        $this->assertGreaterThan(strpos($html, 'icon-dark.png'), strpos($html, 'icon.png?width=32'));
        $this->assertStringContainsString('href="'.e(self::CDN.'/icon-dark.png?width=32&height=32&fit=cover&format=png').'" media="(prefers-color-scheme: dark)">', $html);
        $this->assertStringContainsString(e(self::CDN.'/icon.png?width=180'), $html);
    }

    public function test_saving_the_global_writes_the_marked_cp_icon_and_pro_uses_it(): void
    {
        $this->favicons(['favicon' => 'icon.png']);

        $this->assertFileExists($this->scratch.'/files/'.CpFavicon::PATH);
        $this->assertSame([64, 64], array_slice(getimagesize($this->scratch.'/files/'.CpFavicon::PATH), 0, 2));

        config(['statamic.editions.pro' => true, 'statamic.cp.custom_favicon_url' => null]);
        view('statamic::partials.head')->render();

        $this->assertSame('favicons::'.CpFavicon::PATH, config('statamic.cp.custom_favicon_url'));
    }

    public function test_solo_keeps_statamic_s_cp_icon(): void
    {
        $this->favicons(['favicon' => 'icon.png']);
        config(['statamic.editions.pro' => false, 'statamic.cp.custom_favicon_url' => null]);

        view('statamic::partials.head')->render();

        $this->assertNull(config('statamic.cp.custom_favicon_url'));
    }

    public function test_a_site_s_own_cp_favicon_wins(): void
    {
        $this->favicons(['favicon' => 'icon.png']);
        config(['statamic.editions.pro' => true, 'statamic.cp.custom_favicon_url' => '/own.png']);

        view('statamic::partials.head')->render();

        $this->assertSame('/own.png', config('statamic.cp.custom_favicon_url'));
    }

    public function test_a_new_icon_rewrites_the_cp_icon_cropped_square_and_a_removed_one_deletes_it(): void
    {
        $this->favicons(['favicon' => 'icon.png']);
        $first = File::get($this->scratch.'/files/'.CpFavicon::PATH);

        // 200 x 100: a blue left square that the centre crop drops, and a red centre it keeps.
        $wide = imagecreatetruecolor(200, 100);
        imagefill($wide, 0, 0, imagecolorallocate($wide, 0, 0, 255));
        imagefilledrectangle($wide, 50, 0, 149, 99, imagecolorallocate($wide, 255, 0, 0));
        imagepng($wide, $this->scratch.'/files/wide.png');
        AssetContainer::findByHandle('favicons')->makeAsset('wide.png')->save();
        $this->favicons(['favicon' => 'wide.png']);

        $marked = imagecreatefrompng($this->scratch.'/files/'.CpFavicon::PATH);
        $this->assertNotSame($first, File::get($this->scratch.'/files/'.CpFavicon::PATH));
        $this->assertSame([255, 0, 0], array_values(array_slice(imagecolorsforindex($marked, imagecolorat($marked, 2, 2)), 0, 3)));

        $this->favicons([]);
        $this->assertFileDoesNotExist($this->scratch.'/files/'.CpFavicon::PATH);
    }
}
