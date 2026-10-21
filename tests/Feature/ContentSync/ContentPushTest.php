<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Inly\CoreWeb\ContentSync\LocalContent;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Events\EntrySaved;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** Both halves in one app: the checkout's push is answered by this app's own sync routes, as the pull is. */
class ContentPushTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['content_sync', 'drafts'];

    private string $scratch;

    private string $root;

    private string $lock;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->scratch = sys_get_temp_dir().'/core-web-push-'.uniqid();
        $this->root = $this->scratch.'/content';
        $this->lock = $this->scratch.'/content.lock';
        File::ensureDirectoryExists($this->root);

        config([
            'core-web.content_sync.tokens.disk' => 'local',
            'core-web.content_sync.remote_url' => 'https://remote.test',
            'core-web.drafts.collections' => ['pages' => [], 'articles' => []],
            'core-web.drafts.proposals.disk' => 'local',
            'statamic.revisions.enabled' => true,
            'statamic.revisions.path' => $this->scratch.'/revisions',
        ]);
        Site::setSites(['default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE']]);
        Blueprint::setDirectory($this->scratch.'/blueprints');

        $this->app->instance(LocalContent::class, new class($this->root, $this->lock) extends LocalContent
        {
            public function refreshStache(): void {}
        });

        Collection::make('pages')->sites(['default'])->revisionsEnabled(true)->save();
        Collection::make('articles')->sites(['default'])->revisionsEnabled(true)->save();
        $fields = [['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]], ['handle' => 'lead', 'field' => ['type' => 'text']]];
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => $fields])->save();
        Blueprint::make('article')->setNamespace('collections.articles')->setContents(['fields' => $fields])->save();

        foreach (['home', 'about'] as $slug) {
            Entry::make()->id($slug)->collection('pages')->locale('default')->slug($slug)->data(['title' => ucfirst($slug)])->save();
        }

        $set = GlobalSet::make('settings')->title('Settings');
        $set->save();
        $set->in('default')->data(['phone' => '123'])->save();
        Blueprint::make('settings')->setNamespace('globals')->setContents(['fields' => [['handle' => 'phone', 'field' => ['type' => 'text']]]])->save();

        $this->roles([
            'puller' => ['access cp', 'pull content', 'view pages entries', 'edit pages entries', 'access default site'],
        ]);

        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('boss@example.com', super: true)), ['name' => 'Boss', 'email' => 'boss@example.com'], now()->addDays(30)->toIso8601String());
        $this->routeRemoteToThisApp();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
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

    private function push(array $options = []): PendingCommand
    {
        return $this->artisan('content:push', ['--no-interaction' => true, ...$options]);
    }

    private function edit(string $path, string $from, string $to): void
    {
        $file = $this->root.'/'.$path;
        file_put_contents($file, str_replace($from, $to, file_get_contents($file)));
    }

    public function test_an_edited_entry_becomes_a_working_copy_and_the_live_entry_stays(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Welcome home');

        $this->push()->expectsOutputToContain('working_copy')->expectsOutputToContain('collections/pages/home.md')->assertSuccessful();

        $home = Entry::find('home');
        $this->assertSame('Home', $home->get('title'));
        $this->assertTrue($home->hasWorkingCopy());
        $this->assertSame('Welcome home', $home->fromWorkingCopy()->get('title'));

        $copy = $home->workingCopy();
        $this->assertSame('Pushed by Boss from ann@laptop', $copy->message());
        $this->assertSame('content-push', DraftProvenance::via($copy));
        $this->assertSame('ann@laptop', DraftProvenance::of($copy)['machine']);

        $lock = json_decode(file_get_contents($this->lock), true);
        $this->assertSame(hash_file('sha256', $this->root.'/collections/pages/home.md'), $lock['pushed']['collections/pages/home.md']);
        $this->assertNotSame($lock['pushed']['collections/pages/home.md'], $lock['items']['collections/pages/home.md'], 'The pulled version stays what the lock holds: nothing went live.');

        $this->push()->expectsOutputToContain('Nothing to push')->assertSuccessful();
    }

    public function test_a_change_on_the_site_since_the_pull_refuses_the_push_unless_forced(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');
        Entry::find('home')->set('title', 'Theirs')->save();

        $this->push()->expectsOutputToContain('base_changed')->expectsOutputToContain('Nothing was pushed')->assertFailed();
        $this->assertFalse(Entry::find('home')->hasWorkingCopy());

        $this->push(['--force' => true])->assertSuccessful();
        $this->assertSame('Mine', Entry::find('home')->fromWorkingCopy()->get('title'));
        $this->assertSame('Theirs', Entry::find('home')->get('title'));
    }

    public function test_one_refused_file_stops_the_whole_push_unless_partial(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');
        $this->edit('collections/pages/about.md', 'title: About', 'title: Also mine');
        Entry::find('about')->set('title', 'Theirs')->save();

        $this->push()->assertFailed();
        $this->assertFalse(Entry::find('home')->hasWorkingCopy(), 'All or nothing: the file that passed is not written either.');

        $this->push(['--partial' => true])->expectsOutputToContain('1 refused')->assertFailed();
        $this->assertSame('Mine', Entry::find('home')->fromWorkingCopy()->get('title'));
        $this->assertFalse(Entry::find('about')->hasWorkingCopy());
    }

    public function test_a_new_entry_is_saved_unpublished_with_the_checkout_s_id(): void
    {
        $this->pull()->assertSuccessful();
        File::ensureDirectoryExists($this->root.'/collections/articles');
        file_put_contents($this->root.'/collections/articles/news.md', "---\nid: news-1\nblueprint: article\ntitle: News\n---\n");

        $this->push()->expectsOutputToContain('draft_entry')->assertSuccessful();

        $entry = Entry::find('news-1');
        $this->assertNotNull($entry);
        $this->assertFalse($entry->published());
        $this->assertSame('news', $entry->slug());
        $this->assertSame('News', $entry->get('title'));
    }

    public function test_a_global_change_is_a_proposal(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('globals/default/settings.yaml', "'123'", "'456'");

        $this->push()->expectsOutputToContain('proposal')->assertSuccessful();

        $open = app(Proposals::class)->open();
        $this->assertCount(1, $open);
        $this->assertSame('global:settings:default', $open[0]->target);
        $this->assertSame(['phone' => '456'], $open[0]->value);
        $this->assertSame('123', GlobalSet::find('settings')->in('default')->get('phone'), 'An editor applies it; nothing changed yet.');
    }

    public function test_a_global_set_the_site_lacks_is_refused_not_failed(): void
    {
        $this->pull()->assertSuccessful();
        @mkdir($this->root.'/globals/default', 0777, true);
        file_put_contents($this->root.'/globals/default/missing.yaml', "phone: '1'\n");

        $this->push(['--partial' => true])->expectsOutputToContain('not_found')->assertFailed();
        $this->assertSame([], app(Proposals::class)->open());
    }

    public function test_a_dry_run_checks_and_writes_nothing(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push(['--dry-run' => true])->expectsOutputToContain('working_copy')->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertFalse(Entry::find('home')->hasWorkingCopy());
        $this->assertArrayNotHasKey('pushed', json_decode(file_get_contents($this->lock), true));
    }

    public function test_an_invalid_value_is_refused(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', "title: ''");

        $this->push()->expectsOutputToContain('refused')->assertFailed();
        $this->assertFalse(Entry::find('home')->hasWorkingCopy());
    }

    public function test_a_push_without_a_pull_first_says_so(): void
    {
        $this->push()->expectsOutputToContain('content:pull first')->assertFailed();
    }

    public function test_the_page_order_and_other_types_are_not_pushed(): void
    {
        $this->push(['--only' => 'collection-trees'])->expectsOutputToContain('is not pushed')->assertFailed();
    }

    public function test_pushing_needs_the_push_permission(): void
    {
        $token = $this->tokenFor($this->cmsUser('pia@example.com', roles: ['puller']));

        $this->withToken($token)->postJson('/!/core-web/sync/push', ['items' => [['path' => 'collections/pages/home.md', 'contents' => "---\nid: home\ntitle: X\n---\n"]]])
            ->assertForbidden()
            ->assertJson(['error' => 'forbidden']);
    }

    public function test_a_site_that_takes_no_push_says_so(): void
    {
        config(['core-web.content_sync.push' => false]);
        $token = $this->tokenFor($this->cmsUser('pia@example.com', super: true));

        $this->withToken($token)->postJson('/!/core-web/sync/push', ['items' => []])->assertNotFound()->assertJson(['error' => 'push_off']);
    }

    public function test_an_asset_is_stored_only_where_the_site_has_nothing(): void
    {
        $disk = $this->scratch.'/photos';
        File::ensureDirectoryExists($disk.'/team');
        file_put_contents($disk.'/team/old.jpg', 'old bytes');
        config(['filesystems.disks.photos' => ['driver' => 'local', 'root' => $disk, 'url' => '/photos']]);
        AssetContainer::make('photos')->disk('photos')->save();
        $token = $this->tokenFor($this->cmsUser('pia@example.com', super: true));

        $upload = fn (string $path, string $bytes) => $this->withToken($token)->post('/!/core-web/sync/push/asset', [
            'container' => 'photos',
            'path' => $path,
            'alt' => 'Anna',
            'file' => UploadedFile::fake()->createWithContent(basename($path), $bytes),
        ], ['Accept' => 'application/json']);

        $upload('team/anna.jpg', 'anna bytes')->assertOk()->assertJson(['status' => 'stored']);
        $this->assertSame('anna bytes', file_get_contents($disk.'/team/anna.jpg'));
        $this->assertSame('Anna', AssetContainer::find('photos')->asset('team/anna.jpg')->get('alt'));

        $upload('team/anna.jpg', 'anna bytes')->assertOk()->assertJson(['status' => 'same']);
        $upload('team/old.jpg', 'new bytes')->assertStatus(409)->assertJson(['status' => 'conflict']);
        $this->assertSame('old bytes', file_get_contents($disk.'/team/old.jpg'), 'Never overwritten.');

        foreach (['../escape.jpg', 'team/.meta/anna.jpg.yaml', 'noextension'] as $path) {
            $upload($path, 'x')->assertStatus(422)->assertJson(['status' => 'invalid']);
        }

        $this->assertFileDoesNotExist($this->scratch.'/escape.jpg');
    }

    public function test_an_item_naming_an_asset_the_site_holds_otherwise_is_refused(): void
    {
        $disk = $this->scratch.'/photos';
        File::ensureDirectoryExists($disk);
        file_put_contents($disk.'/anna.jpg', 'theirs');
        config(['filesystems.disks.photos' => ['driver' => 'local', 'root' => $disk, 'url' => '/photos']]);
        AssetContainer::make('photos')->disk('photos')->save();
        $this->pull()->assertSuccessful();
        $token = $this->tokenFor($this->cmsUser('pia@example.com', super: true));
        $base = json_decode(file_get_contents($this->lock), true)['bases']['collections/pages/home.md'];

        $this->withToken($token)->postJson('/!/core-web/sync/push', [
            'items' => [['path' => 'collections/pages/home.md', 'contents' => "---\nid: home\ntitle: With photo\n---\n", 'base' => $base, 'assets' => ['photos::anna.jpg']]],
            'assets' => [['container' => 'photos', 'path' => 'anna.jpg', 'hash' => hash('sha256', 'mine')]],
        ])->assertOk()->assertJson(['written' => false, 'results' => [['status' => 'refused', 'error' => 'asset_refused']], 'assets' => [['status' => 'conflict']]]);

        $this->assertFalse(Entry::find('home')->hasWorkingCopy());
    }

    public function test_an_asset_is_refused_when_its_path_is_not_an_uploadable_file_of_its_kind(): void
    {
        $disk = $this->scratch.'/photos';
        File::ensureDirectoryExists($disk);
        config(['filesystems.disks.photos' => ['driver' => 'local', 'root' => $disk, 'url' => '/photos']]);
        AssetContainer::make('photos')->disk('photos')->save();
        $token = $this->tokenFor($this->cmsUser('pia@example.com', super: true));

        foreach (['shell.php' => 'a.jpg', 'x.html' => 'x.html', 'photo.jpg' => 'photo.png'] as $path => $name) {
            $this->withToken($token)->post('/!/core-web/sync/push/asset', [
                'container' => 'photos',
                'path' => $path,
                'file' => UploadedFile::fake()->createWithContent($name, '<?php echo 1;'),
            ], ['Accept' => 'application/json'])->assertStatus(422)->assertJson(['status' => 'invalid']);

            $this->assertFileDoesNotExist($disk.'/'.$path);
        }

        $this->withToken($token)->postJson('/!/core-web/sync/push', [
            'items' => [['path' => 'collections/pages/home.md', 'contents' => "---\nid: home\ntitle: X\n---\n", 'assets' => ['photos::shell.php']]],
            'assets' => [['container' => 'photos', 'path' => 'shell.php', 'hash' => hash('sha256', 'x')]],
            'dry_run' => true,
        ])->assertOk()->assertJson(['assets' => [['status' => 'invalid']]]);
    }

    public function test_two_files_of_one_entry_refuse_the_push(): void
    {
        $this->pull()->assertSuccessful();
        $token = $this->tokenFor($this->cmsUser('pia@example.com', super: true));
        $base = json_decode(file_get_contents($this->lock), true)['bases']['collections/pages/home.md'];
        $item = ['path' => 'collections/pages/home.md', 'contents' => "---\nid: home\ntitle: One\n---\n", 'base' => $base];

        $this->withToken($token)->postJson('/!/core-web/sync/push', ['items' => [$item, $item]])->assertStatus(422);

        $this->withToken($token)->postJson('/!/core-web/sync/push', ['items' => [$item, [...$item, 'path' => 'collections/pages/other.md', 'contents' => "---\nid: home\ntitle: Two\n---\n"]], 'partial' => true])
            ->assertOk()
            ->assertJson(['results' => [['error' => 'duplicate'], ['error' => 'duplicate']]]);

        $this->assertFalse(Entry::find('home')->hasWorkingCopy());
    }

    public function test_a_working_copy_made_on_the_site_since_the_push_is_not_overwritten(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Pushed');
        $this->push()->assertSuccessful();

        $this->edit('collections/pages/home.md', 'title: Pushed', 'title: Pushed again');
        $this->push()->expectsOutputToContain('working_copy')->assertSuccessful();
        $this->assertSame('Pushed again', Entry::find('home')->fromWorkingCopy()->get('title'), 'A push over its own last push passes.');

        $home = Entry::find('home');
        $home->fromWorkingCopy()->set('title', 'Edited in the CP')->makeWorkingCopy()->user(User::findByEmail('boss@example.com'))->save();
        $this->edit('collections/pages/home.md', 'title: Pushed again', 'title: Pushed a third time');

        $this->push()->expectsOutputToContain('[working_copy]')->expectsOutputToContain('Nothing was pushed')->assertFailed();
        $this->assertSame('Edited in the CP', Entry::find('home')->fromWorkingCopy()->get('title'));
    }

    public function test_an_open_working_copy_the_checkout_never_pushed_is_refused(): void
    {
        $this->pull()->assertSuccessful();
        $home = Entry::find('home');
        $home->fromWorkingCopy()->set('title', 'Edited in the CP')->makeWorkingCopy()->user(User::findByEmail('boss@example.com'))->save();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push()->expectsOutputToContain('working_copy')->expectsOutputToContain('Nothing was pushed')->assertFailed();
        $this->assertSame('Edited in the CP', Entry::find('home')->fromWorkingCopy()->get('title'));
    }

    public function test_a_push_never_sets_an_entry_s_author(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', "title: Mine\nauthor: someone-else");

        $this->push()->assertSuccessful();

        $this->assertNull(Entry::find('home')->fromWorkingCopy()->get('author'));
    }

    public function test_a_push_too_large_in_all_is_refused(): void
    {
        config(['core-web.content_sync.push_bytes' => 10]);
        $token = $this->tokenFor($this->cmsUser('pia@example.com', super: true));

        $this->withToken($token)->postJson('/!/core-web/sync/push', ['items' => [['path' => 'collections/pages/home.md', 'contents' => "---\nid: home\ntitle: X\n---\n"]]])
            ->assertStatus(413)
            ->assertJson(['error' => 'too_large']);
    }

    public function test_forcing_files_pulled_without_a_base_warns(): void
    {
        $this->pull()->assertSuccessful();
        $lock = json_decode(file_get_contents($this->lock), true);
        unset($lock['bases']);
        file_put_contents($this->lock, json_encode($lock));
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push(['--force' => true])->expectsOutputToContain('pulled before the lock kept bases')->assertSuccessful();
    }

    public function test_publishing_needs_the_site_to_allow_it(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push(['--publish' => true])->expectsOutputToContain('does not publish pushed content')->assertFailed();
        $this->assertFalse(Entry::find('home')->hasWorkingCopy());
    }

    public function test_a_published_push_goes_live_and_the_next_push_needs_no_pull(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Live now');
        $this->edit('globals/default/settings.yaml', "'123'", "'456'");
        File::ensureDirectoryExists($this->root.'/collections/articles');
        file_put_contents($this->root.'/collections/articles/news.md', "---\nid: news-1\nblueprint: article\ntitle: News\n---\n");

        $this->push(['--publish' => true])
            ->expectsOutputToContain('3 published or applied, 0 left for an editor')
            ->assertSuccessful();

        $home = Entry::find('home');
        $this->assertSame('Live now', $home->get('title'));
        $this->assertFalse($home->hasWorkingCopy());
        $this->assertTrue(Entry::find('news-1')->published());
        $this->assertSame('456', GlobalSet::find('settings')->in('default')->get('phone'));
        $this->assertSame([], app(Proposals::class)->open());
        $this->assertSame('publish', $home->revisions()->last()->action());

        $this->edit('collections/pages/home.md', 'title: Live now', 'title: Live again');
        $this->edit('globals/default/settings.yaml', "'456'", "'789'");
        $this->push(['--publish' => true])->expectsOutputToContain('2 published or applied')->assertSuccessful();
        $this->assertSame('Live again', Entry::find('home')->get('title'));
        $this->assertSame('789', GlobalSet::find('settings')->in('default')->get('phone'));
    }

    public function test_what_the_person_may_not_publish_stays_a_draft(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->roles([
            'editor' => ['access cp', 'pull content', 'push content', 'view pages entries', 'edit pages entries', 'access default site'],
        ]);
        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('pia@example.com', roles: ['editor'])), ['name' => 'Pia', 'email' => 'pia@example.com'], now()->addDays(30)->toIso8601String());
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push(['--publish' => true, '--only' => 'entries'])
            ->expectsOutputToContain('working_copy')
            ->expectsOutputToContain('0 published or applied, 1 left for an editor')
            ->assertSuccessful();

        $home = Entry::find('home');
        $this->assertSame('Home', $home->get('title'));
        $this->assertSame('Mine', $home->fromWorkingCopy()->get('title'));
    }

    public function test_a_partial_publish_makes_only_the_written_files_live(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');
        $this->edit('collections/pages/about.md', 'title: About', 'title: Also mine');
        Entry::find('about')->set('title', 'Theirs')->save();

        $this->push(['--publish' => true, '--partial' => true])->expectsOutputToContain('1 refused')->assertFailed();

        $this->assertSame('Mine', Entry::find('home')->get('title'));
        $this->assertSame('Theirs', Entry::find('about')->get('title'));
    }

    public function test_an_entry_unpublished_in_the_checkout_is_never_published(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        Entry::make()->id('hidden')->collection('pages')->locale('default')->slug('hidden')->published(false)
            ->data(['title' => 'Hidden', 'updated_by' => User::findByEmail('boss@example.com')->id()])->save();
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/hidden.md', 'title: Hidden', 'title: Still hidden');
        File::ensureDirectoryExists($this->root.'/collections/articles');
        file_put_contents($this->root.'/collections/articles/news.md', "---\nid: news-1\nblueprint: article\npublished: false\ntitle: News\n---\n");

        $this->push(['--publish' => true])
            ->expectsOutputToContain('Unpublished in the checkout')
            ->expectsOutputToContain('0 published or applied, 2 left for an editor')
            ->assertSuccessful();

        $this->assertFalse(Entry::find('news-1')->published());
        $this->assertFalse(Entry::find('hidden')->published());
        $this->assertSame('Still hidden', Entry::find('hidden')->get('title'));
    }

    public function test_a_later_publish_resends_what_was_only_drafted(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Drafted first');

        $this->push()->assertSuccessful();
        $this->assertSame('Home', Entry::find('home')->get('title'));

        $this->push(['--publish' => true])->expectsOutputToContain('1 published or applied')->assertSuccessful();
        $this->assertSame('Drafted first', Entry::find('home')->get('title'));

        $this->push(['--publish' => true])->expectsOutputToContain('Nothing to push')->assertSuccessful();
    }

    public function test_a_draft_left_for_want_of_permission_is_published_once_it_is_granted(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $editor = ['access cp', 'pull content', 'push content', 'view pages entries', 'edit pages entries', 'access default site'];
        $this->roles(['editor' => $editor]);
        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('pia@example.com', roles: ['editor'])), ['name' => 'Pia', 'email' => 'pia@example.com'], now()->addDays(30)->toIso8601String());
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push(['--publish' => true, '--only' => 'entries'])->expectsOutputToContain('1 left for an editor')->assertSuccessful();
        $this->assertArrayNotHasKey('collections/pages/home.md', json_decode(file_get_contents($this->lock), true)['pushed'] ?? []);

        $this->roles(['editor' => [...$editor, 'publish pages entries']]);
        $this->push(['--publish' => true, '--only' => 'entries'])->expectsOutputToContain('1 published or applied')->assertSuccessful();
        $this->assertSame('Mine', Entry::find('home')->get('title'));
    }

    public function test_publish_and_force_together_are_refused_before_anything_is_written(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push(['--publish' => true, '--force' => true])->expectsOutputToContain('cannot be combined with --force')->assertFailed();
        $this->assertFalse(Entry::find('home')->hasWorkingCopy());
    }

    public function test_a_dry_run_publish_is_refused_while_the_site_has_it_off(): void
    {
        $token = $this->tokenFor($this->cmsUser('pia@example.com', super: true));

        $this->withToken($token)->postJson('/!/core-web/sync/push', ['dry_run' => true, 'publish' => true, 'items' => [['path' => 'collections/pages/home.md', 'contents' => "---\nid: home\ntitle: X\n---\n"]]])
            ->assertForbidden()
            ->assertJson(['error' => 'publish_off']);
    }

    public function test_an_applied_nav_tree_is_live_and_its_base_is_in_the_lock(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $nav = Nav::make('main')->title('Main')->collections(['pages']);
        $nav->save();
        $nav->makeTree('default', [['url' => 'https://example.com', 'title' => 'Shop']])->save();
        $this->pull()->assertSuccessful();
        $base = json_decode(file_get_contents($this->lock), true)['bases']['trees/navigation/main.yaml'];
        $this->edit('trees/navigation/main.yaml', 'title: Shop', 'title: Store');

        $this->push(['--publish' => true])->expectsOutputToContain('1 published or applied')->assertSuccessful();

        $this->assertSame('Store', Nav::find('main')->in('default')->tree()[0]['title']);
        $lock = json_decode(file_get_contents($this->lock), true);
        $this->assertNotSame($base, $lock['bases']['trees/navigation/main.yaml']);
        $this->assertArrayHasKey('trees/navigation/main.yaml', $lock['published']);
    }

    public function test_a_refusal_while_writing_publishes_nothing(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->pull()->assertSuccessful();
        File::ensureDirectoryExists($this->root.'/collections/articles');
        file_put_contents($this->root.'/collections/articles/news.md', "---\nid: news-1\nblueprint: article\ntitle: News\n---\n");
        $this->edit('globals/default/settings.yaml', "'123'", "'456'");
        // The site changes the global between the check and the write, so only the write refuses it.
        Event::listen(EntrySaved::class, function (EntrySaved $event): void {
            if ($event->entry->id() === 'news-1') {
                GlobalSet::find('settings')->in('default')->set('phone', '999')->save();
            }
        });

        $this->push(['--publish' => true])->expectsOutputToContain('another file in this push was refused')->assertFailed();

        $this->assertFalse(Entry::find('news-1')->published());
        $this->assertSame('999', GlobalSet::find('settings')->in('default')->get('phone'));
    }

    public function test_an_entry_missing_what_its_collection_is_published_with_stays_a_draft(): void
    {
        config(['core-web.content_sync.push_publish' => true, 'core-web.drafts.collections' => ['pages' => ['published_with' => ['lead']], 'articles' => []]]);
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push(['--publish' => true])->expectsOutputToContain('1 left for an editor')->assertSuccessful();

        $this->assertSame('Home', Entry::find('home')->get('title'));
        $this->assertSame('Mine', Entry::find('home')->fromWorkingCopy()->get('title'));
    }
}
