<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Media\LayeredAdapter;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Media\ProductionFileLocked;
use Inly\CoreWeb\Tests\TestCase;
use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

class LayeredAdapterTest extends TestCase
{
    private string $root;

    private LayeredAdapter $adapter;

    private Repository $cache;

    protected array $features = ['media'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/core-web-layered-'.uniqid();
        mkdir($this->root.'/lower/cars/.meta', 0777, true);
        file_put_contents($this->root.'/lower/cars/a.jpg', 'production a');
        file_put_contents($this->root.'/lower/cars/.meta/a.jpg.yaml', "data:\n  alt: Production\n");

        $this->cache = new Repository(new ArrayStore);
        $this->adapter = new LayeredAdapter(
            new LocalFilesystemAdapter($this->root.'/upper'),
            new LocalFilesystemAdapter($this->root.'/lower'),
            fn (string $path) => '/layer/'.$path,
            fn (string $path) => 'https://cdn.test/'.$path,
            $this->cache,
            'index',
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    public function test_reads_fall_through_to_the_bucket_and_writes_land_in_the_layer(): void
    {
        $this->assertSame('production a', $this->adapter->read('cars/a.jpg'));

        $this->adapter->write('cars/.meta/a.jpg.yaml', 'alt: Staging', new Config);

        $this->assertSame('alt: Staging', $this->adapter->read('cars/.meta/a.jpg.yaml'), 'an edit to a production file\'s meta reads back');
        $this->assertSame("data:\n  alt: Production\n", file_get_contents($this->root.'/lower/cars/.meta/a.jpg.yaml'), 'production\'s meta is untouched');
    }

    public function test_production_files_cannot_be_deleted_or_moved(): void
    {
        $this->expectException(ProductionFileLocked::class);
        $this->adapter->delete('cars/a.jpg');
    }

    public function test_production_files_cannot_be_moved_but_layer_files_can(): void
    {
        $this->adapter->write('cars/b.jpg', 'b', new Config);
        $this->adapter->move('cars/b.jpg', 'cars/c.jpg', new Config);
        $this->assertTrue($this->adapter->fileExists('cars/c.jpg'));

        $this->expectException(ProductionFileLocked::class);
        $this->adapter->move('cars/a.jpg', 'cars/d.jpg', new Config);
    }

    public function test_a_folder_holding_production_files_cannot_be_deleted(): void
    {
        $this->expectException(ProductionFileLocked::class);
        $this->adapter->deleteDirectory('cars');
    }

    public function test_a_copy_of_a_production_file_lands_in_the_layer(): void
    {
        $this->adapter->copy('cars/a.jpg', 'cars/copy.jpg', new Config);

        $this->assertFileExists($this->root.'/upper/cars/copy.jpg');
        $this->assertFileDoesNotExist($this->root.'/lower/cars/copy.jpg');
    }

    public function test_listing_shows_both_once(): void
    {
        $this->adapter->write('cars/a.jpg', 'staging a', new Config);
        $this->adapter->write('cars/b.jpg', 'b', new Config);

        $files = collect($this->adapter->listContents('cars', false))
            ->filter(fn (StorageAttributes $item) => $item->isFile())
            ->map(fn (StorageAttributes $item) => $item->path())
            ->sort()->values()->all();

        $this->assertSame(['cars/a.jpg', 'cars/b.jpg'], $files);
    }

    public function test_urls_follow_the_file_and_the_index_follows_writes(): void
    {
        $this->assertSame('https://cdn.test/cars/a.jpg', $this->adapter->getUrl('cars/a.jpg'));

        $this->adapter->write('cars/new.jpg', 'new', new Config);
        $this->assertSame('/layer/cars/new.jpg', $this->adapter->getUrl('cars/new.jpg'));

        $this->adapter->delete('cars/new.jpg');
        $this->assertFalse($this->adapter->inLayer('cars/new.jpg'));
    }

    public function test_a_failed_layer_listing_is_not_kept(): void
    {
        $failing = new LayeredAdapter(
            new class($this->root.'/upper') extends LocalFilesystemAdapter
            {
                public bool $fail = true;

                public function listContents(string $path, bool $deep): iterable
                {
                    if ($this->fail) {
                        throw new \RuntimeException('bucket down');
                    }

                    return parent::listContents($path, $deep);
                }
            },
            new LocalFilesystemAdapter($this->root.'/lower'),
            fn (string $path) => '/layer/'.$path,
            fn (string $path) => 'https://cdn.test/'.$path,
            $this->cache,
            'failing',
        );
        file_put_contents($this->root.'/upper/new.jpg', 'new');

        $this->assertFalse($failing->inLayer('new.jpg'));
        $this->assertNull($this->cache->get('failing'));
    }

    public function test_reads_find_a_layer_file_the_index_has_not_seen(): void
    {
        $this->assertFalse($this->adapter->inLayer('cars/other.jpg'));
        @mkdir($this->root.'/upper/cars', 0777, true);
        file_put_contents($this->root.'/upper/cars/other.jpg', 'from another server');

        $this->assertSame('from another server', $this->adapter->read('cars/other.jpg'));
        $this->assertSame(19, $this->adapter->fileSize('cars/other.jpg')->fileSize());
    }

    public function test_statamic_cannot_delete_or_rename_a_production_asset_through_the_layer(): void
    {
        config(['filesystems.disks.layered' => [
            'driver' => MediaDisks::LAYERED_DRIVER,
            'name' => 'layered',
            'upper' => ['driver' => 'local', 'root' => $this->root.'/upper', 'url' => '/layer'],
            'lower' => ['driver' => 'local', 'root' => $this->root.'/lower', 'url' => 'https://cdn.test'],
            'url' => 'https://cdn.test',
            'throw' => false,
        ]]);
        $asset = Asset::make()->container(AssetContainer::make('layered')->disk('layered'))->path('cars/a.jpg');

        foreach (['delete' => fn () => $asset->delete(), 'rename' => fn () => $asset->rename('b')] as $action => $attempt) {
            try {
                $attempt();
                $this->fail("{$action} went through");
            } catch (ProductionFileLocked) {
                $this->assertSame('cars/a.jpg', $asset->path(), $action);
                $this->assertTrue(Storage::disk('layered')->exists('cars/a.jpg'), $action);
            }
        }
    }
}
