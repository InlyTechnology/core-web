<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\StaticCaching\SiteCache;
use Inly\CoreWeb\StaticCaching\SiteScopedInvalidator;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Console\Commands\StaticWarmJob;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Contracts\Globals\Variables as VariablesContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Cachers\ApplicationCacher;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * A save on one site of a multisite install under the `all` rule: that
 * site's pages flushed, or with background_recache the saved page forgotten
 * at once (under http and https) and the site's other pages rendered again
 * in the background, never another site's but for the entry's copies there;
 * a global reaches the sites whose copy inherits it, directly or through
 * another copy.
 */
class SiteScopedInvalidatorTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private ApplicationCacher $cacher;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.system.multisite' => true]);
        $this->cacher = new ApplicationCacher(Cache::store('array'), []);
        Collection::make('pages')->sites(['default', 'other'])->routes('/{slug}')->save();
    }

    public function test_a_save_flushes_its_own_site_only(): void
    {
        $this->cacheBothSites();

        $this->invalidator()->refresh($this->entry('a', 'other'));

        $this->assertSame(['/', '/a', '/b'], $this->cached('http://default.test'));
        $this->assertSame([], $this->cached('http://other.test'));
    }

    public function test_with_background_recache_the_saved_page_goes_at_once_and_its_site_is_refreshed(): void
    {
        $this->backgroundRecache();
        $entry = $this->entry('a');

        $this->invalidator()->refresh($entry);

        $this->assertSame(['/', '/b'], $this->cached('http://default.test'), 'the other pages served as cached until rendered again');
        $this->assertSame(['/', '/a'], $this->cached('http://other.test'));
        $this->assertSame(['http://default.test/', 'http://default.test/b'], $this->warmed());
    }

    public function test_a_localized_page_forgets_its_copies_on_the_other_sites(): void
    {
        $origin = $this->entry('a');
        Entry::make()->collection('pages')->locale('other')->origin($origin)->slug('a')->save();
        $this->backgroundRecache();

        $this->invalidator()->refresh($origin);

        $this->assertSame(['/'], $this->cached('http://other.test'), 'the copy links to the saved page from its language switcher');
        $this->assertSame(['http://default.test/', 'http://default.test/b'], $this->warmed(), "the other site's pages are rendered again when its copy is saved");
    }

    public function test_a_copy_saved_alone_forgets_its_origin_s_page(): void
    {
        $origin = $this->entry('a');
        $copy = Entry::make()->collection('pages')->locale('other')->origin($origin)->slug('a');
        $copy->save();
        $this->backgroundRecache();

        $this->invalidator()->refresh($copy);

        $this->assertSame(['/', '/b'], $this->cached('http://default.test'));
        $this->assertSame(['/'], $this->cached('http://other.test'));
        $this->assertSame(['http://other.test/'], $this->warmed());
    }

    public function test_a_copy_of_a_copy_forgets_every_page_in_its_chain(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://other.test/', 'locale' => 'sv_SE'],
            'third' => ['name' => 'Third', 'url' => 'http://third.test/', 'locale' => 'sv_SE'],
        ]);
        Collection::find('pages')->sites(['default', 'other', 'third'])->save();
        $root = $this->entry('a');
        $copy = Entry::make()->collection('pages')->locale('other')->origin($root)->slug('a');
        $copy->save();
        $copyOfCopy = Entry::make()->collection('pages')->locale('third')->origin($copy)->slug('a');
        $copyOfCopy->save();
        $this->cacheBothSites();
        $this->cache('http://third.test', ['/', '/a']);

        $this->invalidator()->refresh($copyOfCopy);

        $this->assertSame(['/', '/b'], $this->cached('http://default.test'), "the root's page links to the saved copy too");
        $this->assertSame(['/'], $this->cached('http://other.test'));
        $this->assertSame([], $this->cached('http://third.test'));
    }

    public function test_without_background_recache_a_copy_forgets_only_its_origin_s_page(): void
    {
        $origin = $this->entry('a');
        $copy = Entry::make()->collection('pages')->locale('other')->origin($origin)->slug('a');
        $copy->save();
        $this->cacheBothSites();

        $this->invalidator()->refresh($copy);

        $this->assertSame(['/', '/b'], $this->cached('http://default.test'));
        $this->assertSame([], $this->cached('http://other.test'));
    }

    public function test_a_redirecting_copy_leaves_the_page_it_redirects_to(): void
    {
        $origin = $this->entry('a');
        Entry::make()->collection('pages')->locale('other')->origin($origin)->slug('a')->set('redirect', '/b')->save();
        $this->backgroundRecache();
        $this->cache('http://other.test', ['/b']);

        $this->invalidator()->refresh($origin);

        $this->assertSame(['/', '/a', '/b'], $this->cached('http://other.test'));
    }

    public function test_an_entry_without_a_page_forgets_nothing_at_once(): void
    {
        Collection::make('snippets')->sites(['default', 'other'])->save();
        $snippet = Entry::make()->collection('snippets')->locale('default')->slug('footer');
        $snippet->save();
        $this->backgroundRecache();

        $this->invalidator()->refresh($snippet);

        $this->assertSame(['/', '/a', '/b'], $this->cached('http://default.test'), 'a missing URL is not the home page');
        $this->assertSame(['http://default.test/', 'http://default.test/a', 'http://default.test/b'], $this->warmed());
    }

    public function test_a_global_reaches_every_site_whose_copy_inherits_it(): void
    {
        $set = $this->inheritedSettings();

        $this->invalidator()->refresh($set->in('default'));
        $this->assertSame([], $this->cached('http://default.test'));
        $this->assertSame([], $this->cached('http://other.test'));
        $this->assertSame([], $this->cached('http://third.test'), 'through the copy it inherits from');
        $this->assertSame(['/'], $this->cached('http://fourth.test'));

        $this->cacheBothSites();
        $this->invalidator()->refresh($set->in('other'));
        $this->assertSame(['/', '/a', '/b'], $this->cached('http://default.test'), 'an origin does not read its copies');
        $this->assertSame([], $this->cached('http://other.test'));
    }

    public function test_with_background_recache_a_global_renders_every_inheriting_site_again(): void
    {
        $set = $this->inheritedSettings();
        $this->backgroundRecache();

        $this->invalidator()->refresh($set->in('other'));

        $this->assertEqualsCanonicalizing(['http://other.test/', 'http://other.test/a', 'http://third.test/'], $this->warmed());
        $this->assertSame(['/', '/a', '/b'], $this->cached('http://default.test'));
    }

    public function test_a_save_that_reaches_every_site_clears_the_whole_cache_at_once(): void
    {
        $set = GlobalSet::make('settings')->sites(['default' => null, 'other' => 'default']);
        $set->save();
        $this->cacheBothSites();
        $this->cache('http://elsewhere.test', ['/']);

        $this->invalidator()->refresh($set->in('default'));

        $this->assertSame([], $this->cached('http://default.test'));
        $this->assertSame([], $this->cached('http://other.test'));
        $this->assertSame([], $this->cached('http://elsewhere.test'), "as Statamic's own rule clears it");
    }

    public function test_a_global_whose_set_is_gone_flushes_its_own_site(): void
    {
        $this->cacheBothSites();
        $variables = app(VariablesContract::class)->globalSet('deleted')->locale('other');

        $this->invalidator()->refresh($variables);

        $this->assertSame(['/', '/a', '/b'], $this->cached('http://default.test'));
        $this->assertSame([], $this->cached('http://other.test'));
    }

    public function test_with_background_recache_a_global_whose_set_is_gone_renders_its_own_site_again(): void
    {
        $this->backgroundRecache();
        $variables = app(VariablesContract::class)->globalSet('deleted')->locale('other');

        $this->invalidator()->refresh($variables);

        $this->assertSame(['http://other.test/', 'http://other.test/a'], $this->warmed());
        $this->assertSame(['/', '/a', '/b'], $this->cached('http://default.test'));
    }

    public function test_a_moved_page_forgets_its_old_address_and_the_pages_under_it(): void
    {
        $entry = $this->entry('old');
        $this->backgroundRecache();
        $this->cache('http://default.test', ['/old', '/old/child', '/oldish']);
        $this->cache('https://default.test', ['/old', '/old/child']);
        $this->cache('http://other.test', ['/old']);
        $entry->slug('new');
        Blink::store('entry-uris')->flush();

        $this->invalidator()->refresh($entry);

        $this->assertSame(['/', '/a', '/b', '/oldish'], $this->cached('http://default.test'));
        $this->assertSame([], $this->cached('https://default.test'));
        $this->assertSame(['/', '/a', '/old'], $this->cached('http://other.test'));
    }

    public function test_on_a_shared_host_a_page_under_the_root_leaves_the_other_site_s_pages(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
        ]);
        $this->cache('http://shared.test', ['/', '/a', '/other', '/other/a']);

        $forgotten = (new SiteCache($this->cacher))->forget(Site::get('default'), ['http://shared.test/*']);

        $this->assertSame(1, $forgotten);
        $this->assertSame(['/', '/other', '/other/a'], $this->cached('http://shared.test'));
    }

    public function test_the_saved_page_is_forgotten_under_http_and_https(): void
    {
        $this->backgroundRecache();
        $this->cache('https://default.test', ['/', '/a']);

        $this->invalidator()->refresh($this->entry('a'));

        $this->assertSame(['/', '/b'], $this->cached('http://default.test'));
        $this->assertSame(['/'], $this->cached('https://default.test'));
    }

    public function test_on_a_shared_host_a_localized_page_forgets_its_copy_under_the_other_site_s_path(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
        ]);
        $origin = $this->entry('a');
        Entry::make()->collection('pages')->locale('other')->origin($origin)->slug('a')->save();
        config(['statamic.static_caching.background_recache' => true]);
        $this->cache('http://shared.test', ['/', '/a', '/other', '/other/a']);
        Queue::fake();

        $this->invalidator()->refresh($origin);

        $this->assertSame(['/', '/other'], $this->cached('http://shared.test'));
        $this->assertSame(['http://shared.test/'], $this->warmed());
    }

    public function test_with_background_recache_a_save_without_a_site_renders_every_host_s_pages_again(): void
    {
        $this->backgroundRecache();
        $this->cache('https://other.test', ['/c']);

        foreach (['navigation' => Nav::make('main'), 'asset' => Asset::make(), 'form' => Form::make('contact')] as $kind => $item) {
            Queue::fake();

            $this->invalidator()->refresh($item);

            $this->assertSame([
                'http://default.test/', 'http://default.test/a', 'http://default.test/b',
                'http://other.test/', 'http://other.test/a',
                'https://other.test/c',
            ], $this->warmed(), "a {$kind} save: Statamic's rule renders again only the base URL's pages");
        }

        $this->assertSame(['/', '/a', '/b'], $this->cached('http://default.test'));
    }

    public function test_a_save_without_a_site_clears_every_site(): void
    {
        $this->cacheBothSites();

        $this->invalidator()->refresh(Nav::make('main'));

        $this->assertSame([], $this->cached('http://default.test'));
        $this->assertSame([], $this->cached('http://other.test'));
    }

    public function test_other_rules_keep_statamic_s_own_invalidation(): void
    {
        $this->cacheBothSites();

        (new SiteScopedInvalidator($this->cacher, ['collections' => ['pages' => ['urls' => ['/b']]]]))->refresh($this->entry('a'));

        $this->assertSame(['/'], $this->cached('http://default.test'));
        $this->assertSame(['/', '/a'], $this->cached('http://other.test'));
    }

    /** Four sites: other inherits the settings from default, third from other, fourth from none. */
    private function inheritedSettings(): GlobalSetContract
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://other.test/', 'locale' => 'sv_SE'],
            'third' => ['name' => 'Third', 'url' => 'http://third.test/', 'locale' => 'sv_SE'],
            'fourth' => ['name' => 'Fourth', 'url' => 'http://fourth.test/', 'locale' => 'sv_SE'],
        ]);
        $set = GlobalSet::make('settings')->sites(['default' => null, 'other' => 'default', 'third' => 'other', 'fourth' => null]);
        $set->save();
        $this->cacheBothSites();
        $this->cache('http://third.test', ['/']);
        $this->cache('http://fourth.test', ['/']);

        return $set;
    }

    private function invalidator(): SiteScopedInvalidator
    {
        return new SiteScopedInvalidator($this->cacher, 'all');
    }

    private function backgroundRecache(): void
    {
        config(['statamic.static_caching.background_recache' => true]);
        $this->cacheBothSites();
        Queue::fake();
    }

    private function entry(string $slug, string $site = 'default'): EntryContract
    {
        $entry = Entry::make()->collection('pages')->locale($site)->slug($slug)->data(['title' => $slug]);
        $entry->save();

        return $entry;
    }

    private function cacheBothSites(): void
    {
        $this->cache('http://default.test', ['/', '/a', '/b']);
        $this->cache('http://other.test', ['/', '/a']);
    }

    /** @param  list<string>  $paths */
    private function cache(string $domain, array $paths): void
    {
        foreach ($paths as $path) {
            $this->cacher->cacheUrl(md5($domain.$path), $path, $domain);
        }
    }

    /** @return list<string> */
    private function cached(string $domain): array
    {
        return $this->cacher->getUrls($domain)->values()->all();
    }

    /** @return list<string> the addresses queued to be rendered again, without the recache token */
    private function warmed(): array
    {
        return Queue::pushed(StaticWarmJob::class)
            ->map(fn (StaticWarmJob $job) => preg_replace('/[?&]__recache=[^&]*$/', '', (string) $job->request->getUri()))
            ->values()
            ->all();
    }
}
