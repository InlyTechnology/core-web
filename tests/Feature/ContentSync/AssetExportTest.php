<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Assets\Asset;
use Statamic\Assets\AssetContainer as FileContainer;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as ContainerContract;
use Statamic\Facades\AssetContainer;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class AssetExportTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['content_sync'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->root = sys_get_temp_dir().'/core-web-asset-export-'.uniqid();
        File::ensureDirectoryExists($this->root.'/photos/team/.meta');
        File::ensureDirectoryExists($this->root.'/secret');
        file_put_contents($this->root.'/photos/team/anna.jpg', 'jpeg bytes');
        file_put_contents($this->root.'/photos/team/.meta/anna.jpg.yaml', "data:\n  alt: 'Anna, smiling'\n  focus: 40-30-1\nsize: 10\nmime_type: image/jpeg\nwidth: 1200\nheight: 800\n");
        file_put_contents($this->root.'/photos/logo.svg', '<svg/>');
        file_put_contents($this->root.'/secret/plan.pdf', 'pdf');

        config(['filesystems.disks.photos' => ['driver' => 'local', 'root' => $this->root.'/photos', 'url' => 'https://cdn.example.com/photos']]);
        config(['filesystems.disks.secret' => ['driver' => 'local', 'root' => $this->root.'/secret', 'url' => '/secret']]);
        AssetContainer::make('photos')->disk('photos')->save();
        AssetContainer::make('secret')->disk('secret')->save();

        $this->roles([
            'photographer' => ['access cp', 'pull content', 'view photos assets'],
            'viewer' => ['access cp', 'view photos assets'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function export(string $token, array $query): TestResponse
    {
        return $this->withToken($token)->getJson('/!/core-web/sync/export?'.http_build_query(['type' => 'assets', ...$query]));
    }

    public function test_the_export_lists_each_file_with_its_url_and_meta_and_never_its_bytes(): void
    {
        $token = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['photographer']));

        $response = $this->export($token, ['handle' => 'photos'])->assertOk();

        $this->assertSame(['url' => 'https://cdn.example.com/photos'], $response->json('containers.photos'));
        $this->assertNull($response->json('next_cursor'));
        $this->assertSame(['logo.svg', 'team/anna.jpg'], array_column($response->json('data'), 'path'));

        $anna = $response->json('data.1');
        $this->assertSame('photos::team/anna.jpg', $anna['id']);
        $this->assertSame('https://cdn.example.com/photos/team/anna.jpg', $anna['url']);
        $this->assertSame(['alt' => 'Anna, smiling', 'focus' => '40-30-1'], $anna['meta']['data']);
        $this->assertSame(1200, $anna['meta']['width']);
        $this->assertArrayNotHasKey('contents', $anna);

        $this->assertNull($response->json('data.0.meta'), 'A file without .meta is sent without one, not measured.');
        $this->assertFileDoesNotExist($this->root.'/photos/.meta/logo.svg.yaml', 'The export never writes the live site.');
    }

    public function test_the_export_pages_through_a_container(): void
    {
        config(['core-web.content_sync.page_size' => 1]);
        $token = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['photographer']));

        $this->export($token, ['handle' => 'photos'])->assertJsonPath('data.0.path', 'logo.svg')->assertJsonPath('next_cursor', '1');
        $this->export($token, ['handle' => 'photos', 'cursor' => '1'])->assertJsonPath('data.0.path', 'team/anna.jpg')->assertJsonPath('next_cursor', null);
    }

    public function test_only_containers_the_user_may_view_are_exported(): void
    {
        $token = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['photographer']));

        $this->assertSame(['photos'], array_unique(array_column($this->export($token, [])->assertOk()->json('data'), 'container')));
        $this->export($token, ['handle' => 'secret'])->assertForbidden()->assertJsonPath('error', 'forbidden');
        $this->export($token, ['handle' => 'nowhere'])->assertNotFound();

        $boss = $this->tokenFor($this->cmsUser('boss@example.com', super: true));
        $this->assertSame(['photos', 'secret'], array_values(array_unique(array_column($this->export($boss, [])->json('data'), 'container'))));
    }

    public function test_a_user_who_may_view_the_container_but_not_pull_content_is_refused(): void
    {
        $token = $this->tokenFor($this->cmsUser('vic@example.com', roles: ['viewer']));

        $this->export($token, ['handle' => 'photos'])->assertUnauthorized()->assertJsonPath('error', 'sync_token_refused')->assertJsonMissingPath('data');
    }

    public function test_files_in_nested_folders_are_exported_with_their_meta(): void
    {
        File::ensureDirectoryExists($this->root.'/photos/team/board/.meta');
        file_put_contents($this->root.'/photos/team/board/bo.jpg', 'jpeg bytes');
        file_put_contents($this->root.'/photos/team/board/.meta/bo.jpg.yaml', "data:\n  alt: Bo\nwidth: 640\n");
        $token = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['photographer']));

        $data = $this->export($token, ['handle' => 'photos'])->assertOk()->json('data');

        $this->assertSame(['logo.svg', 'team/anna.jpg', 'team/board/bo.jpg'], array_column($data, 'path'));
        $this->assertSame(['alt' => 'Bo'], $data[2]['meta']['data']);
        $this->assertSame(640, $data[2]['meta']['width']);
        $this->assertSame('Anna, smiling', $data[1]['meta']['data']['alt']);
    }

    public function test_meta_statamic_has_cached_is_sent_when_the_file_has_no_meta_file(): void
    {
        $asset = AssetContainer::find('photos')->makeAsset('logo.svg');
        $asset->cacheStore()->forever($asset->metaCacheKey(), ['data' => ['alt' => 'Cached logo'], 'width' => 64]);
        $token = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['photographer']));

        $logo = $this->export($token, ['handle' => 'photos'])->assertOk()->json('data.0');

        $this->assertSame(['data' => ['alt' => 'Cached logo'], 'width' => 64], $logo['meta']);
        $this->assertFileDoesNotExist($this->root.'/photos/.meta/logo.svg.yaml');
    }

    public function test_a_page_boundary_between_containers_neither_repeats_nor_drops_a_file(): void
    {
        config(['core-web.content_sync.page_size' => 2]);
        $token = $this->tokenFor($this->cmsUser('boss@example.com', super: true));

        $this->export($token, [])->assertJsonPath('next_cursor', '2');
        $this->export($token, ['cursor' => '2'])->assertJsonPath('data.0.id', 'secret::plan.pdf')->assertJsonPath('next_cursor', null);
    }

    public function test_the_export_lists_through_the_asset_query_and_reads_meta_the_asset_stores(): void
    {
        // The Eloquent driver's container lists only root files and gives asset paths for meta files,
        // and its assets carry their meta from the database row, with no `.meta` file on the disk.
        $this->app->bind(ContainerContract::class, EloquentLikeContainer::class);
        $this->app->bind(AssetContract::class, EloquentLikeAsset::class);
        File::delete($this->root.'/photos/team/.meta/anna.jpg.yaml');
        file_put_contents($this->root.'/photos/notes.yaml', "data:\n  alt: The file's own bytes\n");
        EloquentLikeAsset::$rows = ['notes.yaml' => [], 'team/anna.jpg' => ['data' => ['alt' => 'From the row', 'focus' => '50-50-1'], 'width' => 1200]];
        AssetContainer::make('photos')->disk('photos')->save();
        $token = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['photographer']));

        $this->assertSame(['logo.svg', 'notes.yaml'], AssetContainer::find('photos')->files()->sort()->values()->all());

        $data = $this->export($token, ['handle' => 'photos'])->assertOk()->json('data');

        $this->assertSame(['logo.svg', 'notes.yaml', 'team/anna.jpg'], array_column($data, 'path'));
        $this->assertSame(['alt' => 'From the row', 'focus' => '50-50-1'], $data[2]['meta']['data']);
        $this->assertNull($data[0]['meta'], 'A row without meta is sent without one.');
        $this->assertNull($data[1]['meta'], 'A row with empty meta is sent without one, never by reading the file.');
    }
}

/** Lists files the way statamic/eloquent-driver 5.12 does: root files only, and asset paths as meta files. */
class EloquentLikeContainer extends FileContainer
{
    public function files($folder = '/', $recursive = false): Collection
    {
        return parent::files()->reject(fn (string $path) => str_contains($path, '/'))->values();
    }

    public function metaFiles($folder = '/', $recursive = false): Collection
    {
        return parent::files();
    }
}

/** An asset whose meta comes from its database row and whose meta path is the file itself, as on the Eloquent driver. */
class EloquentLikeAsset extends Asset
{
    /** @var array<string, array<string, mixed>> */
    public static array $rows = [];

    public function getRawMeta()
    {
        return self::$rows[$this->path()] ?? null;
    }

    public function metaPath()
    {
        return $this->path();
    }
}
