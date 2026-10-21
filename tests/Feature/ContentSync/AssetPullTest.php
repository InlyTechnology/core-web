<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Inly\CoreWeb\ContentSync\AssetPull;
use Inly\CoreWeb\ContentSync\LocalContent;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Media\MediaSource;
use Inly\CoreWeb\Media\ProductionFileLocked;
use Inly\CoreWeb\Media\RemoteIndex;
use Inly\CoreWeb\Media\RemoteIndexAdapter;
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

    /** @var array{0: int, 1: mixed}|null an answer the export gives in place of the index */
    private ?array $answer = null;

    private ?int $pageSize = null;

    private ?string $failAtCursor = null;

    private bool $endless = false;

    /** @var array<string, mixed> the CDN's answer for a URL, in place of the file's bytes */
    private array $cdn = [];

    /** @var array<string, string|null> */
    private array $containerUrls = ['assets' => 'https://cdn.test/site/assets'];

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
            if (isset($this->cdn[$request->url()])) {
                return $this->cdn[$request->url()];
            }

            if (str_starts_with($request->url(), 'https://cdn.test/')) {
                return Http::response('live bytes of '.basename(parse_url($request->url(), PHP_URL_PATH)));
            }

            if (str_contains($request->url(), '/!/core-web/sync/export') && $request['type'] === 'assets') {
                $this->exports++;

                if ($this->answer !== null) {
                    return Http::response($this->answer[1], $this->answer[0]);
                }

                $cursor = (int) $request['cursor'];

                if ($this->failAtCursor === (string) $cursor) {
                    return Http::response(['error' => 'server_error'], 500);
                }

                $data = [];

                foreach ($this->live as $path => $file) {
                    $data[] = ['type' => 'assets', 'id' => 'assets::'.$path, 'container' => 'assets', 'path' => $path, ...$file];
                }

                $size = $this->pageSize ?? max(1, count($data));
                $next = $this->endless || $cursor + $size < count($data) ? (string) ($cursor + $size) : null;

                return Http::response([
                    'data' => array_slice($data, $cursor, $size),
                    'next_cursor' => $next,
                    'containers' => array_map(fn (?string $url) => ['url' => $url], $this->containerUrls),
                ]);
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
        $this->assertSame(MediaDisks::LAYERED_DRIVER, $disk['lower']['driver']);
        $this->assertSame($this->dir.'/public/assets', $disk['lower']['upper']['root']);
        $this->assertSame(MediaDisks::REMOTE_INDEX_DRIVER, $disk['lower']['lower']['driver']);
        $this->assertSame('/!/core-web/media/assets', $disk['url']);
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
        $this->assertSame(0, app(LocalContent::class)->refreshes, 'An assets-only pull rebuilds no Stache.');
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
        $this->get(collect($assets)->firstWhere('basename', 'hero.jpg')['url'])->assertRedirect('https://cdn.test/site/assets/hero.jpg');
    }

    public function test_the_control_panel_s_thumbnail_of_a_live_only_file_is_the_cdn_s_rendition(): void
    {
        config(['core-web.media.cdn.origins' => ['https://cdn.test']]);
        File::ensureDirectoryExists($this->dir.'/public/assets');
        file_put_contents($this->dir.'/public/assets/committed.jpg', 'committed');
        $this->pull()->assertSuccessful();
        Storage::disk('assets')->put('upload.jpg', 'mine');
        $this->fresh();

        $assets = collect($this->browse('assets'))->keyBy('basename');

        $this->assertSame('https://cdn.test/site/assets/hero.jpg?width=400&height=400&fit=contain&format=auto', $assets['hero.jpg']['thumbnail']);
        $this->assertSame('https://cdn.test/site/assets/hero.jpg', $assets['hero.jpg']['preview']);
        $this->assertStringContainsString('/thumbnails/', $assets['upload.jpg']['thumbnail'], 'A file in the local layer keeps Glide.');
        $this->assertStringContainsString('/thumbnails/', $assets['committed.jpg']['thumbnail'], 'A committed file keeps Glide.');

        $show = $this->getJson(cp_route('assets.show', base64_encode('assets::hero.jpg')))->assertOk()->json('data');
        $this->assertSame($assets['hero.jpg']['thumbnail'], $show['thumbnail']);

        $fieldtype = $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class])->postJson(cp_route('assets.index').'-fieldtype', ['assets' => ['assets::hero.jpg']])->assertOk()->json();
        $this->assertSame($assets['hero.jpg']['thumbnail'], collect($fieldtype)->firstWhere('id', 'assets::hero.jpg')['thumbnail']);

        $this->assertNull(MediaDisks::indexOnlyUrl(Asset::make()->container('assets')->path('gone.jpg')), 'A row the index no longer holds keeps Glide.');
    }

    public function test_the_control_panel_s_thumbnails_of_live_only_svgs_videos_and_other_files(): void
    {
        config(['core-web.media.cdn.origins' => ['https://cdn.test']]);
        $this->live = [
            'logo.svg' => ['url' => 'https://cdn.test/site/assets/logo.svg', 'meta' => null],
            'clip.mp4' => ['url' => 'https://cdn.test/site/assets/clip.mp4', 'meta' => null],
            'notes.pdf' => ['url' => 'https://cdn.test/site/assets/notes.pdf', 'meta' => null],
        ];
        $this->pull()->assertSuccessful();
        $this->fresh();

        $assets = collect($this->browse('assets'))->keyBy('basename');

        $this->assertSame('https://cdn.test/site/assets/logo.svg', $assets['logo.svg']['thumbnail']);
        $this->assertSame('https://cdn.test/site/assets/clip.mp4?mode=frame&time=0&width=400&height=400&fit=cover', $assets['clip.mp4']['thumbnail']);
        $this->assertNull($assets['notes.pdf']['thumbnail']);

        config(['core-web.media.cdn.origins' => []]);
        $this->assertNull(collect($this->browse('assets'))->firstWhere('basename', 'clip.mp4')['thumbnail'], 'Without a CDN frame no thumbnail, never Glide fetching the whole video.');
    }

    public function test_without_a_cdn_the_thumbnail_of_a_live_only_file_is_its_public_url(): void
    {
        config(['core-web.media.cdn.origins' => []]);
        $this->pull()->assertSuccessful();
        $this->fresh();

        $hero = collect($this->browse('assets'))->firstWhere('basename', 'hero.jpg');

        $this->assertSame('https://cdn.test/site/assets/hero.jpg', $hero['thumbnail']);
    }

    public function test_a_container_on_a_disk_without_the_index_keeps_glide_thumbnails(): void
    {
        config(['core-web.media.cdn.origins' => ['https://cdn.test']]);
        config(['filesystems.disks.plain' => ['driver' => 'local', 'root' => $this->dir.'/public/plain', 'url' => '/plain', 'visibility' => 'public']]);
        File::ensureDirectoryExists($this->dir.'/public/plain');
        file_put_contents($this->dir.'/public/plain/hero.jpg', 'plain');
        AssetContainer::make('plain')->disk('plain')->save();
        $this->pull()->assertSuccessful();
        $this->fresh();

        $hero = collect($this->browse('plain'))->firstWhere('basename', 'hero.jpg');

        $this->assertStringContainsString('/thumbnails/', $hero['thumbnail']);
        $this->assertSame('/plain/hero.jpg', $hero['preview']);
    }

    /** @return list<array<string, mixed>> */
    private function browse(string $container): array
    {
        $super = User::find('boss') ?? tap(User::make()->id('boss')->email('boss@example.com')->makeSuper())->save();

        return $this->actingAs($super)->getJson(cp_route('assets.browse.index').'/folders/'.$container)->assertOk()->json('data');
    }

    public function test_a_remote_file_s_disk_url_is_its_public_url(): void
    {
        $this->pull()->assertSuccessful();

        $this->assertSame('https://cdn.test/site/assets/team/anna.jpg', Storage::disk('assets')->url('team/anna.jpg'));
        $this->assertSame('/!/core-web/media/assets', AssetContainer::find('assets')->url());
    }

    public function test_an_asset_s_url_finds_its_file_in_whichever_layer_holds_it(): void
    {
        File::ensureDirectoryExists($this->dir.'/public/assets');
        file_put_contents($this->dir.'/public/assets/logo.svg', '<svg/>');
        $this->pull()->assertSuccessful();
        Storage::disk('assets')->put('my upload.jpg', 'mine');
        $this->fresh();

        $live = Asset::find('assets::team/anna.jpg');
        $upload = Asset::find('assets::my upload.jpg');

        $this->assertSame('/!/core-web/media/assets/team/anna.jpg', $live->url());
        $this->get($live->url())->assertRedirect('https://cdn.test/site/assets/team/anna.jpg');
        $this->get($upload->url())->assertRedirect('/media-layer/assets/my%20upload.jpg');
        $this->get(Asset::find('assets::logo.svg')->url())->assertRedirect('/assets/logo.svg');
        $this->get('/!/core-web/media/assets/nowhere.jpg')->assertNotFound();
        $this->get('/!/core-web/media/elsewhere/hero.jpg')->assertNotFound();

        $this->assertSame('https://cdn.test/site/assets/team/anna.jpg', MediaSource::from($live)->url, 'The components render the file\'s own URL, which the CDN transforms.');
        $this->assertSame('/media-layer/assets/my%20upload.jpg', MediaSource::from($upload)->url);
    }

    public function test_the_committed_folder_stays_readable_beneath_the_layer_and_above_the_live_files(): void
    {
        File::ensureDirectoryExists($this->dir.'/public/assets/team');
        file_put_contents($this->dir.'/public/assets/logo.svg', '<svg/>');
        file_put_contents($this->dir.'/public/assets/team/anna.jpg', 'committed anna');
        $this->fresh();

        $this->assertSame(['logo.svg', 'team/anna.jpg'], AssetContainer::find('assets')->files()->sort()->values()->all(), 'Before any pull, the committed files.');

        $this->pull()->assertSuccessful();
        $this->fresh();

        $this->assertSame(['hero.jpg', 'logo.svg', 'team/anna.jpg'], AssetContainer::find('assets')->files()->sort()->values()->all());
        $this->assertSame('committed anna', Storage::disk('assets')->get('team/anna.jpg'));
        $this->assertSame('/assets/team/anna.jpg', Storage::disk('assets')->url('team/anna.jpg'));

        try {
            Storage::disk('assets')->delete('logo.svg');
            $this->fail('Deleting a committed file must throw.');
        } catch (ProductionFileLocked) {
            $this->assertFileExists($this->dir.'/public/assets/logo.svg');
        }

        Storage::disk('assets')->put('logo.svg', '<svg>mine</svg>');
        $this->assertSame('<svg>mine</svg>', Storage::disk('assets')->get('logo.svg'));
        $this->assertSame('<svg/>', file_get_contents($this->dir.'/public/assets/logo.svg'));
    }

    public function test_naming_assets_leaves_out_containers_on_the_app_s_own_disks(): void
    {
        config(['filesystems.disks.favicons' => ['driver' => 'local', 'root' => $this->dir.'/public/favicons']]);
        AssetContainer::make('favicons')->disk('favicons')->save();

        $this->pull()->doesntExpectOutputToContain('favicons')->assertSuccessful();
    }

    public function test_a_checkout_with_the_bucket_s_key_says_why_no_assets_came(): void
    {
        config(['core-web.media.bucket.key' => 'key', 'core-web.media.bucket.secret' => 'secret']);
        config(['filesystems.disks' => array_diff_key(config('filesystems.disks'), ['assets' => true])]);
        MediaDisks::define($this->app);
        $this->fresh();

        $this->pull(['--only' => null])->expectsOutputToContain('this checkout has the media bucket\'s key')->assertSuccessful();
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
        foreach ([[403, ['error' => 'forbidden']], [404, ['error' => 'not_found']]] as $answer) {
            $this->pull()->assertSuccessful();
            $this->answer = $answer;

            $this->pull()->expectsOutputToContain('you may not view it')->assertSuccessful();
            $this->assertSame([], $this->index()->files());
            $this->answer = null;
        }
    }

    public function test_another_403_or_404_fails_the_pull_and_keeps_the_index(): void
    {
        $this->pull()->assertSuccessful();

        foreach ([[403, '<h1>Forbidden by the firewall</h1>'], [404, ['message' => 'Not Found']], [403, ['error' => 'something_else']]] as $answer) {
            $this->answer = $answer;

            $this->pull()->expectsOutputToContain("answered HTTP {$answer[0]} for assets")->assertFailed();
            $this->assertSame(['hero.jpg', 'team/anna.jpg'], array_keys($this->index()->files()));
        }
    }

    public function test_a_site_that_exports_no_assets_keeps_the_index_and_still_pulls_content(): void
    {
        $this->pull()->assertSuccessful();
        $this->answer = [422, ['error' => 'invalid_request', 'message' => 'Unknown type: use entries, terms, globals, trees.']];

        $this->artisan('content:pull', ['--no-interaction' => true])
            ->expectsOutputToContain('does not export assets')
            ->expectsOutputToContain('Already up to date.')
            ->assertSuccessful();

        $this->assertSame(['hero.jpg', 'team/anna.jpg'], array_keys($this->index()->files()));
    }

    public function test_the_pull_pages_through_a_container(): void
    {
        $this->pageSize = 1;

        $this->pull()->expectsOutputToContain('Assets assets: 2 files indexed')->assertSuccessful();

        $this->assertSame(2, $this->exports);
        $this->assertSame(['hero.jpg', 'team/anna.jpg'], array_keys($this->index()->files()));
    }

    public function test_the_fetch_reports_the_files_listed_after_each_page(): void
    {
        $this->pageSize = 1;
        $counts = [];

        app(AssetPull::class)->fetch(RemoteSite::fromStorage(), AssetContainer::find('assets'), function (int $files) use (&$counts): void {
            $counts[] = $files;
        });

        $this->assertSame([1, 2], $counts);
    }

    public function test_a_failing_later_page_keeps_the_old_index(): void
    {
        $this->pull()->assertSuccessful();
        $this->live['blog/post.png'] = ['url' => 'https://cdn.test/site/assets/blog/post.png', 'meta' => null];
        $this->pageSize = 1;
        $this->failAtCursor = '1';

        $this->pull()->expectsOutputToContain('answered HTTP 500 for assets')->assertFailed();

        $this->assertSame(['hero.jpg', 'team/anna.jpg'], array_keys($this->index()->files()));
    }

    public function test_a_site_that_never_stops_paging_is_cut_off(): void
    {
        $this->app->instance(AssetPull::class, new AssetPull(maxPages: 3));
        $this->pageSize = 1;
        $this->endless = true;

        $this->pull()->expectsOutputToContain('kept paging assets past 3 pages')->assertFailed();

        $this->assertSame(3, $this->exports);
        $this->assertSame([], $this->index()->files());
    }

    public function test_urls_off_the_site_s_own_hosts_are_left_out(): void
    {
        $this->live = [
            'hero.jpg' => ['url' => 'https://cdn.test/site/assets/hero.jpg', 'meta' => null],
            'same-site.jpg' => ['url' => 'https://remote.test/assets/same-site.jpg', 'meta' => null],
            'private.pdf' => ['url' => null, 'meta' => null],
            'passwd.jpg' => ['url' => 'file:///etc/passwd', 'meta' => null],
            'script.jpg' => ['url' => 'javascript:alert(1)', 'meta' => null],
            'foreign.jpg' => ['url' => 'https://evil.test/x.jpg', 'meta' => null],
            'metadata.jpg' => ['url' => 'http://169.254.169.254/latest/meta-data', 'meta' => null],
        ];

        $this->pull()->expectsOutputToContain('4 files left out')->assertSuccessful();

        $this->assertSame(['hero.jpg', 'private.pdf', 'same-site.jpg'], array_keys($this->index()->files()));
    }

    public function test_a_url_off_the_index_s_hosts_is_never_fetched(): void
    {
        $this->pull()->assertSuccessful();
        $file = $this->dir.'/storage/core-web/remote-assets/assets/assets.json';
        $index = json_decode(file_get_contents($file), true);
        $index['files']['hero.jpg']['url'] = 'https://evil.test/hero.jpg';
        file_put_contents($file, json_encode($index));
        touch($file, time() + 5);
        $this->fresh();

        $this->assertNull($this->index()->files()['hero.jpg']['url']);
        $this->assertNull(Storage::disk('assets')->readStream('hero.jpg'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.test'));
    }

    public function test_a_redirecting_file_is_not_followed(): void
    {
        $this->live['moved.jpg'] = ['url' => 'https://cdn.test/site/assets/moved.jpg', 'meta' => null];
        $this->pull()->assertSuccessful();

        $this->cdn['https://cdn.test/site/assets/moved.jpg'] = Http::response('', 302, ['Location' => 'http://169.254.169.254/']);

        $this->assertNull(Storage::disk('assets')->readStream('moved.jpg'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_read_refuses_a_file_too_large_to_hold_while_a_stream_reads_it(): void
    {
        $this->pull()->assertSuccessful();
        $this->cdn['https://cdn.test/site/assets/hero.jpg'] = Http::response('big', 200, ['Content-Length' => (string) (RemoteIndexAdapter::MAX_READ_BYTES + 1)]);

        $this->assertNull(Storage::disk('assets')->get('hero.jpg'));
        $this->assertSame('big', stream_get_contents(Storage::disk('assets')->readStream('hero.jpg')));
    }

    public function test_oversized_meta_keeps_only_statamic_s_own_keys(): void
    {
        $this->live['hero.jpg']['meta']['notes'] = str_repeat('x', AssetPull::MAX_META_BYTES);

        $this->pull()->assertSuccessful();
        $meta = $this->index()->files()['hero.jpg']['meta'];

        $this->assertArrayNotHasKey('notes', $meta);
        $this->assertSame(1600, $meta['width']);
        $this->assertSame('From the live site', $meta['data']['alt']);

        $this->live['hero.jpg']['meta']['data']['alt'] = str_repeat('y', AssetPull::MAX_META_BYTES);
        $this->pull()->assertSuccessful();

        $this->assertSame(['size', 'mime_type', 'width', 'height', 'last_modified'], array_keys($this->index()->files()['hero.jpg']['meta']));
    }

    public function test_a_container_no_longer_on_the_disk_loses_its_index(): void
    {
        File::ensureDirectoryExists($this->dir.'/storage/core-web/remote-assets/assets');
        file_put_contents($this->dir.'/storage/core-web/remote-assets/assets/renamed.json', json_encode(['url' => 'https://cdn.test/old', 'files' => ['old.jpg' => ['url' => 'https://cdn.test/old/old.jpg', 'meta' => null]]]));

        $this->pull()->assertSuccessful();

        $this->assertFileDoesNotExist($this->dir.'/storage/core-web/remote-assets/assets/renamed.json');
        $this->assertArrayNotHasKey('old.jpg', $this->index()->files());
    }

    public function test_media_check_reports_an_unreadable_index(): void
    {
        $this->artisan('media:check')->expectsOutputToContain('No asset index pulled yet')->assertSuccessful();

        $this->pull()->assertSuccessful();
        $this->artisan('media:check')->expectsOutputToContain('the index of [assets] reads')->assertSuccessful();

        file_put_contents($this->dir.'/storage/core-web/remote-assets/assets/assets.json', '{not json');
        $this->artisan('media:check')->expectsOutputToContain('run content:pull --only=assets:assets')->assertFailed();
    }

    private function index(): RemoteIndex
    {
        return new RemoteIndex($this->dir.'/storage/core-web/remote-assets/assets');
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
