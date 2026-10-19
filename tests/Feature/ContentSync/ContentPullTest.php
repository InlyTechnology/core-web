<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Inly\CoreWeb\ContentSync\LocalContent;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\SyncTokens;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** Both halves in one app: the checkout's calls to the remote are answered by this app's own sync routes. */
class ContentPullTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['content_sync'];

    private string $root;

    private string $lock;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Site::setSites(['default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE']]);
        config(['core-web.content_sync.remote_url' => 'https://remote.test']);

        $dir = sys_get_temp_dir().'/core-web-pull-'.uniqid();
        $this->root = $dir.'/content';
        $this->lock = $dir.'/content.lock';
        File::ensureDirectoryExists($this->root);
        // This app is the remote too, so a refresh from the checkout's files would replace the remote's content.
        $this->app->instance(LocalContent::class, new class($this->root, $this->lock) extends LocalContent
        {
            public int $refreshes = 0;

            public function refreshStache(): void
            {
                $this->refreshes++;
            }
        });

        Collection::make('pages')->save();
        Collection::make('articles')->save();

        foreach (['home', 'about'] as $slug) {
            Entry::make()->id($slug)->collection('pages')->locale('default')->slug($slug)->data(['title' => ucfirst($slug)])->save();
        }

        Entry::make()->id('first')->collection('articles')->locale('default')->slug('first')->data(['title' => 'First'])->published(false)->save();

        $set = GlobalSet::make('settings')->title('Settings');
        $set->save();
        $set->in('default')->data(['phone' => '123'])->save();

        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('boss@example.com', super: true)), ['name' => 'Boss', 'email' => 'boss@example.com'], now()->addDays(30)->toIso8601String());
        $this->routeRemoteToThisApp();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->root));
        RemoteSite::forget();

        parent::tearDown();
    }

    private function routeRemoteToThisApp(): void
    {
        Http::fake(function (Request $request) {
            $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

            if ($request->hasHeader('Authorization')) {
                $server['HTTP_AUTHORIZATION'] = $request->header('Authorization')[0];
            }

            $response = $this->call($request->method(), $request->url(), [], [], [], $server, $request->body());

            return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
        });
    }

    private function pull(array $options = []): PendingCommand
    {
        return $this->artisan('content:pull', ['--no-interaction' => true, ...$options]);
    }

    private function file(string $path): string
    {
        return $this->root.'/'.$path;
    }

    private function locked(): array
    {
        return json_decode(file_get_contents($this->lock), true);
    }

    public function test_a_pull_writes_the_site_s_content_and_the_lock(): void
    {
        $this->pull()->expectsOutputToContain('collections/pages/home.md')->assertSuccessful();

        $this->assertSame(Entry::find('home')->fileContents(), file_get_contents($this->file('collections/pages/home.md')));
        $this->assertFileExists($this->file('collections/articles/first.md'));
        $this->assertSame("phone: '123'\n", file_get_contents($this->file('globals/default/settings.yaml')));

        $lock = $this->locked();
        $this->assertSame('https://remote.test', $lock['remote']);
        $this->assertSame(hash_file('sha256', $this->file('collections/pages/home.md')), $lock['items']['collections/pages/home.md']);
        $this->assertSame(['collections/articles/first.md', 'collections/pages/about.md', 'collections/pages/home.md', 'globals/default/settings.yaml'], array_keys($lock['items']));

        $this->assertSame(1, app(LocalContent::class)->refreshes);

        $this->pull()->expectsOutputToContain('Already up to date')->assertSuccessful();
        $this->assertSame(1, app(LocalContent::class)->refreshes, 'Nothing written, nothing to refresh.');
    }

    public function test_a_change_on_the_site_replaces_an_unchanged_local_file(): void
    {
        $this->pull()->assertSuccessful();

        Entry::find('home')->set('title', 'Welcome')->save();

        $this->pull()->expectsOutputToContain('Changed')->assertSuccessful();
        $this->assertStringContainsString('title: Welcome', file_get_contents($this->file('collections/pages/home.md')));
    }

    public function test_a_local_edit_is_kept_unless_forced(): void
    {
        $this->pull()->assertSuccessful();
        $locked = $this->locked()['items']['collections/pages/home.md'];

        file_put_contents($this->file('collections/pages/home.md'), "---\ntitle: Mine\n---\n");
        Entry::find('home')->set('title', 'Welcome')->save();

        $this->pull()->expectsOutputToContain('Skipped')->expectsOutputToContain('collections/pages/home.md')->assertSuccessful();
        $this->assertStringContainsString('title: Mine', file_get_contents($this->file('collections/pages/home.md')));
        $this->assertSame($locked, $this->locked()['items']['collections/pages/home.md'], 'The lock still holds the last pulled hash, so the file stays a local edit.');

        $this->pull(['--force' => true])->assertSuccessful();
        $this->assertStringContainsString('title: Welcome', file_get_contents($this->file('collections/pages/home.md')));
    }

    public function test_a_local_file_never_pulled_is_a_local_edit_too(): void
    {
        File::ensureDirectoryExists($this->file('collections/pages'));
        file_put_contents($this->file('collections/pages/home.md'), "---\ntitle: Mine\n---\n");

        $this->pull()->expectsOutputToContain('Skipped')->assertSuccessful();

        $this->assertStringContainsString('title: Mine', file_get_contents($this->file('collections/pages/home.md')));
        $this->assertArrayNotHasKey('collections/pages/home.md', $this->locked()['items']);
    }

    public function test_an_item_deleted_on_the_site_goes_only_when_unchanged_here(): void
    {
        $this->pull()->assertSuccessful();
        file_put_contents($this->file('collections/pages/about.md'), "---\ntitle: Mine\n---\n");

        Entry::find('home')->delete();
        Entry::find('about')->delete();

        $this->pull()->expectsOutputToContain('Deleted')->expectsOutputToContain('Kept')->assertSuccessful();

        $this->assertFileDoesNotExist($this->file('collections/pages/home.md'));
        $this->assertFileExists($this->file('collections/pages/about.md'));
        $this->assertArrayNotHasKey('collections/pages/home.md', $this->locked()['items']);

        $this->pull(['--force' => true])->assertSuccessful();
        $this->assertFileDoesNotExist($this->file('collections/pages/about.md'));
    }

    public function test_only_narrows_what_is_pulled_and_deleted(): void
    {
        $this->pull()->assertSuccessful();
        Entry::find('first')->delete();
        Entry::find('home')->set('title', 'Welcome')->save();

        $this->pull(['--only' => 'entries:pages'])->assertSuccessful();

        $this->assertStringContainsString('title: Welcome', file_get_contents($this->file('collections/pages/home.md')));
        $this->assertFileExists($this->file('collections/articles/first.md'), 'Another collection is out of the pull, so its deletions are too.');
        $this->assertArrayHasKey('collections/articles/first.md', $this->locked()['items']);
        $this->assertArrayHasKey('globals/default/settings.yaml', $this->locked()['items']);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->pull(['--dry-run' => true])->expectsOutputToContain('New')->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertFileDoesNotExist($this->file('collections/pages/home.md'));
        $this->assertFileDoesNotExist($this->lock);
    }

    public function test_a_path_out_of_content_stops_the_pull(): void
    {
        foreach (['../.env', 'collections/../../.env', '/etc/passwd', 'globals/default/settings.yaml'] as $path) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['data' => [['type' => 'entries', 'id' => 'x', 'path' => $path, 'contents' => 'APP_KEY=stolen', 'hash' => hash('sha256', 'APP_KEY=stolen')]], 'next_cursor' => null])]);

            $this->pull(['--only' => 'entries'])->expectsOutputToContain('no path of its own inside content/')->assertFailed();
        }

        $this->assertFileDoesNotExist(dirname($this->root).'/.env');
        $this->assertSame([], File::allFiles($this->root));
        $this->assertFileDoesNotExist($this->lock);
    }

    public function test_a_symlinked_folder_does_not_carry_a_write_out_of_content(): void
    {
        $outside = dirname($this->root).'/outside';
        File::ensureDirectoryExists($outside);
        symlink($outside, $this->root.'/collections');

        $this->pull(['--only' => 'entries'])->expectsOutputToContain('leads outside content/')->assertFailed();

        $this->assertSame([], File::allFiles($outside));
        $this->assertFileDoesNotExist($this->lock);
    }

    public function test_a_locked_path_out_of_content_is_never_deleted(): void
    {
        file_put_contents(dirname($this->root).'/keep.txt', 'mine');
        file_put_contents($this->lock, json_encode(['remote' => 'https://remote.test', 'items' => ['../keep.txt' => hash('sha256', 'mine')]]));

        $this->pull()->assertSuccessful();

        $this->assertFileExists(dirname($this->root).'/keep.txt');
        $this->assertArrayNotHasKey('../keep.txt', $this->locked()['items']);
    }

    public function test_without_a_login_it_asks_for_one(): void
    {
        RemoteSite::forget();

        $this->pull()->expectsOutputToContain('php please content:login')->assertFailed();
    }

    public function test_a_refused_login_is_forgotten(): void
    {
        $this->app->make(SyncTokens::class)->revoke(app(SyncTokens::class)->all()[0]);

        $this->pull()->expectsOutputToContain('content:login')->assertFailed();

        $this->assertNull(RemoteSite::fromStorage());
        $this->assertFileDoesNotExist(RemoteSite::loginFile());
    }

    public function test_a_login_to_another_site_is_no_login(): void
    {
        config(['core-web.content_sync.remote_url' => 'https://elsewhere.test']);

        $this->assertNull(RemoteSite::fromStorage());
        $this->pull()->expectsOutputToContain('not signed in to https://elsewhere.test')->assertFailed();
    }

    public function test_it_runs_only_locally(): void
    {
        $this->app['env'] = 'production';

        $this->pull()->expectsOutputToContain('APP_ENV is local')->assertFailed();
        $this->assertFileDoesNotExist($this->lock);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->pull(['--only' => 'users'])->assertFailed();
        $this->pull(['--site' => 'nowhere'])->assertFailed();
    }

    public function test_the_stored_login_is_readable_by_this_user_only(): void
    {
        $this->assertSame('0600', substr(sprintf('%o', fileperms(RemoteSite::loginFile())), -4));
        $this->assertSame('boss@example.com', RemoteSite::fromStorage()->user()['email']);
    }
}
