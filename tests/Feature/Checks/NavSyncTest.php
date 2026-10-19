<?php

namespace Inly\CoreWeb\Tests\Feature\Checks;

use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Checks\Checks\NavSync;
use Inly\CoreWeb\Checks\ContentChecks;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class NavSyncTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['drafts', 'checks'];

    /** @var array<string, string> source page ids */
    private array $sv = [];

    /** @var array<string, string> translated page ids */
    private array $en = [];

    private UserContract $editor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Blueprint::setDirectory(sys_get_temp_dir().'/core-web-nav-sync-'.uniqid());
        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'default' => ['name' => 'Svenska', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'english' => ['name' => 'English', 'url' => 'http://default.test/en/', 'locale' => 'en_US'],
        ]);
        config([
            'core-web.checks.checks' => [NavSync::class],
            'core-web.checks.sync.sites' => ['english' => 'default'],
        ]);

        Collection::make('pages')->sites(['default', 'english'])->routes('{parent_uri}/{slug}')->structureContents(['root' => true])->save();

        foreach (['home' => 'Hem', 'about' => 'Om oss', 'team' => 'Teamet', 'contact' => 'Kontakt'] as $slug => $title) {
            $entry = Entry::make()->collection('pages')->locale('default')->slug($slug)->data(['title' => $title])->published(true);
            $entry->save();
            $this->sv[$slug] = $entry->id();
        }

        foreach (['home' => 'Home', 'about' => 'About', 'team' => 'Team'] as $slug => $title) {
            $localized = Entry::find($this->sv[$slug])->makeLocalization('english')->data(['title' => $title]);
            $localized->save();
            $this->en[$slug] = $localized->id();
        }

        Collection::find('pages')->structure()->in('default')->tree([
            ['entry' => $this->sv['home']],
            ['entry' => $this->sv['about'], 'children' => [['entry' => $this->sv['team']]]],
            ['entry' => $this->sv['contact']],
        ])->save();
        Collection::find('pages')->structure()->in('english')->tree([
            ['entry' => $this->en['home']],
            ['entry' => $this->en['team']],
            ['entry' => $this->en['about']],
        ])->save();

        Blueprint::make('main')->setNamespace('navigation')->setContents(['fields' => [
            ['handle' => 'sync', 'field' => ['type' => 'toggle']],
        ]])->save();
        $nav = Nav::make('main')->title('Main')->collections(['pages'])->maxDepth(3);
        $nav->save();
        $nav->makeTree('default', [
            ['id' => 'sv-1', 'entry' => $this->sv['about']],
            ['id' => 'sv-2', 'url' => '/shop', 'title' => 'Butik'],
            ['id' => 'sv-3', 'title' => 'Mer', 'children' => [
                ['id' => 'sv-4', 'entry' => $this->sv['contact']],
                ['id' => 'sv-5', 'entry' => $this->sv['team']],
            ]],
        ])->save();
        $nav->makeTree('english', [
            ['id' => 'en-1', 'url' => '/shop', 'title' => 'Shop'],
            ['id' => 'en-2', 'entry' => $this->en['about'], 'title' => 'About us'],
            ['id' => 'en-3', 'url' => 'https://jobs.example.com', 'title' => 'Careers', 'data' => ['sync' => false]],
            ['id' => 'en-4', 'url' => '/old', 'title' => 'Old'],
        ])->save();

        $this->editor = User::make()->email('editor@example.com')->data(['name' => 'Editor'])->makeSuper();
        $this->editor->save();
    }

    private function author(): DraftAuthor
    {
        return new DraftAuthor($this->editor, 'Editor', DraftProvenance::make('content-check'), 'Synced');
    }

    /** @return array<string, array<string, mixed>> */
    private function findings(): array
    {
        $result = app(ContentChecks::class)->run(app(NavSync::class));

        return collect($result['findings'])->keyBy('subject')->all();
    }

    public function test_it_finds_menus_and_page_trees_that_differ_from_the_source(): void
    {
        $findings = $this->findings();

        $this->assertSame(['nav:main:english', 'tree:pages:english'], array_keys($findings));
        $this->assertTrue($findings['nav:main:english']['fixable']);
        $this->assertSame('english', $findings['nav:main:english']['site']);
        $this->assertSame(['Kontakt'], $findings['nav:main:english']['details']['left_out']);
        $this->assertStringContainsString('Svenska', $findings['tree:pages:english']['message']);
    }

    public function test_the_fix_proposes_the_mirrored_menu_keeping_what_the_translation_owns(): void
    {
        $proposal = app(ContentChecks::class)->fix('nav-sync', $this->findings()['nav:main:english']['id'], $this->author());
        $value = app(Proposals::class)->find($proposal['id'])->value;

        $this->assertSame([
            ['id' => 'en-2', 'entry' => $this->en['about'], 'title' => 'About us'],
            ['id' => 'en-1', 'url' => '/shop', 'title' => 'Shop'],
            ['title' => 'Mer', 'children' => [['entry' => $this->en['team']]]],
            ['id' => 'en-3', 'url' => 'https://jobs.example.com', 'title' => 'Careers', 'data' => ['sync' => false]],
        ], array_map(fn (array $branch) => self::withoutNewIds($branch), $value));

        app(Proposals::class)->apply($proposal['id'], $this->editor);

        $this->assertArrayNotHasKey('nav:main:english', $this->findings());
    }

    public function test_the_fix_proposes_the_mirrored_page_tree_keeping_pages_only_the_translation_has(): void
    {
        $jobs = Entry::make()->collection('pages')->locale('english')->slug('jobs')->data(['title' => 'Jobs'])->published(true);
        $jobs->save();
        Collection::find('pages')->structure()->in('english')->tree([
            ['entry' => $this->en['home']],
            ['entry' => $this->en['team'], 'children' => [['entry' => $jobs->id()]]],
            ['entry' => $this->en['about']],
        ])->save();

        $proposal = app(ContentChecks::class)->fix('nav-sync', $this->findings()['tree:pages:english']['id'], $this->author());

        $this->assertSame([
            ['entry' => $this->en['home']],
            ['entry' => $this->en['about'], 'children' => [['entry' => $this->en['team'], 'children' => [['entry' => $jobs->id()]]]]],
        ], app(Proposals::class)->find($proposal['id'])->value);

        app(Proposals::class)->apply($proposal['id'], $this->editor);

        $this->assertArrayNotHasKey('tree:pages:english', $this->findings());
    }

    public function test_it_finds_nothing_until_a_site_is_named_a_translation(): void
    {
        config(['core-web.checks.sync.sites' => []]);

        $this->assertSame([], $this->findings());
    }

    public function test_it_compares_only_the_navigations_and_trees_named(): void
    {
        config(['core-web.checks.sync.navs' => [], 'core-web.checks.sync.trees' => ['pages']]);

        $this->assertSame(['tree:pages:english'], array_keys($this->findings()));
    }

    public function test_content_check_fixes_as_the_named_user(): void
    {
        $this->artisan('content:check', ['check' => ['nav-sync'], '--fix' => true, '--as' => 'editor@example.com'])->assertSuccessful();

        $this->assertSame(['nav:main:english', 'tree:pages:english'], collect(app(Proposals::class)->open())->pluck('target')->sort()->values()->all());
    }

    public function test_content_check_needs_a_user_to_fix_as(): void
    {
        $this->artisan('content:check', ['check' => ['nav-sync'], '--fix' => true])->assertFailed();
        $this->artisan('content:check', ['check' => ['nav-sync'], '--fix' => true, '--as' => 'nobody@example.com'])->assertFailed();

        $this->assertSame([], app(Proposals::class)->open());
    }

    public function test_the_mirror_lists_a_page_once_and_leaves_out_links_no_proposal_may_add(): void
    {
        Nav::find('main')->in('default')->tree([
            ['id' => 'sv-1', 'entry' => $this->sv['about']],
            ['id' => 'sv-2', 'entry' => $this->sv['about']],
            ['id' => 'sv-3', 'url' => 'www.example.com', 'title' => 'Utan schema'],
        ])->save();

        $finding = $this->findings()['nav:main:english'];
        $proposal = app(ContentChecks::class)->fix('nav-sync', $finding['id'], $this->author());

        $this->assertSame(['Utan schema'], $finding['details']['left_out']);
        $this->assertSame([
            ['id' => 'en-2', 'entry' => $this->en['about'], 'title' => 'About us'],
            ['id' => 'en-3', 'url' => 'https://jobs.example.com', 'title' => 'Careers', 'data' => ['sync' => false]],
        ], app(Proposals::class)->find($proposal['id'])->value);
    }

    public function test_a_heading_marked_to_stay_is_not_taken_for_a_source_heading(): void
    {
        Nav::find('main')->in('english')->tree([
            ['id' => 'en-5', 'title' => 'Partners', 'data' => ['sync' => false]],
        ])->save();

        $proposal = app(ContentChecks::class)->fix('nav-sync', $this->findings()['nav:main:english']['id'], $this->author());
        $value = array_map(fn (array $branch) => self::withoutNewIds($branch), app(Proposals::class)->find($proposal['id'])->value);

        $this->assertSame('Mer', $value[2]['title']);
        $this->assertSame(['id' => 'en-5', 'title' => 'Partners', 'data' => ['sync' => false]], $value[3]);
    }

    public function test_a_mirror_the_target_would_refuse_is_reported_for_an_editor(): void
    {
        Nav::find('main')->maxDepth(1)->save();

        $finding = $this->findings()['nav:main:english'];

        $this->assertFalse($finding['fixable']);
        $this->assertNotEmpty($finding['details']['refused']);
    }

    public function test_content_check_does_not_fix_with_json(): void
    {
        $this->artisan('content:check', ['check' => ['nav-sync'], '--fix' => true, '--json' => true, '--as' => 'editor@example.com'])->assertFailed();
    }

    /**
     * Branches the source added get their id when the proposal is validated; it is random, so it is left out of the comparison.
     *
     * @param  array<string, mixed>  $branch
     * @return array<string, mixed>
     */
    private static function withoutNewIds(array $branch): array
    {
        if (isset($branch['id']) && ! str_starts_with((string) $branch['id'], 'en-')) {
            unset($branch['id']);
        }

        if (isset($branch['children'])) {
            $branch['children'] = array_map(fn (array $child) => self::withoutNewIds($child), $branch['children']);
        }

        return $branch;
    }
}
