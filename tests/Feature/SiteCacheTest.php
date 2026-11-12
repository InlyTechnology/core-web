<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotChange;
use Inly\CoreWeb\StaticCaching\SiteCache;
use Inly\CoreWeb\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Statamic\Console\Commands\StaticWarmJob;
use Statamic\Events\UrlInvalidated;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Cachers\ApplicationCacher;

/**
 * Flushing one site's static cache: every page cached for it, on its host
 * and under its path, and none of another site's, whether the sites have
 * hosts of their own or share one, each under its own path; or only the
 * pages a snapshot change names, falling back to the whole site whenever
 * they are not known. Refreshing one site's pages, or the ones it names,
 * in the background the same way.
 */
class SiteCacheTest extends TestCase
{
    private ApplicationCacher $cacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacher = new ApplicationCacher(Cache::store('array'), []);
    }

    public function test_sites_on_their_own_hosts_flush_their_own_host(): void
    {
        $this->cache('http://default.test', ['/', '/a', '/other/b']);
        $this->cache('http://other.test', ['/', '/c']);

        (new SiteCache($this->cacher))->flush(Site::get('other'));

        $this->assertSame(['/', '/a', '/other/b'], $this->cached('http://default.test'));
        $this->assertSame([], $this->cached('http://other.test'));
    }

    public function test_sites_sharing_a_host_flush_their_own_path_only(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
            'third' => ['name' => 'Third', 'url' => 'http://shared.test/other-third', 'locale' => 'sv_SE'],
        ]);
        $this->cache('http://shared.test', ['/', '/a', '/otherwise', '/other', '/other/b?x=1', '/other-third/c']);

        (new SiteCache($this->cacher))->flush(Site::get('other'));
        $this->assertSame(['/', '/a', '/otherwise', '/other-third/c'], $this->cached('http://shared.test'));

        (new SiteCache($this->cacher))->flush(Site::get('default'));
        $this->assertSame(['/other-third/c'], $this->cached('http://shared.test'));
    }

    public function test_named_paths_flush_those_pages_with_their_query_strings_only(): void
    {
        $this->cache('http://default.test', ['/', '/campaigns', '/campaigns?brand=a', '/campaigns/a/one', '/campaigns/a/two', '/brands', '/brands/a', '/about']);

        $flushed = (new SiteCache($this->cacher))->flushPaths(Site::get('default'), ['/campaigns', 'brands/*', '/']);

        $this->assertSame(4, $flushed);
        $this->assertSame(['/campaigns/a/one', '/campaigns/a/two', '/brands', '/about'], $this->cached('http://default.test'));
    }

    public function test_each_path_is_forgotten_once_with_every_query_string_it_was_cached_under(): void
    {
        Event::fake([UrlInvalidated::class]);
        $this->cache('http://default.test', ['/', '/a', '/a?x=1', '/a?y=2', '/b']);
        $cache = new SiteCache($this->cacher);

        $this->assertSame(3, $cache->flushPaths(Site::get('default'), ['/a']));
        $this->assertSame(['/', '/b'], $this->cached('http://default.test'));
        Event::assertDispatchedTimes(UrlInvalidated::class, 1);

        $cache->flush(Site::get('default'));
        $this->assertSame([], $this->cached('http://default.test'));
        Event::assertDispatchedTimes(UrlInvalidated::class, 3);
    }

    public function test_named_paths_are_on_the_site_under_its_path_of_a_shared_host(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
        ]);
        $this->cache('http://shared.test', ['/', '/road', '/other', '/other/road', '/other/road/bike', '/other/sea']);

        (new SiteCache($this->cacher))->flushPaths(Site::get('other'), ['/', '/road/*']);

        $this->assertSame(['/', '/road', '/other/road', '/other/sea'], $this->cached('http://shared.test'));
    }

    public function test_forgetting_several_sites_on_a_shared_host_takes_each_site_s_own_pages(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'en' => ['name' => 'English', 'url' => 'http://shared.test/en/', 'locale' => 'en_GB'],
            'de' => ['name' => 'German', 'url' => 'http://shared.test/de/', 'locale' => 'de_DE'],
        ]);
        $this->cache('http://shared.test', ['/', '/a', '/b', '/en', '/en/a', '/en/b', '/en/b?x=1', '/de/a', '/de/b', '/de/b/c']);
        $this->cache('https://shared.test', ['/a', '/en/a', '/en/b']);

        $forgotten = (new SiteCache($this->cacher))->forgetMany([
            'default' => ['http://shared.test/a'],
            'en' => ['http://shared.test/en/b'],
            'de' => ['http://shared.test/de/b/*'],
            'gone' => ['http://shared.test/'],
        ]);

        $this->assertSame(6, $forgotten);
        $this->assertSame(['/', '/b', '/en', '/en/a', '/de/a', '/de/b'], $this->cached('http://shared.test'), '/a on the root site is not /en/a');
        $this->assertSame(['/en/a'], $this->cached('https://shared.test'));
    }

    public function test_a_site_whose_handle_is_digits_owns_its_pages(): void
    {
        Queue::fake();
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'en_GB'],
            '2026' => ['name' => 'Campaign', 'url' => 'http://shared.test/sv/', 'locale' => 'sv_SE'],
        ]);
        $this->cache('http://shared.test', ['/', '/a', '/sv', '/sv/a', '/sv/b']);
        $site = Site::get('2026');
        $cache = new SiteCache($this->cacher);

        $this->assertTrue($cache->owns($site, 'http://shared.test/sv/a'));
        $this->assertSame(1, $cache->forget($site, ['http://shared.test/sv/a']));
        $this->assertSame(1, $cache->forgetMany([2026 => ['http://shared.test/sv/b']]));
        $this->assertSame(1, $cache->refresh($site));

        $cache->flush($site);
        $this->assertSame(['/', '/a'], $this->cached('http://shared.test'));
    }

    public function test_refresh_queues_each_page_of_the_site_once_and_keeps_serving_the_cached_copy(): void
    {
        Queue::fake();
        config(['statamic.static_caching.background_recache' => true]);
        $this->cache('http://default.test', ['/', '/a', '/a?page=2']);
        $this->cache('http://www.default.test', ['/b']);
        $this->cache('http://other.test', ['/', '/c']);

        $queued = (new SiteCache($this->cacher))->refresh(Site::get('default'));

        $this->assertSame(2, $queued, '/ and /a, the query string requested with its path');
        $this->assertSame(['http://default.test/', 'http://default.test/a', 'http://default.test/a?page=2'], $this->warmed());
        $this->assertSame(['/', '/a', '/a?page=2'], $this->cached('http://default.test'));
        $this->assertSame(['/', '/c'], $this->cached('http://other.test'));
    }

    public function test_refresh_on_a_shared_host_takes_the_site_s_own_path_only(): void
    {
        Queue::fake();
        config(['statamic.static_caching.background_recache' => true]);
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
        ]);
        $this->cache('http://shared.test', ['/', '/otherwise', '/other', '/other/b']);

        (new SiteCache($this->cacher))->refresh(Site::get('other'));

        $this->assertSame(['http://shared.test/other', 'http://shared.test/other/b'], $this->warmed());
    }

    public function test_refresh_forgets_a_page_cached_as_an_error_instead_of_rendering_it_again(): void
    {
        Queue::fake();
        config(['statamic.static_caching.background_recache' => true]);
        $this->cache('http://default.test', ['/', '/gone']);
        Cache::store('array')->forever('static-cache:responses:'.md5('http://default.test/gone'), ['content' => '', 'headers' => [], 'status' => 404]);

        (new SiteCache($this->cacher))->refresh(Site::get('default'));

        $this->assertSame(['http://default.test/'], $this->warmed());
        $this->assertSame(['/'], $this->cached('http://default.test'));
    }

    public function test_refresh_all_queues_each_page_on_each_site_s_host_and_scheme(): void
    {
        Queue::fake();
        $this->cache('http://default.test', ['/', '/a', '/a?page=2']);
        $this->cache('https://default.test', ['/b']);
        $this->cache('http://other.test', ['/', '/gone']);
        $this->cache('http://elsewhere.test', ['/', '/c?x=1']);
        Cache::store('array')->forever('static-cache:responses:'.md5('http://other.test/gone'), ['content' => '', 'headers' => [], 'status' => 404]);

        $queued = (new SiteCache($this->cacher))->refreshAll();

        $this->assertSame(5, $queued, 'each path once, with its query strings');
        $this->assertSame([
            'http://default.test/', 'http://default.test/a', 'http://default.test/a?page=2',
            'https://default.test/b',
            'http://other.test/',
        ], $this->warmed());
        $this->assertSame(['/', '/a', '/a?page=2'], $this->cached('http://default.test'), 'served as cached until rendered again');
        $this->assertSame(['/'], $this->cached('http://other.test'), 'an error response forgotten instead');
        $this->assertSame([], $this->cached('http://elsewhere.test'), 'a host no site names is forgotten, never sent a request');
    }

    public function test_refresh_all_reaches_a_page_outside_every_site_s_path_on_a_shared_host(): void
    {
        Queue::fake();
        config(['statamic.static_caching.background_recache' => true]);
        Site::setSites([
            'en' => ['name' => 'English', 'url' => 'http://shared.test/en/', 'locale' => 'en_US'],
            'sv' => ['name' => 'Svenska', 'url' => 'http://shared.test/sv/', 'locale' => 'sv_SE'],
        ]);
        $this->cache('http://shared.test', ['/en', '/sv/a', '/foo']);
        $cache = new SiteCache($this->cacher);

        $this->assertSame(1, $cache->refresh(Site::get('en')));
        $this->assertSame(['http://shared.test/en'], $this->warmed(), 'no site\'s refresh reaches /foo');

        $this->assertSame(3, $cache->refreshAll());
        $this->assertContains('http://shared.test/foo', $this->warmed());
    }

    public function test_a_relative_site_is_on_the_app_url_s_host_whatever_host_the_request_names(): void
    {
        Queue::fake();
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => '/', 'locale' => 'sv_SE'],
            'en' => ['name' => 'English', 'url' => '/en/', 'locale' => 'en_US'],
        ]);
        $this->app['url']->setRequest(Request::create('http://forged.test/'));
        $this->cache('http://default.test', ['/', '/en/a']);
        $this->cache('http://forged.test', ['/', '/en/a']);
        $cache = new SiteCache($this->cacher);

        $this->assertSame(2, $cache->refreshAll());
        $this->assertSame(['http://default.test/', 'http://default.test/en/a'], $this->warmed());
        $this->assertSame([], $this->cached('http://forged.test'), 'forgotten, never sent a request');

        $cache->flush(Site::get('en'));
        $this->assertSame(['/'], $this->cached('http://default.test'));
    }

    public function test_a_relative_site_forgets_on_its_host_what_an_invalidator_reports_on_the_request_s(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => '/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://other.test/', 'locale' => 'en_US'],
        ]);
        $this->app['url']->setRequest(Request::create('http://alias.test/'));
        $this->cache('http://default.test', ['/a', '/b']);
        $this->cache('http://alias.test', ['/a']);
        $cache = new SiteCache($this->cacher);

        $this->assertSame(1, $cache->forget(Site::get('default'), ['http://alias.test/a', 'http://other.test/b']));
        $this->assertSame(['/b'], $this->cached('http://default.test'), "never another site's address");
        $this->assertSame(['/a'], $this->cached('http://alias.test'), "forgotten on the site's host only");
        $this->assertTrue($cache->owns(Site::get('default'), 'http://alias.test/b'));
        $this->assertFalse($cache->owns(Site::get('default'), 'http://other.test/b'));
    }

    public function test_a_site_s_host_matches_whatever_its_case(): void
    {
        Site::setSites(['default' => ['name' => 'Default', 'url' => 'http://Shared.test/', 'locale' => 'sv_SE']]);
        $this->cache('http://shared.test', ['/', '/a', '/b']);
        $cache = new SiteCache($this->cacher);

        $this->assertTrue($cache->owns(Site::get('default'), 'http://Shared.test/a'));
        $this->assertSame(1, $cache->forget(Site::get('default'), ['http://Shared.test/a']));
        $this->assertSame(['/', '/b'], $this->cached('http://shared.test'));

        $cache->flush(Site::get('default'));
        $this->assertSame([], $this->cached('http://shared.test'));
    }

    public function test_refreshing_named_paths_queues_those_pages_with_their_query_strings_only(): void
    {
        Queue::fake();
        config(['statamic.static_caching.background_recache' => true]);
        $this->cache('http://default.test', ['/', '/campaigns', '/campaigns?brand=a', '/campaigns/a/one', '/brands', '/brands/a', '/about']);
        $this->cache('https://default.test', ['/brands/b']);
        $this->cache('http://other.test', ['/campaigns']);

        $queued = (new SiteCache($this->cacher))->refreshPaths(Site::get('default'), ['/campaigns', 'brands/*', '/missing']);

        $this->assertSame(3, $queued, '/campaigns and /brands/a on http, /brands/b on https; /missing is not cached');
        $this->assertSame(['http://default.test/campaigns', 'http://default.test/campaigns?brand=a', 'http://default.test/brands/a', 'https://default.test/brands/b'], $this->warmed());
        $this->assertSame(['/', '/campaigns', '/campaigns?brand=a', '/campaigns/a/one', '/brands', '/brands/a', '/about'], $this->cached('http://default.test'));
    }

    public function test_refreshing_named_paths_on_a_shared_host_takes_the_site_s_own_pages_only(): void
    {
        Queue::fake();
        config(['statamic.static_caching.background_recache' => true]);
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
        ]);
        $this->cache('http://shared.test', ['/', '/road', '/road/bike', '/other', '/other/road', '/other/road/bike']);

        (new SiteCache($this->cacher))->refreshPaths(Site::get('other'), ['/', '/road/*']);

        $this->assertSame(['http://shared.test/other', 'http://shared.test/other/road/bike'], $this->warmed());
    }

    public function test_refreshing_named_paths_forgets_a_page_cached_as_an_error_instead_of_rendering_it_again(): void
    {
        Queue::fake();
        config(['statamic.static_caching.background_recache' => true]);
        $this->cache('http://default.test', ['/', '/gone']);
        Cache::store('array')->forever('static-cache:responses:'.md5('http://default.test/gone'), ['content' => '', 'headers' => [], 'status' => 404]);

        $queued = (new SiteCache($this->cacher))->refreshPaths(Site::get('default'), ['/', '/gone']);

        $this->assertSame(2, $queued);
        $this->assertSame(['http://default.test/'], $this->warmed());
        $this->assertSame(['/'], $this->cached('http://default.test'));
    }

    public function test_refreshing_without_background_recache_forgets_the_pages_instead(): void
    {
        Queue::fake();
        config(['statamic.static_caching.background_recache' => false]);
        $this->cache('http://default.test', ['/', '/a', '/a?page=2', '/b']);
        $this->cache('http://other.test', ['/', '/a']);
        $cache = new SiteCache($this->cacher);

        $this->assertSame(1, $cache->refreshPaths(Site::get('default'), ['/a']));
        $this->assertSame(['/', '/b'], $this->cached('http://default.test'));

        $this->assertSame(2, $cache->refresh(Site::get('default')));
        $this->assertSame([], $this->cached('http://default.test'));
        $this->assertSame(['/', '/a'], $this->cached('http://other.test'));
        $this->assertSame([], $this->warmed());
    }

    public function test_a_change_flushes_the_pages_its_documents_name_and_the_snapshot_s_sitemap(): void
    {
        $this->app->instance(SiteCache::class, new SiteCache($this->cacher));
        $this->cache('http://default.test', ['/', '/campaigns', '/campaigns/one', '/campaigns/old-one', '/campaigns/two', '/sitemap-core-things.xml', '/sitemap-core-others.xml']);
        $snapshot = $this->things()->sitemap(fn (array $doc) => [['loc' => $doc['url']]]);

        $change = SnapshotChange::between($snapshot, 'default',
            ['one' => ['url' => '/campaigns/old-one'], 'two' => ['url' => '/campaigns/two']],
            ['one' => ['url' => '/campaigns/one'], 'two' => ['url' => '/campaigns/two']],
        );
        (SiteCache::flushOnChange())('default', [], $change);

        $this->assertSame(['/', '/campaigns/two', '/sitemap-core-others.xml'], $this->cached('http://default.test'), 'the old and the new page of the moved document, never the unchanged one');
    }

    /** @return iterable<string, array{\Closure(): ?SnapshotChange}> */
    public static function unknownChanges(): iterable
    {
        yield 'no change set (a first pull, a failed listener)' => [fn () => null];
        yield 'a snapshot that names no pages' => [fn () => SnapshotChange::between(Snapshot::make('plain'), 'default', [], ['a' => ['url' => '/a']])];
        yield 'a document whose pages are unknown' => [fn () => SnapshotChange::between(self::things(), 'default', [], ['a' => ['url' => '/a'], 'b' => ['url' => null]])];
        yield 'naming the pages fails' => [fn () => SnapshotChange::between(self::things()->dependents(fn () => throw new RuntimeException('no index')), 'default', [], ['a' => ['url' => '/a']])];
        yield 'more documents than a targeted flush takes' => [fn () => SnapshotChange::between(self::things(), 'default', [], array_map(fn (int $i) => ['url' => "/p{$i}"], array_combine(range(1, 21), range(1, 21))))];
    }

    /** @param  \Closure(): ?SnapshotChange  $change */
    #[DataProvider('unknownChanges')]
    public function test_a_change_whose_pages_are_not_known_flushes_the_whole_site(\Closure $change): void
    {
        $this->app->instance(SiteCache::class, new SiteCache($this->cacher));
        $this->cache('http://default.test', ['/', '/a', '/unrelated']);
        $this->cache('http://other.test', ['/']);

        (SiteCache::flushOnChange())('default', [], $change());

        $this->assertSame([], $this->cached('http://default.test'));
        $this->assertSame(['/'], $this->cached('http://other.test'));
    }

    public function test_targeted_flushing_can_be_switched_off(): void
    {
        config(['core-web.snapshots.targeted_flush.enabled' => false]);
        $this->app->instance(SiteCache::class, new SiteCache($this->cacher));
        $this->cache('http://default.test', ['/', '/a', '/unrelated']);

        (SiteCache::flushOnChange())('default', [], SnapshotChange::between(self::things(), 'default', [], ['a' => ['url' => '/a']]));

        $this->assertSame([], $this->cached('http://default.test'));
    }

    /** A snapshot whose document names its one page by `url`; a document without one names none it knows of. */
    private static function things(): Snapshot
    {
        return Snapshot::make('things')->dependents(fn (array $doc) => $doc['url'] === null ? null : ['/campaigns', $doc['url']]);
    }

    /** @param  list<string>  $paths */
    private function cache(string $domain, array $paths): void
    {
        foreach ($paths as $path) {
            $this->cacher->cacheUrl(md5($domain.$path), $path, $domain);
        }
    }

    /** @return list<string> the addresses queued to be rendered again, without the recache token */
    private function warmed(): array
    {
        return Queue::pushed(StaticWarmJob::class)
            ->map(fn (StaticWarmJob $job) => preg_replace('/[?&]__recache=[^&]*$/', '', (string) $job->request->getUri()))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function cached(string $domain): array
    {
        return $this->cacher->getUrls($domain)->values()->all();
    }
}
