<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Events\AssetSaved;
use Statamic\Facades\AssetContainer;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** Both halves in one app: the checkout's media:push is answered by this app's own sync routes, and a fake bucket takes the signed uploads. */
class MediaPushTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['content_sync'];

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $scratch;

    private string $from;

    private string $disk;

    /** What the fake bucket answers a signed upload with, by path; 200 for any other. */
    private array $bucketAnswers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->scratch = sys_get_temp_dir().'/core-web-media-push-'.uniqid();
        $this->from = $this->scratch.'/old-site/assets';
        $this->disk = $this->scratch.'/photos';
        File::ensureDirectoryExists($this->from);
        File::ensureDirectoryExists($this->disk);

        config([
            'core-web.content_sync.tokens.disk' => 'local',
            'core-web.content_sync.remote_url' => 'https://remote.test',
            'filesystems.disks.photos' => ['driver' => 'local', 'root' => $this->disk, 'url' => '/photos', 'options' => ['CacheControl' => 'public, max-age=31536000']],
        ]);
        AssetContainer::make('photos')->disk('photos')->save();

        $this->roles(['puller' => ['access cp', 'pull content']]);

        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('boss@example.com', super: true)), ['name' => 'Boss', 'email' => 'boss@example.com'], now()->addDays(30)->toIso8601String());
        $this->routeToThisAppAndTheBucket();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        RemoteSite::forget();

        parent::tearDown();
    }

    /** The photos disk signs uploads as a bucket does, to addresses on bucket.test. */
    private function signUploads(): void
    {
        Storage::disk('photos')->buildTemporaryUploadUrlsUsing(fn (string $path, $expiration, array $options) => [
            'url' => 'https://bucket.test/'.$path.'?X-Amz-Signature=abc',
            'headers' => ['Host' => ['bucket.test'], 'Content-Type' => [$options['ContentType']], 'Cache-Control' => [$options['CacheControl'] ?? '']],
        ]);
    }

    private function routeToThisAppAndTheBucket(): void
    {
        Http::fake(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_HOST) === 'bucket.test') {
                $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');
                [$status, $body] = $this->bucketAnswers[$path] ?? [200, ''];

                if ($status === 200) {
                    File::ensureDirectoryExists(dirname($this->disk.'/'.$path));
                    file_put_contents($this->disk.'/'.$path, $request->body());
                }

                return Http::response($body, $status);
            }

            $server = ['HTTP_ACCEPT' => 'application/json'];

            if ($request->hasHeader('Authorization')) {
                $server['HTTP_AUTHORIZATION'] = $request->header('Authorization')[0];
            }

            if ($request->isMultipart()) {
                $fields = [];
                $files = [];

                foreach ($request->data() as $part) {
                    isset($part['filename'])
                        ? $files[$part['name']] = UploadedFile::fake()->createWithContent($part['filename'], (string) $part['contents'])
                        : $fields[$part['name']] = (string) $part['contents'];
                }

                $response = $this->call('POST', $request->url(), $fields, [], $files, $server);
            } else {
                $response = $this->call($request->method(), $request->url(), [], [], [], [...$server, 'CONTENT_TYPE' => 'application/json'], $request->body());
            }

            return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
        });
    }

    private function mediaPush(array $options = []): PendingCommand
    {
        return $this->artisan('media:push', ['from' => $this->from, '--container' => 'photos', '--no-interaction' => true, ...$options]);
    }

    private function local(string $path, string $bytes, ?string $alt = null): void
    {
        File::ensureDirectoryExists(dirname($this->from.'/'.$path));
        file_put_contents($this->from.'/'.$path, $bytes);

        if ($alt !== null) {
            File::ensureDirectoryExists(dirname($this->from.'/'.$path).'/.meta');
            file_put_contents(dirname($this->from.'/'.$path).'/.meta/'.basename($path).'.yaml', "data:\n  alt: '{$alt}'\n  focus: 40-60-1\n");
        }
    }

    /** @return list<Request> the uploads the fake bucket was sent */
    private function bucketPuts(): array
    {
        return Http::recorded(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) === 'bucket.test')->map(fn (array $pair) => $pair[0])->all();
    }

    public function test_files_the_container_lacks_go_straight_to_the_bucket_with_their_alt_text_and_focus(): void
    {
        $this->signUploads();
        $this->local('team/anna.png', base64_decode(self::PNG), alt: 'Anna at the desk');
        $this->local('guide.pdf', 'pdf bytes');
        $saved = 0;
        Event::listen(AssetSaved::class, function () use (&$saved): void {
            $saved++;
        });

        $this->mediaPush()
            ->expectsOutputToContain('photos: 2 file(s), 2 to copy')
            ->expectsOutputToContain('Copied 2 file(s).')
            ->assertSuccessful();

        $this->assertSame(base64_decode(self::PNG), file_get_contents($this->disk.'/team/anna.png'));
        $this->assertSame('pdf bytes', file_get_contents($this->disk.'/guide.pdf'));
        $asset = AssetContainer::find('photos')->asset('team/anna.png');
        $this->assertSame('Anna at the desk', $asset->get('alt'));
        $this->assertSame('40-60-1', $asset->get('focus'));
        $this->assertSame(1, $asset->width());
        $this->assertSame(0, $saved, 'Saved quietly, so the static cache is not refreshed once per file.');

        $puts = $this->bucketPuts();
        $this->assertCount(2, $puts);
        $anna = collect($puts)->first(fn (Request $request) => str_contains($request->url(), 'anna.png'));
        $this->assertSame('PUT', $anna->method());
        $this->assertSame('image/png', $anna->header('Content-Type')[0]);
        $this->assertSame('public, max-age=31536000', $anna->header('Cache-Control')[0]);
        $this->assertSame(base64_encode(md5(base64_decode(self::PNG), true)), $anna->header('Content-MD5')[0]);
    }

    public function test_a_second_run_sends_nothing(): void
    {
        $this->signUploads();
        $this->local('guide.pdf', 'pdf bytes');
        $this->mediaPush()->assertSuccessful();
        $sent = count($this->bucketPuts());

        $this->mediaPush()->expectsOutputToContain('0 to copy (0 B), 1 already there')->assertSuccessful();

        $this->assertCount($sent, $this->bucketPuts());
    }

    public function test_another_file_at_the_same_path_is_left_as_it_is(): void
    {
        $this->signUploads();
        file_put_contents($this->disk.'/guide.pdf', 'their bytes');
        $this->local('guide.pdf', 'my longer bytes');

        $this->mediaPush()
            ->expectsOutputToContain('1 conflicting')
            ->expectsOutputToContain('left as it is')
            ->assertSuccessful();

        $this->assertSame('their bytes', file_get_contents($this->disk.'/guide.pdf'));
        $this->assertSame([], $this->bucketPuts());
    }

    public function test_a_disk_that_signs_no_uploads_takes_each_file_through_the_site(): void
    {
        $this->local('guide.pdf', 'pdf bytes', alt: 'The guide');
        $saved = 0;
        Event::listen(AssetSaved::class, function () use (&$saved): void {
            $saved++;
        });

        $this->mediaPush()->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertSame('pdf bytes', file_get_contents($this->disk.'/guide.pdf'));
        $this->assertSame('The guide', AssetContainer::find('photos')->asset('guide.pdf')->get('alt'));
        $this->assertSame(0, $saved);
        $this->assertSame([], $this->bucketPuts());
    }

    public function test_a_local_disk_that_serves_its_files_takes_each_file_through_the_site_and_not_its_own_upload_route(): void
    {
        config(['filesystems.disks.photos.serve' => true]);
        Storage::forgetDisk('photos');
        $this->assertTrue(Storage::disk('photos')->providesTemporaryUploadUrls());
        $this->local('guide.pdf', 'pdf bytes');

        $this->mediaPush()->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertSame('pdf bytes', file_get_contents($this->disk.'/guide.pdf'));
        $this->assertSame([], Http::recorded(fn (Request $request) => $request->method() === 'PUT')->all());
    }

    public function test_an_svg_goes_through_the_site_sanitised_and_a_second_run_finds_it(): void
    {
        $this->signUploads();
        $this->local('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>');

        $this->mediaPush()->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertStringNotContainsString('<script', file_get_contents($this->disk.'/logo.svg'));
        $this->assertSame([], $this->bucketPuts());
        $this->mediaPush()->expectsOutputToContain('1 already there')->assertSuccessful();
    }

    public function test_an_upload_that_never_finished_is_finished_on_the_next_run_without_sending_it_again(): void
    {
        $this->signUploads();
        $this->local('guide.pdf', 'pdf bytes', alt: 'The guide');
        file_put_contents($this->disk.'/guide.pdf', 'pdf bytes');

        $this->mediaPush()->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertSame([], $this->bucketPuts());
        $this->assertSame('The guide', AssetContainer::find('photos')->asset('guide.pdf')->get('alt'));
    }

    public function test_an_upload_the_bucket_refuses_fails_and_saves_nothing(): void
    {
        $this->signUploads();
        $this->local('guide.pdf', 'pdf bytes');
        $this->bucketAnswers['guide.pdf'] = [403, '<Error><Code>SignatureDoesNotMatch</Code></Error>'];

        $this->mediaPush()
            ->expectsOutputToContain('guide.pdf: the bucket refused the upload: HTTP 403 (SignatureDoesNotMatch).')
            ->assertFailed();

        $this->assertCount(1, $this->bucketPuts(), 'A refusal is not sent again.');
        $this->assertFileDoesNotExist($this->disk.'/guide.pdf');
        $this->assertFileDoesNotExist($this->disk.'/.meta/guide.pdf.yaml');
    }

    public function test_a_file_the_container_s_rules_refuse_is_deleted_again(): void
    {
        $this->signUploads();
        AssetContainer::find('photos')->validationRules(['mimes:pdf'])->save();
        $this->local('anna.png', base64_decode(self::PNG));

        $this->mediaPush()->expectsOutputToContain('anna.png: The file field must be a file of type: pdf.')->assertFailed();

        $this->assertFileDoesNotExist($this->disk.'/anna.png');
    }

    public function test_a_file_the_container_s_rules_refuse_is_not_stored_through_the_site_either(): void
    {
        AssetContainer::find('photos')->validationRules(['mimes:pdf'])->save();
        $this->local('anna.png', base64_decode(self::PNG));

        $this->mediaPush()->expectsOutputToContain('anna.png: The file field must be a file of type: pdf.')->assertFailed();

        $this->assertFileDoesNotExist($this->disk.'/anna.png');
    }

    public function test_a_file_over_the_limit_is_refused_before_anything_is_sent(): void
    {
        $this->signUploads();
        config(['core-web.content_sync.media_push_bytes' => 4]);
        $this->local('guide.pdf', 'pdf bytes');

        $this->mediaPush()->expectsOutputToContain('is over')->assertFailed();

        $this->assertSame([], $this->bucketPuts());
    }

    public function test_paths_no_container_takes_are_refused_and_hidden_files_left_out(): void
    {
        $this->signUploads();
        $this->local('shell.php', '<?php echo 1;');
        $this->local('noextension', 'x');
        $this->local('.DS_Store', 'x');
        $this->local('guide.pdf', 'pdf bytes');

        $this->mediaPush()
            ->expectsOutputToContain('3 file(s), 1 to copy (9 B), 0 already there, 0 conflicting, 2 refused.')
            ->expectsOutputToContain('shell.php: The site takes no upload named shell.php')
            ->assertFailed();

        $this->assertFileExists($this->disk.'/guide.pdf');
        $this->assertFileDoesNotExist($this->disk.'/shell.php');
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        $this->signUploads();
        $this->local('guide.pdf', 'pdf bytes');

        $this->mediaPush(['--dry-run' => true])->expectsOutputToContain('1 to copy')->expectsOutputToContain('Dry run: nothing was sent.')->assertSuccessful();

        $this->assertFileDoesNotExist($this->disk.'/guide.pdf');
        $this->assertSame([], $this->bucketPuts());
    }

    public function test_copying_needs_the_push_permission(): void
    {
        $token = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['puller']));

        foreach (['check', 'uploads', 'finish'] as $endpoint) {
            $this->withToken($token)->postJson("/!/core-web/sync/media/{$endpoint}", ['container' => 'photos', 'files' => [['path' => 'a.pdf', 'size' => 1, 'ticket' => 'x']]])
                ->assertForbidden()
                ->assertJson(['error' => 'forbidden']);
        }
    }

    public function test_a_ticket_finishes_only_its_own_user_s_file_in_its_own_container(): void
    {
        $this->signUploads();
        AssetContainer::make('other')->disk('photos')->save();
        $boss = $this->tokenFor($this->cmsUser('ann@example.com', super: true));
        $pia = $this->tokenFor($this->cmsUser('pia@example.com', super: true));

        $ticket = $this->withToken($boss)->postJson('/!/core-web/sync/media/uploads', ['container' => 'photos', 'files' => [['path' => 'guide.pdf', 'size' => 9]]])
            ->assertOk()
            ->assertJson(['files' => [['path' => 'guide.pdf', 'status' => 'upload']]])
            ->json('files.0.ticket');
        file_put_contents($this->disk.'/guide.pdf', 'pdf bytes');

        $this->withToken($pia)->postJson('/!/core-web/sync/media/finish', ['container' => 'photos', 'files' => [['ticket' => $ticket]]])->assertOk()->assertJson(['files' => [['status' => 'invalid']]]);
        $this->withToken($boss)->postJson('/!/core-web/sync/media/finish', ['container' => 'other', 'files' => [['ticket' => $ticket]]])->assertOk()->assertJson(['files' => [['status' => 'invalid']]]);
        $this->withToken($boss)->postJson('/!/core-web/sync/media/finish', ['container' => 'photos', 'files' => [['ticket' => 'forged']]])->assertOk()->assertJson(['files' => [['status' => 'invalid']]]);
        $this->assertFileDoesNotExist($this->disk.'/.meta/guide.pdf.yaml');

        $this->withToken($boss)->postJson('/!/core-web/sync/media/finish', ['container' => 'photos', 'files' => [['ticket' => $ticket]]])->assertOk()->assertJson(['files' => [['path' => 'guide.pdf', 'status' => 'stored']]]);
    }

    public function test_a_ticket_for_a_file_of_another_size_stores_nothing(): void
    {
        $this->signUploads();
        $token = $this->tokenFor($this->cmsUser('ann@example.com', super: true));
        $ticket = $this->withToken($token)->postJson('/!/core-web/sync/media/uploads', ['container' => 'photos', 'files' => [['path' => 'guide.pdf', 'size' => 9]]])->json('files.0.ticket');
        file_put_contents($this->disk.'/guide.pdf', 'other');

        $this->withToken($token)->postJson('/!/core-web/sync/media/finish', ['container' => 'photos', 'files' => [['ticket' => $ticket]]])->assertOk()->assertJson(['files' => [['status' => 'conflict']]]);

        $this->assertFileDoesNotExist($this->disk.'/.meta/guide.pdf.yaml');
    }

    public function test_it_runs_only_in_a_local_checkout(): void
    {
        $this->app['env'] = 'production';

        $this->mediaPush()->expectsOutputToContain('runs only where APP_ENV is local')->assertFailed();
    }
}
