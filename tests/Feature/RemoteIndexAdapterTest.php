<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Media\RemoteIndex;
use Inly\CoreWeb\Media\RemoteIndexAdapter;
use Inly\CoreWeb\Tests\TestCase;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;

class RemoteIndexAdapterTest extends TestCase
{
    protected array $features = ['media'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/core-web-remote-index-'.uniqid();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function adapter(): RemoteIndexAdapter
    {
        return new RemoteIndexAdapter(new RemoteIndex($this->root), '/assets');
    }

    public function test_without_an_index_the_disk_is_empty(): void
    {
        $this->assertUnknown($this->adapter());
    }

    public function test_a_corrupt_index_lists_nothing_and_says_so(): void
    {
        File::ensureDirectoryExists($this->root);
        file_put_contents($this->root.'/assets.json', '{not json');
        file_put_contents($this->root.'/other.json', json_encode(['files' => 'not a list']));

        $this->assertUnknown($this->adapter());
        $this->assertSame(['assets' => false, 'other' => false], (new RemoteIndex($this->root))->status());
    }

    public function test_a_file_s_metadata_comes_from_its_meta(): void
    {
        (new RemoteIndex($this->root))->put('assets', 'https://remote.test', 'https://cdn.test/assets', [
            'a/hero.jpg' => ['url' => 'https://cdn.test/assets/a/hero.jpg', 'meta' => ['size' => 4, 'last_modified' => 1700000000, 'mime_type' => 'image/jpeg']],
        ]);
        $adapter = $this->adapter();

        $this->assertTrue($adapter->fileExists('a/hero.jpg'));
        $this->assertTrue($adapter->fileExists('a/.meta/hero.jpg.yaml'));
        $this->assertTrue($adapter->directoryExists('a'));
        $this->assertSame(4, $adapter->fileSize('a/hero.jpg')->fileSize());
        $this->assertSame(1700000000, $adapter->lastModified('a/hero.jpg')->lastModified());
        $this->assertSame('image/jpeg', $adapter->mimeType('a/hero.jpg')->mimeType());
        $this->assertSame(['assets' => true], (new RemoteIndex($this->root))->status());
        $this->assertUnknown($adapter, 'a/missing.jpg');
    }

    private function assertUnknown(RemoteIndexAdapter $adapter, string $path = 'hero.jpg'): void
    {
        $this->assertFalse($adapter->fileExists($path));

        if ($path === 'hero.jpg') {
            $this->assertFalse($adapter->directoryExists(''));
            $this->assertSame([], iterator_to_array($adapter->listContents('', true), false));
        }

        foreach (['mimeType', 'fileSize', 'lastModified', 'visibility'] as $method) {
            try {
                $adapter->{$method}($path);
                $this->fail("{$method}() of an unknown file must throw.");
            } catch (UnableToRetrieveMetadata) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(UnableToReadFile::class);
        $adapter->read($path);
    }
}
