<?php

namespace Inly\CoreWeb\Tests\Feature;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inly\CoreWeb\Media\LayeredAdapter;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Media\Push\AssetPusher;
use Inly\CoreWeb\Media\Push\AssetReferences;
use Inly\CoreWeb\Media\Push\HeldAssets;
use Inly\CoreWeb\Media\Push\PushTransport;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Fields\Fields;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class MediaPushTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['media'];

    private string $root;

    private ?UserContract $pusher = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.media.push.accept', true);
        $app['config']->set('core-web.media.push.middleware', ['core-web.sync']);
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

        // Stands in for the login thread's token middleware: the test picks the person.
        $this->app->make(Router::class)->aliasMiddleware('core-web.sync', SignsInPusher::class);
        SignsInPusher::$user = fn () => $this->pusher;

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
        SignsInPusher::$user = null;

        parent::tearDown();
    }

    private function fields(): Fields
    {
        return Blueprint::find('collections.pages.page')->fields();
    }

    private function super(): UserContract
    {
        $user = User::make()->email('ann@example.com')->makeSuper();
        $user->save();

        return $this->pusher = $user;
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
        ], ['Accept' => 'application/json']);
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

    public function test_check_says_what_the_site_already_has(): void
    {
        $this->super();
        $this->remoteFile('cars/same.jpg', 'same');
        $this->remoteFile('cars/other.jpg', 'production');
        $this->remoteFile('_pushed/'.substr(hash('sha256', 'held'), 0, 12).'/cars/held.jpg', 'held');

        $files = fn (string $path, string $contents) => ['container' => 'assets', 'path' => $path, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)];

        $this->postJson('/!/core-web/sync/assets/check', ['files' => [
            $files('cars/same.jpg', 'same'),
            $files('cars/other.jpg', 'laptop'),
            $files('cars/held.jpg', 'held'),
            ['container' => 'nope', 'path' => 'a.jpg', 'sha256' => str_repeat('a', 64), 'size' => 1],
            $files('../escape.jpg', 'x'),
        ]])->assertOk()->assertJson(['files' => [
            ['status' => 'exists', 'path' => 'cars/same.jpg'],
            ['status' => 'needed', 'path' => '_pushed/'.substr(hash('sha256', 'laptop'), 0, 12).'/cars/other.jpg'],
            ['status' => 'held', 'path' => '_pushed/'.substr(hash('sha256', 'held'), 0, 12).'/cars/held.jpg'],
            ['status' => 'refused', 'reason' => 'unknown_container'],
            ['status' => 'refused', 'reason' => 'bad_path'],
        ]]);
    }

    public function test_an_upload_lands_in_the_holding_folder_and_never_replaces_a_file(): void
    {
        $this->super();
        $this->remoteFile('cars/a.jpg', 'production');

        $response = $this->upload('cars/a.jpg', 'laptop')->assertOk()->assertJson(['status' => 'uploaded']);
        $held = $response->json('path');

        $this->assertSame('_pushed/'.substr(hash('sha256', 'laptop'), 0, 12).'/cars/a.jpg', $held);
        $this->assertSame('production', File::get($this->root.'/remote/cars/a.jpg'));
        $this->assertSame('laptop', File::get($this->root.'/remote/'.$held));
        $this->assertSame('cars/a.jpg', Asset::find("assets::{$held}")->get(HeldAssets::META)['for']);
        $this->assertSame('ann@example.com', Asset::find("assets::{$held}")->get(HeldAssets::META)['by']);

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

    public function test_the_pusher_needs_upload_rights_on_the_container(): void
    {
        $user = User::make()->email('bo@example.com');
        $user->save();
        $this->pusher = $user;

        $this->upload('cars/a.jpg', 'laptop')->assertStatus(422)->assertJson(['reason' => 'forbidden']);

        $this->pusher = null;
        $this->app['auth']->forgetGuards();
        $this->upload('cars/a.jpg', 'laptop')->assertUnauthorized();
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

                return $this->test->postJson('/!/core-web/sync/assets/check', ['files' => $files])->json('files');
            }

            public function upload(string $container, string $path, string $sha256, $stream): array
            {
                $this->uploaded[] = $path;
                $contents = stream_get_contents($stream);

                return $this->test->post('/!/core-web/sync/assets', [
                    'container' => $container, 'path' => $path, 'sha256' => $sha256,
                    'file' => UploadedFile::fake()->createWithContent(basename($path), $contents),
                ], ['Accept' => 'application/json'])->json();
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
}

final class SignsInPusher
{
    public static ?Closure $user = null;

    public function handle(Request $request, Closure $next): mixed
    {
        $user = self::$user ? (self::$user)() : null;

        if ($user) {
            auth()->setUser($user);
        }

        return $next($request);
    }
}
