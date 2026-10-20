<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\ContentSync\ContentPaths;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class ContentExportTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['content_sync'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['core-web.content_sync.tokens.disk' => 'local']);
        $this->roles([
            'editor' => ['access cp', 'pull content', 'view pages entries', 'access default site', 'access other site'],
            'globals' => ['access cp', 'pull content', 'edit settings globals', 'view main nav', 'access default site', 'access other site'],
        ]);
    }

    /** @param  list<string>  $sites */
    private function content(array $sites): void
    {
        Collection::make('pages')->sites($sites)->routes('{parent_uri}/{slug}')->structureContents(['root' => true])->save();
        Collection::make('secrets')->sites($sites)->save();

        $home = Entry::make()->collection('pages')->locale('default')->slug('home')->data(['title' => 'Home'])->published(true);
        $home->save();
        Entry::make()->collection('pages')->locale('default')->slug('draft')->data(['title' => 'Draft', 'content' => 'Not *yet*.'])->published(false)->save();
        Entry::make()->collection('secrets')->locale('default')->slug('plan')->data(['title' => 'Plan'])->save();
        Collection::find('pages')->structure()->in('default')->tree([['entry' => $home->id()]])->save();

        if (in_array('other', $sites, true)) {
            Entry::make()->collection('pages')->locale('other')->slug('hem')->data(['title' => 'Hem'])->published(true)->save();
        }

        Taxonomy::make('tags')->sites($sites)->save();
        Term::make()->taxonomy('tags')->inDefaultLocale()->slug('news')->data(['title' => 'News'])->save();

        $set = GlobalSet::make('settings')->title('Settings');
        $set->sites(array_fill_keys($sites, null))->save();

        foreach ($sites as $site) {
            $set->in($site)->data(['phone' => $site === 'default' ? '123' : '456'])->save();
        }

        $nav = Nav::make('main')->title('Main')->collections(['pages']);
        $nav->save();
        $nav->makeTree('default', [['url' => 'https://example.com', 'title' => 'Shop']])->save();
    }

    private function export(string $token, string $query): array
    {
        return $this->withToken($token)->getJson('/!/core-web/sync/export?'.$query)->assertOk()->json();
    }

    /** @return array<string, string> path => contents */
    private function all(string $token, string $type, string $extra = ''): array
    {
        $items = [];
        $cursor = '0';

        while ($cursor !== null) {
            $page = $this->export($token, "type={$type}&cursor={$cursor}{$extra}");

            foreach ($page['data'] as $item) {
                $this->assertSame($type, $item['type']);
                $this->assertSame(hash('sha256', $item['contents']), $item['hash']);
                $items[$item['path']] = $item['contents'];
            }

            $cursor = $page['next_cursor'];
        }

        ksort($items);

        return $items;
    }

    public function test_a_multisite_export_lays_files_out_per_site(): void
    {
        config(['statamic.system.multisite' => true]);
        $this->content(['default', 'other']);
        $token = $this->tokenFor($this->cmsUser('boss@example.com', super: true));

        $entries = $this->all($token, 'entries');

        $this->assertSame([
            'collections/pages/default/draft.md',
            'collections/pages/default/home.md',
            'collections/pages/other/hem.md',
            'collections/secrets/default/plan.md',
        ], array_keys($entries));
        $draft = Entry::query()->where('slug', 'draft')->first();
        $this->assertSame($draft->fileContents(), $entries['collections/pages/default/draft.md']);
        $this->assertStringContainsString('published: false', $entries['collections/pages/default/draft.md']);
        $this->assertStringContainsString('Not *yet*.', $entries['collections/pages/default/draft.md']);

        $this->assertSame(['taxonomies/tags/news.yaml'], array_keys($this->all($token, 'terms')));
        $this->assertSame(['globals/default/settings.yaml', 'globals/other/settings.yaml'], array_keys($globals = $this->all($token, 'globals')));
        $this->assertSame("phone: '456'\n", $globals['globals/other/settings.yaml']);
        $this->assertSame(['trees/collections/default/pages.yaml'], array_keys($this->all($token, 'collection-trees')));
        $this->assertSame(['trees/navigation/default/main.yaml'], array_keys($nav = $this->all($token, 'nav-trees')));
        $this->assertStringContainsString('url: \'https://example.com\'', $nav['trees/navigation/default/main.yaml']);

        $this->assertSame(['collections/pages/other/hem.md'], array_keys($this->all($token, 'entries', '&site=other')));
        $this->assertSame(['collections/secrets/default/plan.md'], array_keys($this->all($token, 'entries', '&handle=secrets')));
        $this->assertSame(['globals/default/settings.yaml'], array_keys($this->all($token, 'globals', '&site=default')));
    }

    public function test_an_entry_of_a_structured_collection_is_exported_without_its_parent(): void
    {
        $this->content(['default']);
        Collection::make('articles')->save();
        // The Eloquent driver keeps an entry's parent in its data; the tree already holds it.
        $child = Entry::make()->collection('pages')->locale('default')->slug('child')->data(['title' => 'Child', 'parent' => 'home-id']);
        $child->save();
        Entry::make()->collection('articles')->locale('default')->slug('note')->data(['title' => 'Note', 'parent' => 'a field'])->save();
        $token = $this->tokenFor($this->cmsUser('boss@example.com', super: true));

        $entries = $this->all($token, 'entries');

        $this->assertSame("---\nid: {$child->id()}\nblueprint: page\ntitle: Child\n---\n", $entries['collections/pages/child.md']);
        $this->assertStringContainsString("parent: 'a field'", $entries['collections/articles/note.md'], 'A collection without a structure has no tree to hold it: there it is a field.');
    }

    public function test_a_single_site_export_has_no_site_folders_but_globals_keep_theirs(): void
    {
        Site::setSites(['default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE']]);
        $this->content(['default']);
        $token = $this->tokenFor($this->cmsUser('boss@example.com', super: true));

        $this->assertSame(['collections/pages/draft.md', 'collections/pages/home.md', 'collections/secrets/plan.md'], array_keys($this->all($token, 'entries')));
        $this->assertSame(['trees/collections/pages.yaml'], array_keys($this->all($token, 'collection-trees')));
        $this->assertSame(['trees/navigation/main.yaml'], array_keys($this->all($token, 'nav-trees')));
        $this->assertSame(['globals/default/settings.yaml'], array_keys($this->all($token, 'globals')));

        foreach (array_keys($this->all($token, 'entries')) as $path) {
            $this->assertSame(['handle' => explode('/', $path)[1], 'site' => 'default'], ContentPaths::parse('entries', $path));
        }
    }

    public function test_only_what_the_user_may_view_is_exported(): void
    {
        config(['statamic.system.multisite' => true]);
        $this->content(['default', 'other']);
        $editor = $this->tokenFor($this->cmsUser('ann@example.com', roles: ['editor']));
        $globals = $this->tokenFor($this->cmsUser('eva@example.com', roles: ['globals']));

        $this->assertSame([
            'collections/pages/default/draft.md',
            'collections/pages/default/home.md',
            'collections/pages/other/hem.md',
        ], array_keys($this->all($editor, 'entries')));
        $this->assertSame([], $this->all($editor, 'globals'));
        $this->assertSame([], $this->all($editor, 'terms'));
        $this->assertSame([], $this->all($editor, 'nav-trees'));
        $this->assertSame(['trees/collections/default/pages.yaml'], array_keys($this->all($editor, 'collection-trees')));

        $this->assertSame([], $this->all($globals, 'entries'));
        $this->assertSame(['globals/default/settings.yaml', 'globals/other/settings.yaml'], array_keys($this->all($globals, 'globals')));
        $this->assertSame(['trees/navigation/default/main.yaml'], array_keys($this->all($globals, 'nav-trees')));
    }

    public function test_the_export_is_paged(): void
    {
        Site::setSites(['default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE']]);
        config(['core-web.content_sync.page_size' => 2]);
        Collection::make('articles')->save();

        foreach (['a', 'b', 'c', 'd', 'e'] as $slug) {
            Entry::make()->collection('articles')->locale('default')->slug($slug)->data(['title' => $slug])->save();
        }

        $token = $this->tokenFor($this->cmsUser('boss@example.com', super: true));

        $first = $this->export($token, 'type=entries');
        $second = $this->export($token, 'type=entries&cursor='.$first['next_cursor']);
        $third = $this->export($token, 'type=entries&cursor='.$second['next_cursor']);

        $this->assertSame(['2', '4', null], [$first['next_cursor'], $second['next_cursor'], $third['next_cursor']]);
        $this->assertSame([2, 2, 1], [count($first['data']), count($second['data']), count($third['data'])]);
        $paths = collect([...$first['data'], ...$second['data'], ...$third['data']])->pluck('path')->sort()->values()->all();
        $this->assertSame(['collections/articles/a.md', 'collections/articles/b.md', 'collections/articles/c.md', 'collections/articles/d.md', 'collections/articles/e.md'], $paths);
    }

    public function test_an_unknown_type_or_cursor_is_refused(): void
    {
        $token = $this->tokenFor($this->cmsUser('boss@example.com', super: true));

        $this->withToken($token)->getJson('/!/core-web/sync/export?type=users')->assertStatus(422);
        $this->withToken($token)->getJson('/!/core-web/sync/export?type=blueprints')->assertStatus(422);
        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries&cursor=-1')->assertStatus(422);
    }

    public function test_paths_never_lead_out_of_content(): void
    {
        foreach (['../.env', 'collections/../../.env', '/etc/passwd', 'collections/pages/./home.md', 'collections\\pages\\home.md', 'C:/x', "collections/pages/a\0.md", ''] as $path) {
            $this->assertFalse(ContentPaths::safe($path), $path);
            $this->assertNull(ContentPaths::parse('entries', $path), $path);
        }

        $this->assertNull(ContentPaths::parse('entries', 'globals/default/settings.yaml'));
        $this->assertNull(ContentPaths::parse('globals', 'globals/settings.yaml'), 'A global set\'s own file is config, never synced.');
        $this->assertSame(['handle' => 'settings', 'site' => 'other'], ContentPaths::parse('globals', 'globals/other/settings.yaml'));
    }

    public function test_each_page_says_what_it_covers(): void
    {
        config(['statamic.system.multisite' => true]);
        $this->content(['default', 'other']);
        $this->roles(['editor' => ['access cp', 'pull content', 'view pages entries', 'access default site']]);
        $editor = $this->tokenFor($this->cmsUser('ann@example.com', roles: ['editor']));
        $boss = $this->tokenFor($this->cmsUser('boss@example.com', super: true));

        $page = $this->export($editor, 'type=entries');
        $this->assertFalse($page['covers_all']);
        $this->assertSame([['handle' => 'pages', 'site' => 'default']], $page['covers'], 'Not the other site, nor a collection the user may not view.');
        $this->assertSame(['collections/pages/default/draft.md', 'collections/pages/default/home.md'], collect($page['data'])->pluck('path')->sort()->values()->all());

        $this->assertSame([], $this->export($editor, 'type=globals')['covers']);
        $this->assertTrue($this->export($boss, 'type=entries')['covers_all']);
        $this->assertSame([['handle' => 'tags', 'site' => null]], $this->export($boss, 'type=terms&handle=tags')['covers']);
    }

    public function test_an_item_a_policy_holds_back_takes_its_scope_out_of_what_a_page_covers(): void
    {
        $this->content(['default']);
        $boss = $this->tokenFor($this->cmsUser('boss@example.com', super: true));
        Gate::before(fn ($user, string $ability, array $arguments) => $ability === 'view' && ($arguments[0] ?? null) instanceof EntryContract && $arguments[0]->slug() === 'plan' ? false : null);

        $page = $this->export($boss, 'type=entries');

        $this->assertFalse($page['covers_all']);
        $this->assertContains(['handle' => 'pages', 'site' => 'default'], $page['covers']);
        $this->assertNotContains(['handle' => 'secrets', 'site' => 'default'], $page['covers']);
        $this->assertNotContains('collections/secrets/plan.md', collect($page['data'])->pluck('path')->all());
    }
}
