<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inly\CoreWeb\Tests\TestCase;
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

        $this->roles(['photographer' => ['access cp', 'pull content', 'view photos assets']]);
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
}
