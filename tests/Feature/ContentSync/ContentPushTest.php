<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Inly\CoreWeb\ContentSync\LocalContent;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\Targets\CollectionTreeTarget;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Events\EntrySaved;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
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

    public function test_a_type_a_push_does_not_carry_is_refused(): void
    {
        $this->push(['--only' => 'assets'])->expectsOutputToContain('is not pushed')->assertFailed();
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

    /** @param  array<string, mixed>  $structure */
    private function docs(array $structure = ['max_depth' => 3]): void
    {
        config(['core-web.drafts.collections' => ['pages' => [], 'articles' => [], 'docs' => ['structured' => true]]]);
        $docs = Collection::make('docs')->sites(['default'])->revisionsEnabled(true)->structureContents($structure);
        $docs->save();
        Blueprint::make('doc')->setNamespace('collections.docs')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]]]])->save();

        foreach (['intro', 'setup'] as $slug) {
            Entry::make()->id($slug)->collection('docs')->locale('default')->slug($slug)->data(['title' => ucfirst($slug)])->save();
        }

        $docs->structure()->makeTree('default', [['entry' => 'intro'], ['entry' => 'setup']])->save();
    }

    private function tree(string $handle): array
    {
        return Collection::find($handle)->structure()->in('default')->tree();
    }

    public function test_a_page_tree_is_a_proposal_and_applied_with_publish(): void
    {
        $this->docs();
        $this->pull()->assertSuccessful();
        $this->assertFileExists($this->root.'/trees/collections/docs.yaml');
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: setup\n  -\n    entry: intro\n");

        $this->push()->expectsOutputToContain('proposal')->assertSuccessful();
        $this->assertSame('intro', $this->tree('docs')[0]['entry'], 'A proposal changes nothing yet.');
        $this->assertSame('tree:docs:default', app(Proposals::class)->open()[0]->target);

        config(['core-web.content_sync.push_publish' => true]);
        $this->push(['--publish' => true])->expectsOutputToContain('1 published or applied')->assertSuccessful();
        $this->assertSame('setup', $this->tree('docs')[0]['entry']);
        $this->assertArrayHasKey('trees/collections/docs.yaml', json_decode(file_get_contents($this->lock), true)['published']);
    }

    public function test_a_new_entry_sits_in_the_page_tree_pushed_with_it(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->docs();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/collections/docs/faq.md', "---\nid: faq\nblueprint: doc\ntitle: FAQ\n---\n");
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: setup\n  -\n    entry: intro\n    children:\n      -\n        entry: faq\n");

        $this->push(['--publish' => true])->expectsOutputToContain('2 published or applied, 0 left')->assertSuccessful();

        $this->assertTrue(Entry::find('faq')->published());
        $tree = $this->tree('docs');
        $this->assertSame('setup', $tree[0]['entry']);
        $this->assertSame('faq', $tree[1]['children'][0]['entry']);
    }

    public function test_a_page_tree_changed_on_the_site_since_the_pull_is_refused(): void
    {
        $this->docs();
        $this->pull()->assertSuccessful();
        Collection::find('docs')->structure()->in('default')->tree([['entry' => 'setup'], ['entry' => 'intro']])->save();
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: intro\n    children:\n      -\n        entry: setup\n");

        $this->push()->expectsOutputToContain('base_changed')->assertFailed();
    }

    private function tags(): void
    {
        Taxonomy::make('tags')->sites(['default'])->save();
        Term::make()->taxonomy('tags')->slug('red')->dataForLocale('default', ['title' => 'Red'])->save();
    }

    public function test_a_term_change_is_a_proposal_and_applied_with_publish(): void
    {
        $this->tags();
        $this->pull()->assertSuccessful();
        $this->edit('taxonomies/tags/red.yaml', 'title: Red', 'title: Crimson');

        $this->push()->expectsOutputToContain('proposal')->assertSuccessful();
        $this->assertSame('Red', Term::find('tags::red')->title());

        config(['core-web.content_sync.push_publish' => true]);
        $this->push(['--publish' => true])->expectsOutputToContain('1 published or applied')->assertSuccessful();
        $this->assertSame('Crimson', Term::find('tags::red')->title());
    }

    public function test_a_new_term_is_created_only_by_a_push_that_publishes(): void
    {
        $this->tags();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/taxonomies/tags/blue.yaml', "title: Blue\n");

        $this->push()->expectsOutputToContain('Push it with --publish')->assertFailed();
        $this->assertNull(Term::find('tags::blue'));

        config(['core-web.content_sync.push_publish' => true]);
        $this->push(['--publish' => true])->expectsOutputToContain('1 published or applied')->assertSuccessful();
        $this->assertSame('Blue', Term::find('tags::blue')->title());
    }

    public function test_creating_a_term_needs_the_person_s_create_permission(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->tags();
        $this->roles(['editor' => ['access cp', 'pull content', 'push content', 'view tags terms', 'edit tags terms', 'access default site']]);
        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('pia@example.com', roles: ['editor'])), ['name' => 'Pia', 'email' => 'pia@example.com'], now()->addDays(30)->toIso8601String());
        $this->pull(['--only' => 'terms'])->assertSuccessful();
        file_put_contents($this->root.'/taxonomies/tags/blue.yaml', "title: Blue\n");

        $this->push(['--publish' => true, '--only' => 'terms'])->expectsOutputToContain('not_permitted')->assertFailed();
        $this->assertNull(Term::find('tags::blue'));
    }

    public function test_a_page_tree_the_placing_left_unchanged_is_recorded_so_the_next_push_passes(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->docs();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/collections/docs/faq.md', "---\nid: faq\nblueprint: doc\ntitle: FAQ\n---\n");
        // Exactly where the writer places a new entry under its parent: the tree is then what the site holds.
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: intro\n    children:\n      -\n        entry: faq\n  -\n    entry: setup\n");

        $this->push(['--publish' => true])->expectsOutputToContain('unchanged')->assertSuccessful();
        $lock = json_decode(file_get_contents($this->lock), true);
        $this->assertArrayHasKey('trees/collections/docs.yaml', $lock['published']);

        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: setup\n  -\n    entry: intro\n    children:\n      -\n        entry: faq\n");
        $this->push(['--publish' => true])->expectsOutputToContain('1 published or applied')->assertSuccessful();
        $this->assertSame('setup', $this->tree('docs')[0]['entry']);
    }

    public function test_an_editor_s_change_to_the_tree_while_the_push_writes_is_not_adopted(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->docs();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/collections/docs/faq.md', "---\nid: faq\nblueprint: doc\ntitle: FAQ\n---\n");
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: intro\n    children:\n      -\n        entry: faq\n  -\n    entry: setup\n");
        Event::listen(EntrySaved::class, function (EntrySaved $event): void {
            if ($event->entry->id() === 'faq') {
                $tree = Collection::find('docs')->structure()->in('default');
                $tree->tree([['entry' => 'setup'], ...array_values(array_filter($tree->tree(), fn ($branch) => $branch['entry'] !== 'setup'))])->save();
            }
        });

        $this->push(['--publish' => true])->expectsOutputToContain('base_changed')->assertFailed();

        $this->assertSame('setup', $this->tree('docs')[0]['entry'], 'The editor\'s order stands.');
        $this->assertFalse(Entry::find('faq')->published(), 'A refusal while writing publishes nothing.');
    }

    public function test_a_tree_refused_after_the_push_placed_its_new_entries_is_recorded_at_the_site_s_version(): void
    {
        $this->docs();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/collections/docs/faq.md', "---\nid: faq\nblueprint: doc\ntitle: FAQ\n---\n");
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: setup\n  -\n    entry: intro\n    children:\n      -\n        entry: faq\n");
        $other = $this->cmsUser('eva@example.com', super: true);
        Event::listen(EntrySaved::class, function (EntrySaved $event) use ($other): void {
            $target = CollectionTreeTarget::make('docs', 'default');

            if ($event->entry->id() === 'faq' && app(Proposals::class)->open() === []) {
                app(Proposals::class)->propose(new DraftAuthor($other, 'Eva', DraftProvenance::make('test'), 'Mine'), $target, [['entry' => 'setup'], ['entry' => 'intro', 'children' => [['entry' => 'faq']]]], Proposals::base($target));
            }
        });

        $pulled = json_decode(file_get_contents($this->lock), true)['bases']['trees/collections/docs.yaml'];

        $this->push()->expectsOutputToContain('[held]')->assertFailed();
        $this->assertNotSame($pulled, json_decode(file_get_contents($this->lock), true)['bases']['trees/collections/docs.yaml']);

        app(Proposals::class)->discard(app(Proposals::class)->open()[0]->id, $other);
        $this->push()->expectsOutputToContain('proposal')->assertSuccessful();
    }

    public function test_a_tree_holding_new_entries_is_refused_while_someone_else_holds_a_proposal_on_it(): void
    {
        $this->docs();
        $this->pull()->assertSuccessful();
        $other = $this->cmsUser('eva@example.com', super: true);
        $target = CollectionTreeTarget::make('docs', 'default');
        app(Proposals::class)->propose(new DraftAuthor($other, 'Eva', DraftProvenance::make('test'), 'Mine'), $target, [['entry' => 'setup'], ['entry' => 'intro']], Proposals::base($target));
        file_put_contents($this->root.'/collections/docs/faq.md', "---\nid: faq\nblueprint: doc\ntitle: FAQ\n---\n");
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: intro\n    children:\n      -\n        entry: faq\n  -\n    entry: setup\n");

        $this->push()->expectsOutputToContain('[held]')->expectsOutputToContain('Nothing was pushed')->assertFailed();
        $this->assertNull(Entry::find('faq'));
    }

    public function test_a_tree_holding_new_entries_is_still_checked_for_what_it_drops(): void
    {
        $this->docs();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/collections/docs/faq.md', "---\nid: faq\nblueprint: doc\ntitle: FAQ\n---\n");
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: intro\n    children:\n      -\n        entry: faq\n");

        $this->push()->expectsOutputToContain('leaves out entries')->assertFailed();
        $this->assertNull(Entry::find('faq'));
    }

    public function test_a_page_tree_needs_the_reorder_permission(): void
    {
        $this->docs();
        $this->roles(['editor' => ['access cp', 'pull content', 'push content', 'view docs entries', 'edit docs entries', 'access default site']]);
        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('pia@example.com', roles: ['editor'])), ['name' => 'Pia', 'email' => 'pia@example.com'], now()->addDays(30)->toIso8601String());
        $this->pull(['--only' => 'collection-trees'])->assertSuccessful();
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: setup\n  -\n    entry: intro\n");

        $this->push(['--only' => 'collection-trees'])->expectsOutputToContain('not_permitted')->assertFailed();
        $this->assertSame([], app(Proposals::class)->open());
    }

    public function test_a_term_edit_needs_the_edit_permission(): void
    {
        $this->tags();
        $this->roles(['viewer' => ['access cp', 'pull content', 'push content', 'view tags terms', 'access default site']]);
        RemoteSite::store('https://remote.test', $this->tokenFor($this->cmsUser('pia@example.com', roles: ['viewer'])), ['name' => 'Pia', 'email' => 'pia@example.com'], now()->addDays(30)->toIso8601String());
        $this->pull(['--only' => 'terms'])->assertSuccessful();
        $this->edit('taxonomies/tags/red.yaml', 'title: Red', 'title: Crimson');

        $this->push(['--only' => 'terms'])->expectsOutputToContain('not_permitted')->assertFailed();
        $this->assertSame([], app(Proposals::class)->open());
    }

    public function test_a_new_term_that_fails_validation_is_refused(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->tags();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/taxonomies/tags/blue.yaml', "title: ''\n");

        $this->push(['--publish' => true])->expectsOutputToContain('validation')->assertFailed();
        $this->assertNull(Term::find('tags::blue'));
    }

    public function test_a_new_term_leaves_out_hidden_and_unknown_values_and_names_them(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->tags();
        Blueprint::make('tag')->setNamespace('taxonomies.tags')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
            ['handle' => 'kind', 'field' => ['type' => 'text']],
            ['handle' => 'detail', 'field' => ['type' => 'text', 'validate' => ['integer'], 'if' => ['kind' => 'equals long']]],
            ['handle' => 'rows', 'field' => ['type' => 'grid', 'fields' => [
                ['handle' => 'kind', 'field' => ['type' => 'text']],
                ['handle' => 'note', 'field' => ['type' => 'text', 'validate' => ['integer'], 'if' => ['kind' => 'equals long']]],
            ]]],
        ]])->save();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/taxonomies/tags/blue.yaml', "title: Blue\nkind: short\ndetail: abc\ncolour: blue\nrows:\n  -\n    kind: short\n    note: abc\n");

        $this->push(['--publish' => true])->expectsOutputToContain('Left out, as the site does not take them: colour, detail, rows.0.note.')->assertSuccessful();

        $term = Term::find('tags::blue');
        $this->assertSame('short', $term->get('kind'));
        $this->assertNull($term->get('detail'));
        $this->assertNull($term->get('colour'));
        $this->assertArrayNotHasKey('note', $term->get('rows')[0]);
    }

    public function test_a_tree_holding_a_new_entry_past_the_tree_s_depth_is_refused_by_the_check(): void
    {
        $this->docs(['max_depth' => 2]);
        Collection::find('docs')->structure()->in('default')->tree([['entry' => 'intro', 'children' => [['entry' => 'setup']]]])->save();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/collections/docs/faq.md', "---\nid: faq\nblueprint: doc\ntitle: FAQ\n---\n");
        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: intro\n    children:\n      -\n        entry: setup\n        children:\n          -\n            entry: faq\n");

        $this->push()->expectsOutputToContain('at most 2 levels deep')->assertFailed();
        $this->assertNull(Entry::find('faq'));
    }

    public function test_a_tree_holding_a_new_entry_before_or_under_the_home_page_is_refused_by_the_check(): void
    {
        $this->docs(['root' => true, 'max_depth' => 3]);
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/collections/docs/faq.md', "---\nid: faq\nblueprint: doc\ntitle: FAQ\n---\n");

        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: faq\n  -\n    entry: intro\n  -\n    entry: setup\n");
        $this->push()->expectsOutputToContain('The first page is the home page')->assertFailed();

        file_put_contents($this->root.'/trees/collections/docs.yaml', "tree:\n  -\n    entry: intro\n    children:\n      -\n        entry: faq\n  -\n    entry: setup\n");
        $this->push()->expectsOutputToContain('The home page holds no pages under it')->assertFailed();

        $this->assertNull(Entry::find('faq'));
    }

    public function test_a_new_term_s_file_is_named_by_its_slug(): void
    {
        config(['core-web.content_sync.push_publish' => true]);
        $this->tags();
        $this->pull()->assertSuccessful();
        file_put_contents($this->root.'/taxonomies/tags/Light Blue.yaml', "title: Light blue\n");

        $this->push(['--publish' => true])->expectsOutputToContain('light-blue.yaml')->assertFailed();
    }

    public function test_a_term_s_blueprint_is_not_changed_by_a_push(): void
    {
        $this->tags();
        $this->pull()->assertSuccessful();
        $this->edit('taxonomies/tags/red.yaml', 'title: Red', "title: Red\nblueprint: colour");

        $this->push()->expectsOutputToContain('another blueprint')->assertFailed();
    }

    public function test_paths_push_only_the_files_they_name_and_leave_the_rest_for_later(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');
        $this->edit('collections/pages/about.md', 'title: About', 'title: Also mine');

        $this->push(['paths' => ['content/collections/pages/home.md']])->assertSuccessful();
        $this->assertTrue(Entry::find('home')->hasWorkingCopy());
        $this->assertFalse(Entry::find('about')->hasWorkingCopy());

        $this->push()->expectsOutputToContain('collections/pages/about.md')->assertSuccessful();
        $this->assertTrue(Entry::find('about')->hasWorkingCopy());
    }

    public function test_a_path_is_a_file_a_folder_or_a_pattern(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');
        $this->edit('collections/pages/about.md', 'title: About', 'title: Also mine');
        $this->edit('globals/default/settings.yaml', "'123'", "'456'");

        $this->assertSame(['collections/pages/about.md'], $this->statusPaths(['paths' => ['collections/*/about.md']]));
        $this->assertSame(['collections/pages/about.md', 'collections/pages/home.md'], $this->statusPaths(['paths' => ['content/collections/pages/']]));
        $this->assertSame(['collections/pages/home.md', 'globals/default/settings.yaml'], $this->statusPaths(['paths' => ['./collections/pages/home.md', 'globals']]));
    }

    public function test_a_path_that_names_no_pushable_file_refuses_the_push(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push(['paths' => ['collections/pages/hom.md']])->expectsOutputToContain('No file a push carries matches [collections/pages/hom.md]')->assertFailed();
        $this->push(['paths' => ['../.env']])->expectsOutputToContain('is no file or folder under content/')->assertFailed();
        $this->push(['paths' => ['collections/pages/home.md'], '--only' => 'globals'])->expectsOutputToContain('No file a push carries matches')->assertFailed();
        $this->assertFalse(Entry::find('home')->hasWorkingCopy());
    }

    public function test_a_path_to_an_unchanged_file_pushes_nothing(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->push(['paths' => ['collections/pages/about.md']])->expectsOutputToContain('Nothing to push')->assertSuccessful();
        $this->assertFalse(Entry::find('home')->hasWorkingCopy());
    }

    public function test_status_lists_what_a_push_sends_and_what_waits_for_an_editor(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');
        File::ensureDirectoryExists($this->root.'/collections/articles');
        file_put_contents($this->root.'/collections/articles/news.md', "---\nid: news-1\nblueprint: article\ntitle: News\n---\n");

        Artisan::call('content:status', ['--json' => true]);
        $status = json_decode(Artisan::output(), true);
        $this->assertTrue($status['ok']);
        $this->assertSame('https://remote.test', $status['remote']);
        $this->assertSame([
            ['path' => 'collections/articles/news.md', 'type' => 'entries', 'state' => 'new', 'draft_on_site' => false, 'assets' => []],
            ['path' => 'collections/pages/home.md', 'type' => 'entries', 'state' => 'changed', 'draft_on_site' => false, 'assets' => []],
        ], $status['push']);
        $this->assertSame([], $status['waiting']);

        $this->push(['paths' => ['collections/pages/home.md']])->assertSuccessful();

        $this->artisan('content:status')
            ->expectsOutputToContain('sends 1 file(s)')
            ->expectsOutputToContain('collections/articles/news.md')
            ->expectsOutputToContain('Pushed and waiting for an editor in the CP (1)')
            ->assertSuccessful();
        $this->assertSame(['collections/articles/news.md', 'collections/pages/home.md'], $this->statusPaths(['--publish' => true]));
    }

    public function test_status_before_a_pull_says_so(): void
    {
        Artisan::call('content:status', ['--json' => true]);

        $this->assertSame(false, json_decode(Artisan::output(), true)['ok']);
        $this->artisan('content:status')->expectsOutputToContain('run php artisan content:pull first')->assertFailed();
    }

    public function test_staged_and_committed_follow_git(): void
    {
        $this->pull()->assertSuccessful();
        $this->git(['init', '-q']);
        $this->git(['add', '.']);
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-qm', 'pulled']);
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');
        $this->edit('collections/pages/about.md', 'title: About', 'title: Also mine');
        $this->git(['add', 'collections/pages/about.md']);

        $this->assertSame(['collections/pages/about.md'], $this->statusPaths(['--staged' => true]));
        $this->assertSame([], $this->statusPaths(['--committed' => true]));

        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-qm', 'about']);
        $this->assertSame(['collections/pages/about.md'], $this->statusPaths(['--committed' => true]));

        $this->freshLocal();
        $this->push(['--committed' => true])->assertSuccessful();
        $this->assertTrue(Entry::find('about')->hasWorkingCopy());
        $this->assertFalse(Entry::find('home')->hasWorkingCopy());
    }

    public function test_staged_and_committed_need_git_and_not_both(): void
    {
        $this->pull()->assertSuccessful();

        $this->push(['--staged' => true])->expectsOutputToContain('not in a git checkout')->assertFailed();

        $this->git(['init', '-q']);
        $this->freshLocal();
        $this->push(['--staged' => true, '--committed' => true])->expectsOutputToContain('not both')->assertFailed();
    }

    public function test_json_prints_one_document_with_the_site_s_answer(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');

        $this->assertSame(0, Artisan::call('content:push', ['--json' => true]));
        $out = json_decode(Artisan::output(), true);

        $this->assertTrue($out['ok']);
        $this->assertFalse($out['dry_run']);
        $this->assertTrue($out['written']);
        $this->assertSame(['collections/pages/home.md'], $out['files']);
        $this->assertSame('working_copy', $out['results'][0]['status']);
        $this->assertContains('To push:', $out['messages']);
    }

    public function test_json_never_asks_for_a_login(): void
    {
        $this->pull()->assertSuccessful();
        $this->edit('collections/pages/home.md', 'title: Home', 'title: Mine');
        RemoteSite::forget();

        $this->assertSame(1, Artisan::call('content:push', ['--json' => true]));
        $out = json_decode(Artisan::output(), true);

        $this->assertFalse($out['ok']);
        $this->assertSame([], $out['results']);
        $this->assertStringContainsString('run php artisan content:login', implode("\n", $out['messages']));
    }

    /** @return list<string> the paths content:status lists for the options */
    private function statusPaths(array $options = []): array
    {
        $this->freshLocal();
        Artisan::call('content:status', ['--json' => true, ...$options]);

        return array_column(json_decode(Artisan::output(), true)['push'] ?? [], 'path');
    }

    /** LocalContent reads git once per instance, as one command run does. */
    private function freshLocal(): void
    {
        $this->app->instance(LocalContent::class, new class($this->root, $this->lock) extends LocalContent
        {
            public function refreshStache(): void {}
        });
    }

    private function git(array $arguments): void
    {
        Process::path($this->root)->run(['git', ...$arguments])->throw();
    }
}
