<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Eloquent\Assets\Asset as EloquentAsset;
use Statamic\Eloquent\Assets\AssetModel;
use Statamic\Eloquent\ServiceProvider;
use Statamic\Facades\AssetContainer;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** media:push into a container whose meta the eloquent driver keeps in the database, as on Laravel Cloud. */
class MediaPushEloquentTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['content_sync'];

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $scratch;

    private string $from;

    private string $disk;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ServiceProvider::class];
    }

    /** Before the providers register, which is when the driver reads which repositories to swap. */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('statamic.eloquent-driver.assets', ['driver' => 'eloquent', 'model' => AssetModel::class, 'asset' => EloquentAsset::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require dirname((new \ReflectionClass(AssetModel::class))->getFileName(), 3).'/database/migrations/2024_03_07_100000_create_asset_table.php')->up();

        Storage::fake('local');
        $this->scratch = sys_get_temp_dir().'/core-web-media-push-eloquent-'.uniqid();
        $this->from = $this->scratch.'/old-site/assets';
        $this->disk = $this->scratch.'/photos';
        File::ensureDirectoryExists($this->from);
        File::ensureDirectoryExists($this->disk);

        config([
            'core-web.content_sync.tokens.disk' => 'local',
            'core-web.content_sync.remote_url' => 'https://remote.test',
            'filesystems.disks.photos' => ['driver' => 'local', 'root' => $this->disk, 'url' => '/photos'],
        ]);
        AssetContainer::make('photos')->disk('photos')->save();
        Storage::disk('photos')->buildTemporaryUploadUrlsUsing(fn (string $path, $expiration, array $options) => [
            'url' => 'https://bucket.test/'.$path, 'headers' => ['Content-MD5' => [$options['ContentMD5']]],
        ]);

        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('boss@example.com', super: true)), ['name' => 'Boss', 'email' => 'boss@example.com'], now()->addDays(30)->toIso8601String());

        Http::fake(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_HOST) === 'bucket.test') {
                $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');
                File::ensureDirectoryExists(dirname($this->disk.'/'.$path));
                file_put_contents($this->disk.'/'.$path, $request->body());

                return Http::response('', 200);
            }

            $response = $this->call($request->method(), $request->url(), [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => $request->header('Authorization')[0],
                'CONTENT_TYPE' => 'application/json',
            ], $request->body());

            return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        RemoteSite::forget();

        parent::tearDown();
    }

    private function local(string $path, string $alt): void
    {
        File::ensureDirectoryExists(dirname($this->from.'/'.$path).'/.meta');
        file_put_contents($this->from.'/'.$path, base64_decode(self::PNG));
        file_put_contents(dirname($this->from.'/'.$path).'/.meta/'.basename($path).'.yaml', "data:\n  alt: '{$alt}'\n");
    }

    public function test_the_alt_text_is_saved_in_the_database_and_a_second_run_finds_the_file(): void
    {
        $this->assertInstanceOf(EloquentAsset::class, AssetContainer::find('photos')->makeAsset('x.png'));
        $this->local('team/anna.png', 'Anna');

        $this->artisan('media:push', ['from' => $this->from, '--container' => 'photos'])->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertSame('Anna', AssetModel::query()->where('path', 'team/anna.png')->first()->meta['data']['alt']);
        $this->assertFileDoesNotExist($this->disk.'/team/.meta/anna.png.yaml');
        $this->artisan('media:push', ['from' => $this->from, '--container' => 'photos'])->expectsOutputToContain('1 already there')->assertSuccessful();
    }

    public function test_a_file_without_a_row_is_finished_without_sending_it(): void
    {
        $this->local('anna.png', 'Anna');
        file_put_contents($this->disk.'/anna.png', base64_decode(self::PNG));

        $this->artisan('media:push', ['from' => $this->from, '--container' => 'photos'])->expectsOutputToContain('1 to finish')->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertSame([], Http::recorded(fn (Request $request) => $request->method() === 'PUT')->all());
        $this->assertSame('Anna', AssetModel::query()->where('path', 'anna.png')->first()->meta['data']['alt']);
    }
}
