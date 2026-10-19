<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Inly\CoreWeb\ContentSync\LocalContent;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Media\ProductionFileLocked;
use Inly\CoreWeb\Media\RemoteIndex;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** The checkout's half: the live site's answers are faked, as the export's own test covers that side. */
class AssetPullTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['content_sync', 'media'];

    private string $dir;

    /** @var array<string, array{url: ?string, meta: ?array<string, mixed>}> */
    private array $live = [];

    private int $exports = 0;

    private bool $forbidden = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/core-web-asset-pull-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/storage/framework/cache');
        $this->app->useStoragePath($this->dir.'/storage');
        $this->app->usePublicPath($this->dir.'/public');
        $this->app['env'] = 'local';

        config(['core-web.content_sync.remote_url' => 'https://remote.test']);
        config(['filesystems.disks' => array_diff_key(config('filesystems.disks'), ['assets' => true])]);
        MediaDisks::define($this->app);
        Storage::forgetDisk('assets');

        AssetContainer::make('assets')->disk('assets')->save();

        $this->app->instance(LocalContent::class, new class($this->dir.'/content', $this->dir.'/content.lock') extends LocalContent
        {
            public int $refreshes = 0;

            public function refreshStache(): void
            {
                $this->refreshes++;
            }
        });

        RemoteSite::store('https://remote.test', 'token', ['name' => 'Ann', 'email' => 'ann@example.com'], now()->addDays(30)->toIso8601String());

        $this->live = [
            'hero.jpg' => ['url' => 'https://cdn.test/site/assets/hero.jpg', 'meta' => ['data' => ['alt' => 'From the live site', 'focus' => '20-80-1'], 'size' => 4, 'mime_type' => 'image/jpeg', 'width' => 1600, 'height' => 900, 'last_modified' => 1700000000]],
            'team/anna.jpg' => ['url' => 'https://cdn.test/site/assets/team/anna.jpg', 'meta' => null],
        ];

        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://cdn.test/')) {
                return Http::response('live bytes of '.basename(parse_url($request->url(), PHP_URL_PATH)));
            }

            if (str_contains($request->url(), '/!/core-web/sync/export') && $request['type'] === 'assets') {
                $this->exports++;

                if ($this->forbidden) {
                    return Http::response(['error' => 'forbidden'], 403);
                }

                $data = [];

                foreach ($this->live as $path => $file) {
                    $data[] = ['type' => 'assets', 'id' => 'assets::'.$path, 'container' => 'assets', 'path' => $path, ...$file];
                }

                return Http::response(['data' => $data, 'next_cursor' => null, 'containers' => ['assets' => ['url' => 'https://cdn.test/site/assets']]]);
            }

            return Http::response(['data' => [], 'next_cursor' => null]);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function pull(array $options = []): PendingCommand
    {
        return $this->artisan('content:pull', ['--no-interaction' => true, '--only' => 'assets', ...$options]);
    }

    private function fresh(): void
    {
        Blink::flush();
        Storage::forgetDisk('assets');
    }

    public function test_a_local_checkout_without_a_key_layers_the_live_index_under_a_local_folder(): void
    {
        $disk = config('filesystems.disks.assets');
        $this->artisan('media:check')->expectsOutputToContain('assets: the live site\'s files as content:pull last listed them')->assertSuccessful();

        $this->assertSame(MediaDisks::LAYERED_DRIVER, $disk['driver']);
        $this->assertSame($this->dir.'/public/media-layer/assets', $disk['upper']['root']);
        $this->assertSame(MediaDisks::REMOTE_INDEX_DRIVER, $disk['lower']['driver']);
        $this->assertTrue(MediaDisks::isRemoteIndexed('assets'));
    }

    public function test_the_pull_writes_the_index_to_storage_and_no_file(): void
    {
        $this->pull()->expectsOutputToContain('Assets assets: 2 files indexed')->assertSuccessful();

        $index = json_decode(file_get_contents($this->dir.'/storage/core-web/remote-assets/assets/assets.json'), true);
        $this->assertSame('https://remote.test', $index['remote']);
        $this->assertSame('https://cdn.test/site/assets', $index['url']);
        $this->assertSame(['hero.jpg', 'team/anna.jpg'], array_keys($index['files']));
        $this->assertSame('From the live site', $index['files']['hero.jpg']['meta']['data']['alt']);
        $this->assertFileExists($this->dir.'/storage/core-web/remote-assets/.gitignore');
        $this->assertDirectoryDoesNotExist($this->dir.'/public/media-layer');
        $this->assertSame(1, app(LocalContent::class)->refreshes);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://cdn.test/'));
    }

    public function test_the_container_lists_the_live_files_and_local_uploads_together(): void
    {
        $this->pull()->assertSuccessful();
        Storage::disk('assets')->put('local/upload.jpg', 'mine');
        $this->fresh();

        $container = AssetContainer::find('assets');
        $this->assertSame(['hero.jpg', 'local/upload.jpg', 'team/anna.jpg'], $container->files()->sort()->values()->all());

        $hero = Asset::find('assets::hero.jpg');
        $this->assertSame('From the live site', $hero->get('alt'));
        $this->assertSame(1600, $hero->width());
        $this->assertSame('https://cdn.test/site/assets/hero.jpg', $hero->url());
        $this->assertSame('live bytes of hero.jpg', Storage::disk('assets')->get('hero.jpg'));
    }

    public function test_the_control_panel_browses_the_merged_container(): void
    {
        $this->pull()->assertSuccessful();
        Storage::disk('assets')->put('upload.jpg', 'mine');
        $this->fresh();

        $super = User::make()->email('boss@example.com')->makeSuper();
        $super->save();

        $assets = $this->actingAs($super)->getJson(cp_route('assets.browse.index').'/folders/assets')->assertOk()->json('data');

        $this->assertSame(['hero.jpg', 'upload.jpg'], collect($assets)->pluck('basename')->sort()->values()->all());
        $this->assertSame('https://cdn.test/site/assets/hero.jpg', collect($assets)->firstWhere('basename', 'hero.jpg')['url']);
    }

    public function test_a_remote_file_s_url_is_its_public_url(): void
    {
        $this->pull()->assertSuccessful();

        $this->assertSame('https://cdn.test/site/assets/team/anna.jpg', Storage::disk('assets')->url('team/anna.jpg'));
        $this->assertSame('https://cdn.test/site/assets', AssetContainer::find('assets')->url());
    }

    public function test_an_upload_lands_in_the_local_layer_only(): void
    {
        $this->pull()->assertSuccessful();

        $this->assertTrue(Storage::disk('assets')->put('new.jpg', 'mine'));
        Storage::disk('assets')->put('.meta/hero.jpg.yaml', "data:\n  alt: Edited here\n");

        $this->assertFileExists($this->dir.'/public/media-layer/assets/new.jpg');
        $this->assertSame('/media-layer/assets/new.jpg', Storage::disk('assets')->url('new.jpg'));
        $this->assertSame("data:\n  alt: Edited here\n", Storage::disk('assets')->get('.meta/hero.jpg.yaml'));
        $this->assertCount(2, json_decode(file_get_contents($this->dir.'/storage/core-web/remote-assets/assets/assets.json'), true)['files']);
    }

    public function test_a_live_file_cannot_be_deleted_or_moved(): void
    {
        $this->pull()->assertSuccessful();

        foreach ([fn ($disk) => $disk->delete('hero.jpg'), fn ($disk) => $disk->move('hero.jpg', 'moved.jpg')] as $change) {
            try {
                $change(Storage::disk('assets'));
                $this->fail('Changing a live file must throw.');
            } catch (ProductionFileLocked) {
                $this->assertTrue(Storage::disk('assets')->exists('hero.jpg'));
            }
        }
    }

    public function test_a_pull_replaces_the_index_and_keeps_local_files(): void
    {
        $this->pull()->assertSuccessful();
        Storage::disk('assets')->put('new.jpg', 'mine');

        unset($this->live['team/anna.jpg']);
        $this->live['hero.jpg']['meta']['data']['alt'] = 'Changed on the live site';
        $this->live['blog/post.png'] = ['url' => 'https://cdn.test/site/assets/blog/post.png', 'meta' => null];
        Asset::find('assets::hero.jpg')->get('alt');

        $this->pull()->expectsOutputToContain('Assets assets: 2 files indexed')->assertSuccessful();
        $this->fresh();

        $this->assertSame(['blog/post.png', 'hero.jpg', 'new.jpg'], AssetContainer::find('assets')->files()->sort()->values()->all());
        $this->assertSame('Changed on the live site', Asset::find('assets::hero.jpg')->get('alt'));
        $this->assertFileExists($this->dir.'/public/media-layer/assets/new.jpg');
    }

    public function test_a_dry_run_writes_no_index(): void
    {
        $this->pull(['--dry-run' => true])->expectsOutputToContain('Dry run: nothing was written.')->assertSuccessful();

        $this->assertSame([], (new RemoteIndex($this->dir.'/storage/core-web/remote-assets/assets'))->files());
    }

    public function test_a_path_out_of_the_container_stops_the_pull(): void
    {
        $this->pull()->assertSuccessful();

        foreach (['../../.env', '/etc/passwd', 'team/.meta/anna.jpg.yaml', "a\0b.jpg"] as $path) {
            $this->live = [$path => ['url' => 'https://cdn.test/x.jpg', 'meta' => null]];

            $this->pull()->expectsOutputToContain('nothing was written')->assertFailed();
        }

        $this->assertSame(['hero.jpg', 'team/anna.jpg'], array_keys((new RemoteIndex($this->dir.'/storage/core-web/remote-assets/assets'))->files()));
    }

    public function test_a_container_the_site_will_not_show_clears_its_index(): void
    {
        $this->pull()->assertSuccessful();

        $this->forbidden = true;

        $this->pull()->expectsOutputToContain('you may not view it')->assertSuccessful();
        $this->assertSame([], (new RemoteIndex($this->dir.'/storage/core-web/remote-assets/assets'))->files());
    }

    public function test_assets_are_pulled_with_everything_else(): void
    {
        $this->artisan('content:pull', ['--no-interaction' => true])->expectsOutputToContain('Assets assets: 2 files indexed')->assertSuccessful();

        $this->assertSame(1, $this->exports);
    }

    public function test_without_content_sync_the_local_disk_stays_the_plain_folder(): void
    {
        config(['core-web.features.content_sync' => false]);
        config(['filesystems.disks' => array_diff_key(config('filesystems.disks'), ['assets' => true])]);
        MediaDisks::define($this->app);

        $this->assertSame('local', config('filesystems.disks.assets.driver'));
        $this->assertSame($this->dir.'/public/assets', config('filesystems.disks.assets.root'));
    }
}
