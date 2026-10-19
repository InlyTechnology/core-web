<?php

namespace Inly\CoreWeb\Tests\Feature;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inly\CoreWeb\ContentSync\RemoteLoginNeeded;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\RemoteSiteError;
use Inly\CoreWeb\Media\LayeredAdapter;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Media\Push\AssetPusher;
use Inly\CoreWeb\Media\Push\AssetReferences;
use Inly\CoreWeb\Media\Push\HeldAssets;
use Inly\CoreWeb\Media\Push\HttpPushTransport;
use Inly\CoreWeb\Media\Push\PushTransport;
use Inly\CoreWeb\Tests\Feature\ContentSync\SyncsContent;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Fields;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class MediaPushTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['media', 'content_sync'];

    private string $root;

    private ?UserContract $pusher = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.media.push.accept', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/core-web-push-'.uniqid();
        File::ensureDirectoryExists($this->root.'/remote');
        File::ensureDirectoryExists($this->root.'/blueprints');
        Blueprint::setDirectory($this->root.'/blueprints');
        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->root.'/remote', 'url' => '/assets']]);
        Storage::forgetDisk('assets');
        AssetContainer::make('assets')->disk('assets')->save();

        Collection::make('pages')->sites(['default'])->routes('/{slug}')->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'hero', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1]],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'sets' => ['main' => ['sets' => ['gallery' => ['fields' => [
                ['handle' => 'images', 'field' => ['type' => 'assets', 'container' => 'assets']],
            ]]]]]]],
            ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['media' => ['fields' => [
                ['handle' => 'file', 'field' => ['type' => 'assets', 'container' => 'assets']],
                ['handle' => 'rows', 'field' => ['type' => 'grid', 'fields' => [
                    ['handle' => 'icon', 'field' => ['type' => 'assets', 'container' => 'assets']],
                ]]],
            ]]]]]]],
        ]])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        RemoteSite::forget();

        parent::tearDown();
    }

    private function fields(): Fields
    {
        return Blueprint::find('collections.pages.page')->fields();
    }

    private function super(): UserContract
    {
        return $this->pusher = $this->cmsUser('ann@example.com', super: true);
    }

    /** @return array<string, string> the pusher's content:login token, as a push sends it */
    public function headers(): array
    {
        return ['Accept' => 'application/json', ...($this->pusher ? ['Authorization' => 'Bearer '.$this->tokenFor($this->pusher)] : [])];
    }

    private function remoteFile(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->root.'/remote/'.$path));
        File::put($this->root.'/remote/'.$path, $contents);
    }

    private function upload(string $path, string $contents, ?string $sha = null): TestResponse
    {
        return $this->post('/!/core-web/sync/assets', [
            'container' => 'assets',
            'path' => $path,
            'sha256' => $sha ?? hash('sha256', $contents),
            'file' => UploadedFile::fake()->createWithContent(basename($path), $contents),
        ], $this->headers());
    }

    public function test_references_are_found_and_rewritten_at_any_depth(): void
    {
        $values = [
            'title' => 'Hello',
            'hero' => 'cars/hero.jpg',
            'body' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'image', 'attrs' => ['src' => 'asset::assets::inline/photo.jpg']],
                    ['type' => 'text', 'text' => 'pdf', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://asset::assets::docs/a.pdf']]]],
                ]],
                ['type' => 'set', 'attrs' => ['values' => ['type' => 'gallery', 'images' => ['g/1.jpg', 'g/2.jpg']]]],
            ],
            'blocks' => [
                ['type' => 'media', 'file' => ['b/file.jpg'], 'rows' => [['icon' => ['b/icon.svg']]]],
            ],
        ];

        $this->assertSame([
            'assets::cars/hero.jpg',
            'assets::inline/photo.jpg',
            'assets::docs/a.pdf',
            'assets::g/1.jpg',
            'assets::g/2.jpg',
            'assets::b/file.jpg',
            'assets::b/icon.svg',
        ], AssetReferences::collect($this->fields(), $values));

        $rewritten = AssetReferences::rewrite($this->fields(), $values, [
            'assets::cars/hero.jpg' => '_pushed/abc/cars/hero.jpg',
            'assets::docs/a.pdf' => '_pushed/def/docs/a.pdf',
            'assets::g/2.jpg' => '_pushed/123/g/2.jpg',
            'assets::b/icon.svg' => '_pushed/456/b/icon.svg',
        ]);

        $this->assertSame('_pushed/abc/cars/hero.jpg', $rewritten['hero']);
        $this->assertSame('asset::assets::inline/photo.jpg', $rewritten['body'][0]['content'][0]['attrs']['src']);
        $this->assertSame('statamic://asset::assets::_pushed/def/docs/a.pdf', $rewritten['body'][0]['content'][1]['marks'][0]['attrs']['href']);
        $this->assertSame(['g/1.jpg', '_pushed/123/g/2.jpg'], $rewritten['body'][1]['attrs']['values']['images']);
        $this->assertSame(['_pushed/456/b/icon.svg'], $rewritten['blocks'][0]['rows'][0]['icon']);
        $this->assertSame('Hello', $rewritten['title']);
    }

    public function test_references_in_bard_stored_as_html_are_found(): void
    {
        $fields = Blueprint::make()->setContents(['fields' => [['handle' => 'body', 'field' => ['type' => 'bard', 'save_html' => true]]]])->fields();
        $html = '<p><img src="statamic://asset::assets::a/b.jpg" alt="x"> and <a href="statamic://asset::assets::c.pdf">pdf</a></p>';

        $this->assertSame(['assets::a/b.jpg', 'assets::c.pdf'], AssetReferences::collect($fields, ['body' => $html]));
        $this->assertSame(
            '<p><img src="statamic://asset::assets::_pushed/1/a/b.jpg" alt="x"> and <a href="statamic://asset::assets::c.pdf">pdf</a></p>',
            AssetReferences::rewrite($fields, ['body' => $html], ['assets::a/b.jpg' => '_pushed/1/a/b.jpg'])['body'],
        );
    }

    public function test_check_says_what_the_site_already_has(): void
    {
        $this->super();
        $this->remoteFile('cars/same.jpg', 'same');
        $this->remoteFile('cars/other.jpg', 'production');
        $this->upload('cars/held.jpg', 'held')->assertOk();

        $files = fn (string $path, string $contents) => ['container' => 'assets', 'path' => $path, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)];

        $this->postJson('/!/core-web/sync/assets/check', ['files' => [
            $files('cars/same.jpg', 'same'),
            $files('cars/other.jpg', 'laptop'),
            $files('cars/held.jpg', 'held'),
            ['container' => 'nope', 'path' => 'a.jpg', 'sha256' => str_repeat('a', 64), 'size' => 1],
            $files('../escape.jpg', 'x'),
        ]], $this->headers())->assertOk()->assertJson(['files' => [
            ['status' => 'exists', 'path' => 'cars/same.jpg'],
            ['status' => 'needed', 'path' => '_pushed/'.substr(hash('sha256', 'laptop'), 0, 12).'/cars/other.jpg'],
            ['status' => 'held', 'path' => '_pushed/'.substr(hash('sha256', 'held'), 0, 12).'/cars/held.jpg'],
            ['status' => 'refused', 'reason' => 'unknown_container'],
            ['status' => 'refused', 'reason' => 'bad_path'],
        ]]);
    }

    public function test_an_upload_lands_in_the_holding_folder_and_never_replaces_a_file(): void
    {
        $ann = $this->super();
        $this->remoteFile('cars/a.jpg', 'production');

        $response = $this->upload('cars/a.jpg', 'laptop')->assertOk()->assertJson(['status' => 'uploaded']);
        $held = $response->json('path');

        $this->assertSame('_pushed/'.substr(hash('sha256', 'laptop'), 0, 12).'/cars/a.jpg', $held);
        $this->assertSame('production', File::get($this->root.'/remote/cars/a.jpg'));
        $this->assertSame('laptop', File::get($this->root.'/remote/'.$held));
        $this->assertSame('cars/a.jpg', Asset::find("assets::{$held}")->get(HeldAssets::META)['for']);
        $this->assertSame($ann->id(), Asset::find("assets::{$held}")->get(HeldAssets::META)['by'], 'an id, not an email: the meta sits in the public bucket');

        $this->upload('cars/a.jpg', 'laptop')->assertOk()->assertJson(['status' => 'held', 'path' => $held]);
    }

    public function test_an_upload_is_refused_when_it_does_not_add_up(): void
    {
        $this->super();

        $this->upload('cars/a.jpg', 'laptop', hash('sha256', 'other'))->assertStatus(422)->assertJson(['reason' => 'hash_mismatch']);
        $this->upload('cars/a.php', '<?php echo 1;')->assertStatus(422)->assertJson(['reason' => 'file_type']);
        $this->upload('_pushed/x/a.jpg', 'laptop')->assertStatus(422)->assertJson(['reason' => 'bad_path']);

        $this->assertSame([], File::allFiles($this->root.'/remote'));
    }

    public function test_an_svg_is_stored_sanitised_and_still_found_by_what_was_pushed(): void
    {
        $this->super();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>';

        $held = $this->upload('icons/a.svg', $svg)->assertOk()->json('path');

        $this->assertStringNotContainsString('<script', File::get($this->root.'/remote/'.$held));
        $this->upload('icons/a.svg', $svg)->assertOk()->assertJson(['status' => 'held', 'path' => $held]);
    }

    public function test_the_container_s_own_upload_rules_apply(): void
    {
        $this->super();
        AssetContainer::find('assets')->validationRules(['max:1'])->save();

        $this->upload('cars/a.jpg', str_repeat('x', 4096))->assertStatus(422)->assertJson(['reason' => 'file_type']);
    }

    public function test_the_pusher_needs_upload_rights_on_the_container(): void
    {
        $this->roles(['puller' => ['access cp', 'pull content']]);
        $this->pusher = $this->cmsUser('bo@example.com', roles: ['puller']);

        $this->upload('cars/a.jpg', 'laptop')->assertStatus(422)->assertJson(['reason' => 'forbidden']);

        $this->pusher = null;
        $this->app['auth']->forgetGuards();
        $this->upload('cars/a.jpg', 'laptop')->assertUnauthorized()->assertJson(['error' => 'sync_token_refused']);
    }

    public function test_publishing_copies_held_files_to_their_folder_and_points_the_entry_there(): void
    {
        $this->super();
        $this->remoteFile('cars/a.jpg', 'production');
        $held = $this->upload('cars/a.jpg', 'laptop')->json('path');

        $draft = Entry::make()->collection('pages')->locale('default')->slug('nyhet')->published(false)
            ->data(['title' => 'Nyhet', 'hero' => $held, 'body' => [['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => "asset::assets::{$held}"]]]]]]);
        $draft->save();

        $this->assertSame($held, $draft->fresh()->get('hero'), 'an unpublished draft keeps the held file');

        $draft->published(true)->save();
        $entry = Entry::find($draft->id());

        $this->assertSame('cars/a-2.jpg', $entry->get('hero'));
        $this->assertSame('asset::assets::cars/a-2.jpg', $entry->get('body')[0]['content'][0]['attrs']['src']);
        $this->assertSame('laptop', File::get($this->root.'/remote/cars/a-2.jpg'));
        $this->assertSame('production', File::get($this->root.'/remote/cars/a.jpg'));
        $this->assertTrue(File::exists($this->root.'/remote/'.$held), 'other drafts may still point at the held file');
        $this->assertNull(Asset::find('assets::cars/a-2.jpg')->get(HeldAssets::META));

        $second = Entry::make()->collection('pages')->locale('default')->slug('annan')->published(true)->data(['title' => 'Annan', 'hero' => $held]);
        $second->save();

        $this->assertSame('cars/a-2.jpg', Entry::find($second->id())->get('hero'), 'a file released before is reused');
        $this->assertFalse(File::exists($this->root.'/remote/cars/a-3.jpg'));

        File::put($this->root.'/remote/cars/a-2.jpg', 'replaced in the CP');
        $third = Entry::make()->collection('pages')->locale('default')->slug('tredje')->published(true)->data(['title' => 'Tredje', 'hero' => $held]);
        $third->save();

        $this->assertSame('cars/a-3.jpg', Entry::find($third->id())->get('hero'), 'a released copy replaced since is not reused');
        $this->assertSame('laptop', File::get($this->root.'/remote/cars/a-3.jpg'));
    }

    public function test_the_pusher_sends_only_files_the_remote_lacks_and_points_the_values_at_them(): void
    {
        $this->super();
        $this->remoteFile('cars/prod.jpg', 'production');

        // The laptop: production's file through the layer's lower half, its own new and changed files in the layer.
        File::ensureDirectoryExists($this->root.'/layer/cars');
        File::put($this->root.'/layer/cars/new.jpg', 'new');
        config(['filesystems.disks.assets' => [
            'driver' => MediaDisks::LAYERED_DRIVER,
            'name' => 'assets',
            'upper' => ['driver' => 'local', 'root' => $this->root.'/layer', 'url' => '/media-layer/assets'],
            'lower' => ['driver' => 'local', 'root' => $this->root.'/remote', 'url' => '/assets'],
            'url' => '/assets',
        ]]);
        Storage::forgetDisk('assets');
        $this->assertInstanceOf(LayeredAdapter::class, Storage::disk('assets')->getAdapter());

        $transport = new class($this) implements PushTransport
        {
            public array $checked = [];

            public array $uploaded = [];

            public function __construct(private MediaPushTest $test) {}

            public function check(array $files): array
            {
                $this->checked = array_column($files, 'path');

                return $this->test->postJson('/!/core-web/sync/assets/check', ['files' => $files], $this->test->headers())->json('files');
            }

            public function upload(string $container, string $path, string $sha256, $stream): array
            {
                $this->uploaded[] = $path;
                $contents = stream_get_contents($stream);

                return $this->test->post('/!/core-web/sync/assets', [
                    'container' => $container, 'path' => $path, 'sha256' => $sha256,
                    'file' => UploadedFile::fake()->createWithContent(basename($path), $contents),
                ], $this->test->headers())->json();
            }
        };

        // The remote's disk is the plain folder; the pusher's is the layered one, so the requests below see the remote.
        $remote = ['driver' => 'local', 'root' => $this->root.'/remote', 'url' => '/assets'];
        $pusher = new AssetPusher(new class($transport, $remote) implements PushTransport
        {
            public function __construct(private PushTransport $inner, private array $remote) {}

            public function check(array $files): array
            {
                return $this->asRemote(fn () => $this->inner->check($files));
            }

            public function upload(string $container, string $path, string $sha256, $stream): array
            {
                return $this->asRemote(fn () => $this->inner->upload($container, $path, $sha256, $stream));
            }

            private function asRemote(Closure $call): array
            {
                $layered = config('filesystems.disks.assets');
                config(['filesystems.disks.assets' => $this->remote]);
                Storage::forgetDisk('assets');

                try {
                    return $call();
                } finally {
                    config(['filesystems.disks.assets' => $layered]);
                    Storage::forgetDisk('assets');
                }
            }
        });

        $result = $pusher->push($this->fields(), ['hero' => 'cars/new.jpg', 'blocks' => [['type' => 'media', 'file' => ['cars/prod.jpg']]]]);

        $held = '_pushed/'.substr(hash('sha256', 'new'), 0, 12).'/cars/new.jpg';
        $this->assertSame(['cars/new.jpg'], $transport->checked, "production's own file is neither hashed nor sent");
        $this->assertSame(['cars/new.jpg'], $transport->uploaded);
        $this->assertSame($held, $result['values']['hero']);
        $this->assertSame(['cars/prod.jpg'], $result['values']['blocks'][0]['file']);
        $this->assertSame([['id' => 'assets::cars/new.jpg', 'status' => 'uploaded', 'path' => $held]], $result['files']);
        $this->assertSame('new', File::get($this->root.'/remote/'.$held));
    }

    public function test_a_failed_upload_is_answered_refused_and_the_others_still_count(): void
    {
        File::ensureDirectoryExists($this->root.'/remote/cars');
        File::put($this->root.'/remote/cars/ok.jpg', 'ok');
        File::put($this->root.'/remote/cars/fails.jpg', 'fails');

        $pusher = new AssetPusher(new class implements PushTransport
        {
            public function check(array $files): array
            {
                return array_map(fn (array $file) => ['status' => 'needed', 'path' => '_pushed/x/'.$file['path']], $files);
            }

            public function upload(string $container, string $path, string $sha256, $stream): array
            {
                if ($path === 'cars/fails.jpg') {
                    throw new \RuntimeException('connection reset');
                }

                return ['status' => 'uploaded', 'path' => '_pushed/x/'.$path];
            }
        });

        $result = $pusher->push($this->fields(), ['blocks' => [['type' => 'media', 'file' => ['cars/ok.jpg', 'cars/fails.jpg']]]]);

        $this->assertSame(['_pushed/x/cars/ok.jpg', 'cars/fails.jpg'], $result['values']['blocks'][0]['file']);
        $this->assertSame('transport', $result['files'][1]['reason']);
    }

    public function test_the_http_transport_sends_this_machine_s_login_and_a_refused_one_ends_it(): void
    {
        config(['core-web.content_sync.remote_url' => 'https://site.example']);
        RemoteSite::store('https://site.example', 'machine-token', ['name' => 'Ann', 'email' => 'ann@example.com'], null);

        Http::fake([
            'site.example/!/core-web/sync/assets/check' => Http::response(['files' => [['status' => 'needed', 'path' => '_pushed/x/a.jpg']]]),
            'site.example/!/core-web/sync/assets' => Http::sequence()
                ->push(['status' => 'uploaded', 'path' => '_pushed/x/a.jpg'])
                ->push(['status' => 'uploaded', 'path' => '_pushed/x/a.jpg'], 200, ['X-Core-Web-Token-Expires-At' => '2030-01-01T00:00:00+00:00'])
                ->push('Authorization Required', 401)
                ->push(['error' => 'sync_token_refused'], 401),
        ]);

        $transport = HttpPushTransport::fromStoredLogin();
        $stream = fopen('php://memory', 'r+');

        try {
            $this->assertSame('needed', $transport->check([['container' => 'assets', 'path' => 'a.jpg', 'sha256' => str_repeat('a', 64), 'size' => 1]])[0]['status']);
            $this->assertSame('uploaded', $transport->upload('assets', 'a.jpg', str_repeat('a', 64), $stream)['status']);
            Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer machine-token'));
            Http::assertSent(fn ($request) => $request->isMultipart() && str_starts_with($request->header('Content-Type')[0] ?? '', 'multipart/form-data; boundary='));

            $transport->upload('assets', 'a.jpg', str_repeat('a', 64), $stream);
            $this->assertSame('2030-01-01', RemoteSite::stored()->expiresAt()->toDateString(), 'a push slides the stored login as a pull does');

            try {
                $transport->upload('assets', 'a.jpg', str_repeat('a', 64), $stream);
                $this->fail('A 401 from in front of the site is an error, not a refused file.');
            } catch (RemoteLoginNeeded) {
                $this->fail('Only the sync middleware\'s own 401 ends the login.');
            } catch (RemoteSiteError) {
                $this->assertNotNull(RemoteSite::stored());
            }

            $this->expectException(RemoteLoginNeeded::class);
            $transport->upload('assets', 'a.jpg', str_repeat('a', 64), $stream);
        } finally {
            fclose($stream);
            $this->assertNull(RemoteSite::stored());
        }
    }

    public function test_the_http_transport_needs_a_login(): void
    {
        RemoteSite::forget();

        $this->expectException(RemoteLoginNeeded::class);
        HttpPushTransport::fromStoredLogin();
    }

    public function test_the_push_endpoints_take_https_only(): void
    {
        $this->super();
        $this->app['env'] = 'production';

        $this->postJson('http://localhost/!/core-web/sync/assets/check', ['files' => []], $this->headers())
            ->assertForbidden()->assertJson(['error' => 'https_required']);
    }
}
