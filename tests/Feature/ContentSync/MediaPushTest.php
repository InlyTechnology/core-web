<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Closure;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;
use Inly\CoreWeb\ContentSync\AssetPush;
use Inly\CoreWeb\ContentSync\MediaPush;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Tests\TestCase;
use Mockery;
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

    /** A PDF a type check recognises as one. */
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private string $scratch;

    private string $from;

    private string $disk;

    /** What the fake bucket answers a signed upload with, by path, before it stores anything: [status, body], or a closure returning one or null to go on. */
    private array $bucketAnswers = [];

    /** @var list<array<string, mixed>> the options each signed upload was made with */
    private array $signed = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
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
        $this->signUploads();

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

    /** The photos disk signs uploads as an S3 bucket does: the MD5 among the signed headers, type and Cache-Control left to the request. */
    private function signUploads(string $base = 'https://bucket.test/'): void
    {
        Storage::forgetDisk('photos');
        // The disk binds the callback to itself, so the options are recorded through a reference.
        $signed = &$this->signed;
        Storage::disk('photos')->buildTemporaryUploadUrlsUsing(function (string $path, $expiration, array $options) use ($base, &$signed) {
            $signed[] = $options;

            return ['url' => $base.$path.'?X-Amz-Signature=abc', 'headers' => ['Host' => ['bucket.test'], 'Content-MD5' => [$options['ContentMD5']]]];
        });
    }

    /** The photos disk as a plain local folder, which signs no uploads. */
    private function signNoUploads(): void
    {
        Storage::forgetDisk('photos');
    }

    private function routeToThisAppAndTheBucket(): void
    {
        Http::fake(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_HOST) === 'bucket.test') {
                $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');
                $answer = $this->bucketAnswers[$path] ?? null;
                $answer = $answer instanceof Closure ? $answer($request) : $answer;

                if ($answer !== null) {
                    return Http::response($answer[1], $answer[0]);
                }

                if (base64_encode(md5($request->body(), true)) !== ($request->header('Content-MD5')[0] ?? null)) {
                    return Http::response('<Error><Code>BadDigest</Code></Error>', 400);
                }

                if (($request->header('If-None-Match')[0] ?? null) === '*' && file_exists($this->disk.'/'.$path)) {
                    return Http::response('<Error><Code>PreconditionFailed</Code></Error>', 412);
                }

                File::ensureDirectoryExists(dirname($this->disk.'/'.$path));
                file_put_contents($this->disk.'/'.$path, $request->body());

                return Http::response('', 200);
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

    /** The site's answer to media/uploads for one file of the given bytes, as the token's user. */
    private function uploadFor(string $token, string $path, string $bytes, string $container = 'photos'): array
    {
        return $this->withToken($token)->postJson('/!/core-web/sync/media/uploads', ['container' => $container, 'files' => [['path' => $path, 'size' => strlen($bytes), 'md5' => md5($bytes)]]])
            ->assertOk()
            ->json('files.0');
    }

    private function finishWith(string $token, string $ticket, string $container = 'photos'): array
    {
        return $this->withToken($token)->postJson('/!/core-web/sync/media/finish', ['container' => $container, 'files' => [['ticket' => $ticket]]])->assertOk()->json('files.0');
    }

    /** @return list<Request> the uploads the fake bucket was sent */
    private function bucketPuts(): array
    {
        return Http::recorded(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) === 'bucket.test')->map(fn (array $pair) => $pair[0])->all();
    }

    public function test_files_the_container_lacks_go_straight_to_the_bucket_with_their_alt_text_and_focus(): void
    {
        $this->local('team/anna.png', base64_decode(self::PNG), alt: 'Anna at the desk');
        $this->local('guide.pdf', self::PDF);
        $saved = 0;
        Event::listen(AssetSaved::class, function () use (&$saved): void {
            $saved++;
        });

        $this->mediaPush()
            ->expectsOutputToContain('photos: 2 file(s), 2 to copy')
            ->expectsOutputToContain('Copied 2 file(s).')
            ->assertSuccessful();

        $this->assertSame(base64_decode(self::PNG), file_get_contents($this->disk.'/team/anna.png'));
        $this->assertSame(self::PDF, file_get_contents($this->disk.'/guide.pdf'));
        $asset = AssetContainer::find('photos')->asset('team/anna.png');
        $this->assertSame('Anna at the desk', $asset->get('alt'));
        $this->assertSame('40-60-1', $asset->get('focus'));
        $this->assertSame(1, $asset->width());
        $this->assertSame(0, $saved, 'Saved quietly, so the static cache is not refreshed once per file.');

        $md5 = base64_encode(md5(base64_decode(self::PNG), true));
        $this->assertContains(['ContentType' => 'image/png', 'CacheControl' => 'public, max-age=31536000', 'ContentMD5' => $md5], $this->signed);
        $puts = $this->bucketPuts();
        $this->assertCount(2, $puts);
        $anna = collect($puts)->first(fn (Request $request) => str_contains($request->url(), 'anna.png'));
        $this->assertSame('PUT', $anna->method());
        $this->assertSame('image/png', $anna->header('Content-Type')[0]);
        $this->assertSame('public, max-age=31536000', $anna->header('Cache-Control')[0]);
        $this->assertSame($md5, $anna->header('Content-MD5')[0]);
        $this->assertSame('*', $anna->header('If-None-Match')[0]);
    }

    public function test_a_second_run_sends_nothing(): void
    {
        $this->local('guide.pdf', self::PDF);
        $this->mediaPush()->assertSuccessful();
        $sent = count($this->bucketPuts());

        $this->mediaPush()->expectsOutputToContain('0 to copy (0 B), 0 to finish, 1 already there')->assertSuccessful();

        $this->assertCount($sent, $this->bucketPuts());
    }

    public function test_another_file_at_the_same_path_is_left_as_it_is(): void
    {
        file_put_contents($this->disk.'/guide.pdf', 'their bytes');
        $this->local('guide.pdf', self::PDF);

        $this->mediaPush()
            ->expectsOutputToContain('1 conflicting')
            ->expectsOutputToContain('left as it is')
            ->assertSuccessful();

        $this->assertSame('their bytes', file_get_contents($this->disk.'/guide.pdf'));
        $this->assertSame([], $this->bucketPuts());
    }

    public function test_a_container_that_is_not_on_a_bucket_is_refused(): void
    {
        $this->signNoUploads();
        $this->local('guide.pdf', self::PDF);

        $this->mediaPush()->expectsOutputToContain('is not on a bucket')->assertFailed();

        $this->assertFileDoesNotExist($this->disk.'/guide.pdf');
    }

    public function test_a_local_disk_that_serves_its_files_is_not_taken_for_a_bucket(): void
    {
        config(['filesystems.disks.photos.serve' => true]);
        $this->signNoUploads();
        $this->assertTrue(Storage::disk('photos')->providesTemporaryUploadUrls());
        $this->local('guide.pdf', self::PDF);

        $this->mediaPush()->expectsOutputToContain('is not on a bucket')->assertFailed();

        $this->assertSame([], Http::recorded(fn (Request $request) => $request->method() === 'PUT')->all());
    }

    public function test_an_svg_goes_through_the_site_sanitised_and_a_second_run_finds_it(): void
    {
        $this->local('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>', alt: 'The logo');
        $saved = 0;
        Event::listen(AssetSaved::class, function () use (&$saved): void {
            $saved++;
        });

        $this->mediaPush()->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertStringNotContainsString('<script', file_get_contents($this->disk.'/logo.svg'));
        $this->assertSame('The logo', AssetContainer::find('photos')->asset('logo.svg')->get('alt'));
        $this->assertSame(0, $saved);
        $this->assertSame([], $this->bucketPuts());
        $this->mediaPush()->expectsOutputToContain('1 already there')->assertSuccessful();
    }

    public function test_an_upload_that_never_finished_is_finished_on_the_next_run_without_sending_it_again(): void
    {
        $this->local('guide.pdf', self::PDF, alt: 'The guide');
        file_put_contents($this->disk.'/guide.pdf', self::PDF);

        $this->mediaPush()->expectsOutputToContain('0 to copy (0 B), 1 to finish')->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertSame([], $this->bucketPuts());
        $this->assertSame('The guide', AssetContainer::find('photos')->asset('guide.pdf')->get('alt'));
    }

    public function test_an_svg_that_never_finished_is_finished_on_the_next_run(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>';
        $this->local('logo.svg', $svg, alt: 'The logo');
        file_put_contents($this->disk.'/logo.svg', AssetPush::stored('logo.svg', $svg));

        $this->mediaPush()->expectsOutputToContain('1 to finish')->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertSame('The logo', AssetContainer::find('photos')->asset('logo.svg')->get('alt'));
    }

    public function test_a_file_without_meta_that_is_another_file_of_the_same_size_is_left_as_it_is(): void
    {
        $this->local('guide.pdf', self::PDF, alt: 'The guide');
        $theirs = strrev(self::PDF);
        file_put_contents($this->disk.'/guide.pdf', $theirs);

        $this->mediaPush()->expectsOutputToContain('guide.pdf is not this checkout\'s file')->assertSuccessful();

        $this->assertSame($theirs, file_get_contents($this->disk.'/guide.pdf'));
        $this->assertFileDoesNotExist($this->disk.'/.meta/guide.pdf.yaml');
    }

    public function test_a_file_that_was_there_before_is_never_deleted_for_breaking_the_rules(): void
    {
        AssetContainer::find('photos')->validationRules(['mimes:pdf'])->save();
        $this->local('anna.png', base64_decode(self::PNG));
        file_put_contents($this->disk.'/anna.png', base64_decode(self::PNG));

        $this->mediaPush()
            ->expectsOutputToContain('anna.png: The file field must be a file of type: pdf. The file was in the container before this run, so it was left as it is.')
            ->assertFailed();

        $this->assertFileExists($this->disk.'/anna.png');
        $this->assertSame([], $this->bucketPuts());
    }

    public function test_an_upload_the_bucket_refuses_fails_and_saves_nothing(): void
    {
        $this->local('guide.pdf', self::PDF);
        $this->bucketAnswers['guide.pdf'] = [403, '<Error><Code>SignatureDoesNotMatch</Code></Error>'];

        $this->mediaPush()
            ->expectsOutputToContain('guide.pdf: the bucket refused the upload: HTTP 403 (SignatureDoesNotMatch).')
            ->assertFailed();

        $this->assertCount(1, $this->bucketPuts(), 'A refusal is not sent again.');
        $this->assertFileDoesNotExist($this->disk.'/guide.pdf');
        $this->assertFileDoesNotExist($this->disk.'/.meta/guide.pdf.yaml');
    }

    public function test_a_bucket_that_fails_is_tried_again(): void
    {
        $this->local('guide.pdf', self::PDF);
        $tries = 0;
        $this->bucketAnswers['guide.pdf'] = function () use (&$tries): ?array {
            return ++$tries === 1 ? [503, '<Error><Code>SlowDown</Code></Error>'] : null;
        };

        $this->mediaPush()->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertSame(2, $tries);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_file_that_reaches_the_path_while_the_upload_is_on_its_way_is_left_as_it_is(): void
    {
        $this->local('guide.pdf', self::PDF);
        $theirs = strrev(self::PDF);
        $this->bucketAnswers['guide.pdf'] = function () use ($theirs): ?array {
            file_put_contents($this->disk.'/guide.pdf', $theirs);

            return null;
        };

        $this->mediaPush()->expectsOutputToContain('guide.pdf is not this checkout\'s file')->assertSuccessful();

        $this->assertSame($theirs, file_get_contents($this->disk.'/guide.pdf'));
    }

    public function test_an_address_that_is_not_https_is_never_sent_to(): void
    {
        // Plain http is allowed only to a host on this machine (a .test one), so the bucket is named elsewhere.
        $this->signUploads('http://bucket.example.com/');
        $this->local('guide.pdf', self::PDF);

        $this->mediaPush()->expectsOutputToContain('not https')->assertFailed();

        $this->assertSame([], $this->bucketPuts());
    }

    public function test_addresses_not_used_within_ten_minutes_are_signed_again(): void
    {
        $this->local('a.pdf', self::PDF);
        $this->local('b.pdf', self::PDF);
        $this->bucketAnswers['a.pdf'] = function (): ?array {
            $this->travel(11)->minutes();

            return null;
        };

        $this->mediaPush()->expectsOutputToContain('Copied 2 file(s).')->assertSuccessful();

        $this->assertCount(3, $this->signed, 'a and b, then b again.');
    }

    public function test_a_file_the_container_s_rules_refuse_is_deleted_again(): void
    {
        AssetContainer::find('photos')->validationRules(['mimes:pdf'])->save();
        $this->local('anna.png', base64_decode(self::PNG));

        $this->mediaPush()->expectsOutputToContain('anna.png: The file field must be a file of type: pdf.')->assertFailed();

        $this->assertFileDoesNotExist($this->disk.'/anna.png');
    }

    public function test_a_file_stored_as_another_type_than_its_extension_names_is_deleted_again(): void
    {
        $this->local('anna.png', '<html><body><script>alert(1)</script></body></html>');

        $this->mediaPush()->expectsOutputToContain('anna.png was stored as text/html, not image/png')->assertFailed();

        $this->assertFileDoesNotExist($this->disk.'/anna.png');
    }

    public function test_an_svg_the_container_s_rules_refuse_is_not_stored_through_the_site_either(): void
    {
        AssetContainer::find('photos')->validationRules(['mimes:pdf'])->save();
        $this->local('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');

        $this->mediaPush()->expectsOutputToContain('logo.svg: The file field must be a file of type: pdf.')->assertFailed();

        $this->assertFileDoesNotExist($this->disk.'/logo.svg');
    }

    public function test_a_file_over_the_limit_is_refused_before_anything_is_sent_and_in_a_dry_run(): void
    {
        config(['core-web.content_sync.media_push_bytes' => 4]);
        $this->local('guide.pdf', self::PDF);

        $this->mediaPush(['--dry-run' => true])->expectsOutputToContain('is over')->assertFailed();
        $this->mediaPush()->expectsOutputToContain('is over')->assertFailed();

        $this->assertSame([], $this->bucketPuts());
    }

    public function test_paths_no_container_takes_are_refused_and_hidden_files_left_out(): void
    {
        $this->local('shell.php', '<?php echo 1;');
        $this->local('noextension', 'x');
        $this->local('.DS_Store', 'x');
        $this->local('guide.pdf', self::PDF);

        $this->mediaPush()
            ->expectsOutputToContain('3 file(s), 1 to copy ('.strlen(self::PDF).' B), 0 to finish, 0 already there, 0 conflicting, 2 refused.')
            ->expectsOutputToContain('shell.php: The site takes no upload named shell.php')
            ->assertFailed();

        $this->assertFileExists($this->disk.'/guide.pdf');
        $this->assertFileDoesNotExist($this->disk.'/shell.php');
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        $this->local('guide.pdf', self::PDF);

        $this->mediaPush(['--dry-run' => true])->expectsOutputToContain('1 to copy')->expectsOutputToContain('Dry run: nothing was sent.')->assertSuccessful();

        $this->assertFileDoesNotExist($this->disk.'/guide.pdf');
        $this->assertSame([], $this->bucketPuts());
    }

    public function test_copying_needs_the_push_permission(): void
    {
        $token = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['puller']));

        foreach (['check', 'uploads', 'finish'] as $endpoint) {
            $this->withToken($token)->postJson("/!/core-web/sync/media/{$endpoint}", ['container' => 'photos', 'files' => [['path' => 'a.pdf', 'size' => 1, 'md5' => md5('a'), 'ticket' => 'x']]])
                ->assertForbidden()
                ->assertJson(['error' => 'forbidden']);
        }
    }

    public function test_copying_needs_the_permission_to_upload_to_the_container_and_to_make_its_folders(): void
    {
        $this->roles([
            'pusher' => ['access cp', 'pull content', 'push content', 'view photos assets'],
            'uploader' => ['access cp', 'pull content', 'push content', 'view photos assets', 'upload photos assets'],
        ]);
        $pusher = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['pusher']));
        $uploader = $this->tokenFor($this->cmsUser('ulf@example.com', roles: ['uploader']));

        $this->assertSame('forbidden', $this->uploadFor($pusher, 'guide.pdf', self::PDF)['status']);
        $this->assertSame('upload', $this->uploadFor($uploader, 'guide.pdf', self::PDF)['status']);
        $this->assertSame('forbidden', $this->uploadFor($uploader, 'new/guide.pdf', self::PDF)['status']);
    }

    public function test_a_ticket_finishes_only_its_own_user_s_file_in_its_own_container(): void
    {
        AssetContainer::make('other')->disk('photos')->save();
        $boss = $this->tokenFor($this->cmsUser('ann@example.com', super: true));
        $pia = $this->tokenFor($this->cmsUser('pia@example.com', super: true));

        $answer = $this->uploadFor($boss, 'guide.pdf', self::PDF);
        $this->assertSame(['path' => 'guide.pdf', 'status' => 'upload'], Arr::only($answer, ['path', 'status']));
        file_put_contents($this->disk.'/guide.pdf', self::PDF);

        $this->assertSame('invalid', $this->finishWith($pia, $answer['ticket'])['status']);
        $this->assertSame('invalid', $this->finishWith($boss, $answer['ticket'], 'other')['status']);
        $this->assertSame('invalid', $this->finishWith($boss, 'forged')['status']);
        $this->assertFileDoesNotExist($this->disk.'/.meta/guide.pdf.yaml');

        $this->assertSame(['path' => 'guide.pdf', 'status' => 'stored'], $this->finishWith($boss, $answer['ticket']));
    }

    public function test_a_ticket_for_other_bytes_stores_nothing_and_deletes_nothing(): void
    {
        $token = $this->tokenFor($this->cmsUser('ann@example.com', super: true));

        foreach (['other' => 'shorter', 'same size' => strrev(self::PDF)] as $theirs) {
            File::delete($this->disk.'/guide.pdf');
            $ticket = $this->uploadFor($token, 'guide.pdf', self::PDF)['ticket'];
            file_put_contents($this->disk.'/guide.pdf', $theirs);

            $this->assertSame('conflict', $this->finishWith($token, $ticket)['status']);
            $this->assertSame($theirs, file_get_contents($this->disk.'/guide.pdf'));
            $this->assertFileDoesNotExist($this->disk.'/.meta/guide.pdf.yaml');
        }
    }

    public function test_a_file_sent_with_another_size_than_it_was_signed_for_is_deleted_again(): void
    {
        $token = $this->tokenFor($this->cmsUser('ann@example.com', super: true));
        $bytes = str_repeat('x', 5000);
        $answer = $this->withToken($token)->postJson('/!/core-web/sync/media/uploads', ['container' => 'photos', 'files' => [['path' => 'guide.pdf', 'size' => 5, 'md5' => md5($bytes)]]])
            ->assertOk()
            ->json('files.0');
        // What the signed address took: the MD5 it names, at a size the checkout did not declare.
        file_put_contents($this->disk.'/guide.pdf', $bytes);

        $result = $this->finishWith($token, $answer['ticket']);

        $this->assertSame('invalid', $result['status']);
        $this->assertStringContainsString('another size than the 5 bytes', $result['message']);
        $this->assertFileDoesNotExist($this->disk.'/guide.pdf');
    }

    public function test_a_file_with_meta_that_reached_the_path_first_is_a_conflict(): void
    {
        $this->local('guide.pdf', self::PDF, alt: 'Ours');
        $theirs = strrev(self::PDF);
        $this->bucketAnswers['guide.pdf'] = function () use ($theirs): ?array {
            file_put_contents($this->disk.'/guide.pdf', $theirs);
            AssetContainer::find('photos')->makeAsset('guide.pdf')->set('alt', 'Theirs')->saveQuietly();

            return null;
        };

        $this->mediaPush()->expectsOutputToContain('guide.pdf is not this checkout\'s file')->doesntExpectOutputToContain('after the copy')->assertSuccessful();

        $this->assertSame($theirs, file_get_contents($this->disk.'/guide.pdf'));
        $this->assertSame('Theirs', AssetContainer::find('photos')->asset('guide.pdf')->get('alt'));
    }

    public function test_a_try_made_after_ten_minutes_goes_to_a_fresh_address(): void
    {
        $this->local('guide.pdf', self::PDF);
        $tries = 0;
        $this->bucketAnswers['guide.pdf'] = function () use (&$tries): ?array {
            if (++$tries > 1) {
                return null;
            }

            $this->travel(11)->minutes();

            return [503, '<Error><Code>SlowDown</Code></Error>'];
        };

        $this->mediaPush()->expectsOutputToContain('Copied 1 file(s).')->assertSuccessful();

        $this->assertCount(2, $this->signed, 'signed, then again before the second try.');
    }

    public function test_the_site_asks_for_each_file_s_md5(): void
    {
        $token = $this->tokenFor($this->cmsUser('ann@example.com', super: true));

        foreach ([[], ['md5' => 'not-an-md5']] as $md5) {
            $this->withToken($token)->postJson('/!/core-web/sync/media/uploads', ['container' => 'photos', 'files' => [['path' => 'guide.pdf', 'size' => 5, ...$md5]]])
                ->assertUnprocessable();
        }
    }

    public function test_an_s3_disk_takes_signed_uploads_where_laravel_says_it_does_not(): void
    {
        // Laravel 12, and 13 before 13.20, answer false for the S3 driver.
        $s3 = Mockery::mock(AwsS3V3Adapter::class);
        $s3->allows('providesTemporaryUploadUrls')->andReturn(false);

        $this->assertTrue(Closure::bind(fn (FilesystemAdapter $disk) => MediaPush::signsUploads($disk), null, MediaPush::class)($s3));
    }

    public function test_it_runs_only_in_a_local_checkout(): void
    {
        $this->app['env'] = 'production';

        $this->mediaPush()->expectsOutputToContain('runs only where APP_ENV is local')->assertFailed();
    }
}
