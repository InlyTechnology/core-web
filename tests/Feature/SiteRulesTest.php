<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\File;
use Inly\CoreWeb\Tests\TestCase;
use Tests\Central\CoreWebRulesTest;

/** The rules site-ci copies from `rules/` into a site, run against this test's app as the site. */
class SiteRulesTest extends TestCase
{
    protected array $features = ['media'];

    /** @var array<string, array<string, mixed>> */
    private array $siteDisks = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach ($this->siteDisks as $disk => $config) {
            $app['config']->set("filesystems.disks.{$disk}", $config);
        }
    }

    protected function setUp(): void
    {
        $this->siteDisks = $this->name() === 'test_a_site_keeping_its_own_assets_disk_is_reported'
            ? ['assets' => ['driver' => 'local', 'root' => '/tmp']]
            : [];

        parent::setUp();

        // The rule suite extends the site's own Tests\TestCase, which a package has not got.
        if (! class_exists(\Tests\TestCase::class)) {
            class_alias(TestCase::class, \Tests\TestCase::class);
        }
        require_once __DIR__.'/../../rules/CoreWebRulesTest.php';
    }

    public function test_a_site_keeping_its_own_assets_disk_is_reported(): void
    {
        $this->assertSame(["disk 'assets' (config/filesystems.php or a service provider)"], $this->rule('siteDefinedMediaDisks')[0]);
    }

    public function test_a_site_leaving_the_media_disks_to_core_web_passes(): void
    {
        $this->assertSame([], $this->rule('siteDefinedMediaDisks')[0]);
    }

    public function test_a_site_without_the_media_feature_is_not_checked(): void
    {
        config(['core-web.features.media' => false]);

        $this->assertSame([], $this->rule('siteDefinedMediaDisks')[0]);
    }

    public function test_a_picture_without_widths_is_reported(): void
    {
        $this->siteView('cards/card.blade.php', "<div>\n    <x-core-img :src=\"\$image\" sizes=\"(min-width: 1024px) 33vw, 100vw\"\n        class=\"object-cover\" />\n</div>\n");

        $this->assertSame(
            ['resources/views/rule-fixtures/cards/card.blade.php:2  <x-core-img :src="$image" sizes="(min-width: 1024px) 33vw, 100vw" class="object-cover" />'],
            $this->rule('coreImagesWithoutWidths')[0],
        );
    }

    public function test_a_media_tag_without_widths_is_reported(): void
    {
        $this->siteView('media.blade.php', '<x-core-media :src="$media" :small-width="480" />');

        $this->assertCount(1, $this->rule('coreImagesWithoutWidths')[0]);
    }

    public function test_pictures_that_say_their_sizes_pass(): void
    {
        $this->siteView('ok.blade.php', implode("\n", [
            '<x-core-img :src="$a" :widths="[320, 640]" sizes="100vw" />',
            '<x-core-img :src="$b" widths="320,640" />',
            '<x-core-img :src="$c" :width="120" alt="a > b" />',
            '<x-core-img :src="$d" :widths="[]" />',
            '<x-core-img {{ $attributes->merge([\'class\' => \'w-full\']) }} />',
            '{{-- <x-core-img :src="$e" /> --}}',
            '<x-core-video :src="$f" />',
        ]));

        $this->assertSame([], $this->rule('coreImagesWithoutWidths')[0]);
    }

    public function test_pictures_are_not_checked_without_the_media_feature(): void
    {
        $this->siteView('off.blade.php', '<x-core-img :src="$a" />');
        config(['core-web.features.media' => false]);

        $this->assertSame([], $this->rule('coreImagesWithoutWidths')[0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('views/rule-fixtures'));

        parent::tearDown();
    }

    private function siteView(string $path, string $code): void
    {
        File::ensureDirectoryExists(dirname(resource_path("views/rule-fixtures/{$path}")));
        File::put(resource_path("views/rule-fixtures/{$path}"), $code);
    }

    /** @return array{list<string>, string} */
    private function rule(string $rule): array
    {
        return (fn () => $this->{$rule}())->call(new CoreWebRulesTest('test_the_site_follows_the_rule'));
    }
}
