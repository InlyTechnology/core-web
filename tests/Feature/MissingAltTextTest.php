<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\Media\MissingAltText;
use Inly\CoreWeb\Media\ScanMissingAltTextJob;
use Inly\CoreWeb\Tests\TestCase;
use Inly\CoreWeb\Widgets\MissingAltTextWidget;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Statamic\Widgets\Loader;

class MissingAltTextTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['media'];

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/core-web-alt-'.uniqid();
        File::ensureDirectoryExists($this->scratch.'/photos');

        foreach (['with-alt.png', 'photos/no-alt.png', 'blank-alt.png'] as $path) {
            File::copy(__DIR__.'/../fixtures/pixel.png', "{$this->scratch}/{$path}");
        }

        File::put($this->scratch.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        File::put($this->scratch.'/price-list.pdf', '%PDF-1.4');
        config(['filesystems.disks.alt_test' => ['driver' => 'local', 'root' => $this->scratch, 'url' => '/alt']]);
        AssetContainer::make('photos')->title('Photos')->disk('alt_test')->save();

        Asset::find('photos::with-alt.png')->set('alt', 'A red pixel')->save();
        Asset::find('photos::blank-alt.png')->set('alt', '  ')->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    private function widget(array $config = []): string
    {
        return (string) app(Loader::class)->load('missing_alt_text', ['type' => 'missing_alt_text', 'container' => 'photos', ...$config])->html();
    }

    /** A visit on a sync queue scans after its response; the next visit shows the count. */
    private function visitTwice(array $config = []): string
    {
        $this->widget($config);
        $this->app->terminate();

        return $this->widget($config);
    }

    public function test_it_counts_the_images_and_svgs_without_alt_text(): void
    {
        $scan = MissingAltText::scan('photos');

        $this->assertSame(3, $scan['count']);
        $this->assertEqualsCanonicalizing(['photos::blank-alt.png', 'photos::logo.svg', 'photos::photos/no-alt.png'], $scan['ids']);
    }

    public function test_the_widget_scans_on_its_first_visit_and_lists_the_newest(): void
    {
        $this->assertSame(MissingAltTextWidget::class, app('statamic.widgets')->get('missing_alt_text'));
        $this->assertNull(MissingAltText::cached('photos'));

        $this->assertStringContainsString('Counting the images', $this->widget(['limit' => 2]));
        $this->assertNull(MissingAltText::cached('photos'));

        $html = $this->visitTwice(['limit' => 2]);

        $this->assertNotNull(MissingAltText::cached('photos'));
        $this->assertStringContainsString('text="3"', $html);
        $this->assertSame(2, substr_count($html, 'Add alt text'));
        $this->assertStringContainsString('Images missing alt text', $html);
    }

    public function test_with_a_queue_worker_the_visit_only_queues_the_scan(): void
    {
        Queue::fake();

        $this->assertStringContainsString('Counting the images', $this->widget());
        Queue::assertPushed(ScanMissingAltTextJob::class, fn (ScanMissingAltTextJob $job) => $job->container === 'photos');
    }

    public function test_saving_an_asset_counts_again_on_the_next_visit(): void
    {
        $this->visitTwice();

        Asset::find('photos::photos/no-alt.png')->set('alt', 'Fixed')->save();
        $this->assertNull(MissingAltText::cached('photos'));
        $this->assertStringContainsString('text="2"', $this->visitTwice());
    }

    public function test_each_field_keeps_its_own_count(): void
    {
        $this->assertSame(3, MissingAltText::scan('photos')['count']);
        $this->assertSame(4, MissingAltText::scan('photos', 'alt_en')['count']);
        $this->assertSame(3, MissingAltText::cached('photos')['count']);
    }

    public function test_all_clear_and_an_unknown_container(): void
    {
        foreach (['photos/no-alt.png', 'blank-alt.png', 'logo.svg'] as $path) {
            Asset::find("photos::{$path}")->set('alt', 'Described')->save();
        }

        $this->assertStringContainsString('All clear', $this->visitTwice());
        $this->assertStringContainsString('Asset container [nope] does not exist.', $this->widget(['container' => 'nope']));
    }
}
