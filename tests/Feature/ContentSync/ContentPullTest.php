<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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
        config(['core-web.content_sync.tokens.disk' => 'local']);
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
            if ($answer = $this->loginAnswer($request)) {
                return $answer;
            }

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

        $this->pull()->expectsOutputToContain('php artisan content:login')->assertFailed();
    }

    public function test_a_refused_login_is_forgotten(): void
    {
        $this->app->make(SyncTokens::class)->revoke(app(SyncTokens::class)->all()[0]);

        $this->pull()->expectsOutputToContain('content:login')->assertFailed();

        $this->assertNull(RemoteSite::fromStorage());
        $this->assertFileDoesNotExist(RemoteSite::loginFile());
    }

    public function test_in_a_terminal_a_refused_login_signs_in_again_and_pulls(): void
    {
        app(SyncTokens::class)->revoke(app(SyncTokens::class)->all()[0]);
        $this->approveLoginsWith($this->tokenFor($this->cmsUser('boss@example.com', super: true)));

        $this->artisan('content:pull')
            ->expectsOutputToContain('refused this machine\'s login; signing in again')
            ->assertSuccessful();

        $this->assertSame(1, $this->signIns());
        $this->assertFileExists($this->file('collections/pages/home.md'));
    }

    public function test_a_site_that_refuses_even_a_fresh_login_stops_after_one_sign_in(): void
    {
        app(SyncTokens::class)->revoke(app(SyncTokens::class)->all()[0]);
        $this->approveLoginsWith('a-token-this-site-never-issued');

        $this->artisan('content:pull')->expectsOutputToContain('refused even the login just made')->assertFailed();

        $this->assertSame(1, $this->signIns());
        $this->assertFileDoesNotExist($this->file('collections/pages/home.md'));
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

    public function test_a_pull_deletes_nothing_the_puller_may_not_read(): void
    {
        $this->pull()->assertSuccessful();
        $this->roles(['editor' => ['access cp', 'pull content', 'view pages entries']]);
        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('ann@example.com', roles: ['editor'])), ['name' => 'Ann', 'email' => 'ann@example.com'], null);
        Entry::find('about')->delete();

        $this->pull()->expectsOutputToContain('Kept, not in the export')->expectsOutputToContain('collections/articles/first.md')->assertSuccessful();

        $this->assertFileDoesNotExist($this->file('collections/pages/about.md'), 'Pages are readable in full, so a deletion there is one.');
        $this->assertFileExists($this->file('collections/articles/first.md'));
        $this->assertFileExists($this->file('globals/default/settings.yaml'));
        $this->assertArrayHasKey('collections/articles/first.md', $this->locked()['items']);
        $this->assertArrayHasKey('globals/default/settings.yaml', $this->locked()['items']);
    }

    public function test_an_export_without_coverage_deletes_nothing(): void
    {
        $this->pull()->assertSuccessful();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['data' => [], 'next_cursor' => null])]);

        $this->pull()->assertSuccessful();

        $this->assertFileExists($this->file('collections/pages/home.md'));
    }

    public function test_a_first_pull_takes_over_files_as_committed(): void
    {
        $dir = dirname($this->root);
        File::ensureDirectoryExists($this->file('collections/pages'));
        file_put_contents($this->file('collections/pages/home.md'), "---\ntitle: Committed\n---\n");
        file_put_contents($this->file('collections/pages/about.md'), "---\ntitle: Committed\n---\n");
        $git = fn (string ...$args) => Process::path($dir)->run(['git', '-c', 'user.email=dev@example.com', '-c', 'user.name=Dev', ...$args])->throw();
        $git('init', '-q');
        $git('add', '.');
        $git('commit', '-qm', 'Content');
        file_put_contents($this->file('collections/pages/about.md'), "---\ntitle: Mine\n---\n");

        $this->pull()->expectsOutputToContain('Skipped')->assertSuccessful();

        $this->assertSame(Entry::find('home')->fileContents(), file_get_contents($this->file('collections/pages/home.md')), 'Unchanged since the commit, so the site\'s version wins.');
        $this->assertStringContainsString('title: Mine', file_get_contents($this->file('collections/pages/about.md')));
    }

    /** @param  array<string, string>  $files  path => contents, as a site on the Eloquent driver exports them */
    private function fakeEloquentExport(array $files): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([
            'data' => collect($files)->map(fn (string $contents, string $path) => ['type' => 'entries', 'id' => $path, 'path' => $path, 'contents' => $contents, 'hash' => hash('sha256', $contents)])->values()->all(),
            'next_cursor' => null,
            'covers_all' => false,
            'covers' => [],
        ])]);
        Collection::make('pages')->structureContents(['root' => true])->save();
    }

    private function local(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->file($path)));
        file_put_contents($this->file($path), $contents);
    }

    public function test_a_save_stamp_key_order_or_a_parent_alone_is_no_change(): void
    {
        $mine = "---\nid: home\ntitle: Home\nupdated_by: ann\nupdated_at: 1700000000\ntemplate: default\n---\nHello *world*.\n";
        $this->local('collections/pages/home.md', $mine);
        $this->fakeEloquentExport(['collections/pages/home.md' => "---\nid: home\ntemplate: default\ntitle: Home\nparent: about\nupdated_by: importer\nupdated_at: 1760000000\n---\nHello *world*.\n"]);

        $this->pull(['--only' => 'entries:pages'])->expectsOutputToContain('Already up to date')->expectsOutputToContain('0 new, 0 changed')->assertSuccessful();

        $this->assertSame($mine, file_get_contents($this->file('collections/pages/home.md')));
        $this->assertSame(hash('sha256', $mine), $this->locked()['items']['collections/pages/home.md']);
        $this->assertSame(0, app(LocalContent::class)->refreshes);

        $this->pull(['--only' => 'entries:pages'])->expectsOutputToContain('Already up to date')->assertSuccessful();
        $this->assertSame($mine, file_get_contents($this->file('collections/pages/home.md')));
    }

    public function test_a_real_change_is_written_in_this_file_s_key_order_with_the_site_s_stamps(): void
    {
        $mine = "---\nid: home\ntitle: Home\nupdated_by: ann\nupdated_at: 1700000000\ntemplate: default\n---\nHello *world*.\n";
        $this->local('collections/pages/home.md', $mine);
        file_put_contents($this->lock, json_encode(['remote' => 'https://remote.test', 'items' => ['collections/pages/home.md' => hash('sha256', $mine)]]));
        $this->fakeEloquentExport(['collections/pages/home.md' => "---\nid: home\ntemplate: default\ntitle: Welcome\nhero: big\nparent: about\npublished: false\nupdated_by: importer\nupdated_at: 1760000000\n---\nHello *world*.\n"]);

        $this->pull(['--only' => 'entries:pages'])->expectsOutputToContain('Changed')->expectsOutputToContain('0 new, 1 changed')->assertSuccessful();

        $written = file_get_contents($this->file('collections/pages/home.md'));
        $this->assertSame("---\nid: home\npublished: false\ntitle: Welcome\nupdated_by: importer\nupdated_at: 1760000000\ntemplate: default\nhero: big\n---\nHello *world*.\n", $written);
        $this->assertSame(hash('sha256', $written), $this->locked()['items']['collections/pages/home.md']);

        $this->pull(['--only' => 'entries:pages'])->expectsOutputToContain('Already up to date')->assertSuccessful();
    }

    public function test_a_file_changed_here_stays_skipped_when_the_site_changed_only_its_stamps(): void
    {
        $pulled = "---\nid: home\ntitle: Home\nupdated_at: 1700000000\n---\n";
        $mine = "---\nid: home\ntitle: Mine\nupdated_at: 1700000000\n---\n";
        $this->local('collections/pages/home.md', $mine);
        file_put_contents($this->lock, json_encode(['remote' => 'https://remote.test', 'items' => ['collections/pages/home.md' => hash('sha256', $pulled)]]));
        $this->fakeEloquentExport(['collections/pages/home.md' => "---\nid: home\nupdated_at: 1760000000\ntitle: Home\n---\n"]);

        $this->pull(['--only' => 'entries:pages'])->expectsOutputToContain('Skipped')->expectsOutputToContain('collections/pages/home.md')->assertSuccessful();

        $this->assertSame($mine, file_get_contents($this->file('collections/pages/home.md')));
        $this->assertSame(hash('sha256', $pulled), $this->locked()['items']['collections/pages/home.md'], 'The lock keeps the last pulled hash, so the file stays a local edit.');
    }

    public function test_a_change_to_the_body_alone_is_written(): void
    {
        $mine = "---\nid: home\ntitle: Home\n---\nHello.\n";
        $this->local('collections/pages/home.md', $mine);
        file_put_contents($this->lock, json_encode(['remote' => 'https://remote.test', 'items' => ['collections/pages/home.md' => hash('sha256', $mine)]]));
        $this->fakeEloquentExport(['collections/pages/home.md' => "---\nid: home\ntitle: Home\n---\nGoodbye.\n"]);

        $this->pull(['--only' => 'entries:pages'])->expectsOutputToContain('0 new, 1 changed')->assertSuccessful();

        $this->assertSame("---\nid: home\ntitle: Home\n---\nGoodbye.\n", file_get_contents($this->file('collections/pages/home.md')));
    }

    public function test_a_dry_run_of_a_stamp_only_difference_reports_nothing(): void
    {
        $mine = "---\nid: home\ntitle: Home\nupdated_at: 1700000000\n---\n";
        $this->local('collections/pages/home.md', $mine);
        $this->fakeEloquentExport(['collections/pages/home.md' => "---\nid: home\nupdated_at: 1760000000\ntitle: Home\nparent: about\n---\n"]);

        $this->pull(['--only' => 'entries:pages', '--dry-run' => true])
            ->expectsOutputToContain('Already up to date')
            ->doesntExpectOutputToContain('Changed')
            ->doesntExpectOutputToContain('Skipped')
            ->assertSuccessful();

        $this->assertSame($mine, file_get_contents($this->file('collections/pages/home.md')));
        $this->assertFileDoesNotExist($this->lock);
    }

    public function test_a_new_entry_of_a_structured_collection_is_written_without_its_parent(): void
    {
        $this->fakeEloquentExport([
            'collections/pages/about.md' => "---\nid: about\ntitle: About\nparent: home\n---\n",
            'collections/articles/note.md' => "---\nid: note\ntitle: Note\nparent: home\n---\n",
        ]);

        $this->pull(['--only' => 'entries'])->expectsOutputToContain('2 new')->assertSuccessful();

        $this->assertSame("---\nid: about\ntitle: About\n---\n", file_get_contents($this->file('collections/pages/about.md')));
        $this->assertStringContainsString('parent: home', file_get_contents($this->file('collections/articles/note.md')), 'Without a structure there is no tree to hold it: there it is a field.');
    }

    public function test_a_symlinked_file_is_neither_written_nor_deleted(): void
    {
        $outside = dirname($this->root).'/outside.md';
        file_put_contents($outside, 'mine');
        File::ensureDirectoryExists($this->file('collections/pages'));
        symlink($outside, $this->file('collections/pages/home.md'));

        $this->pull(['--force' => true])->expectsOutputToContain('is a symlink')->assertFailed();

        $this->assertSame('mine', file_get_contents($outside));
        $this->assertFileDoesNotExist($this->lock);
    }

    public function test_a_rate_limit_is_reported(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '42'])]);

        $this->pull()->expectsOutputToContain('rate limiting this machine (HTTP 429); try again in 42 seconds')->assertFailed();
        $this->assertNotNull(RemoteSite::fromStorage());
    }

    public function test_a_redirect_names_the_url_to_set(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 301, ['Location' => 'https://www.remote.test/!/core-web/sync/export?type=entries'])]);

        $this->pull()->expectsOutputToContain('redirects to [https://www.remote.test]; set that URL')->assertFailed();
        $this->assertNotNull(RemoteSite::fromStorage());
    }

    public function test_a_401_from_in_front_of_the_site_keeps_the_login(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('<h1>Authorization Required</h1>', 401, ['WWW-Authenticate' => 'Basic realm="staging"'])]);

        $this->pull()->expectsOutputToContain('basic auth')->assertFailed();
        $this->assertNotNull(RemoteSite::fromStorage());
    }
}
