<?php

namespace Inly\CoreWeb\Tests\Feature;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\StaticCaching\GuardedApplicationCacher;
use Inly\CoreWeb\StaticCaching\SiteCache;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\ApplicationCacher;
use Statamic\StaticCaching\StaticCacheManager;

/**
 * The `half` cacher serves a cached page only when its request started after
 * the page was last cleared: a page Statamic cached while a clear ran, which
 * lost its place on the URL list, is a miss instead of outliving every later
 * clear.
 */
class GuardedApplicationCacherTest extends TestCase
{
    private const PAGE = 'http://default.test/products/orange';

    private InterleavingStore $store;

    private float $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InterleavingStore;
    }

    public function test_statamics_cacher_keeps_a_page_cached_during_a_flush_through_every_later_flush(): void
    {
        $cacher = new ApplicationCacher(new Repository($this->store), []);
        $this->cache($cacher, self::PAGE, 'v1', at: 0);
        $this->store->whenForgetting(fn () => $this->cache($cacher, self::PAGE, 'v2', at: 1));

        $cacher->flush();
        $this->assertSame([], $cacher->getUrls('http://default.test')->all());

        $this->now += 60;
        $cacher->flush();
        $cacher->invalidateUrl('/products/orange', 'http://default.test');

        // Upstream's bug, kept as the guard's reason: once this fails, Statamic lists the page and the guard can go.
        $this->assertSame('v2', $this->served($cacher, self::PAGE));
    }

    public function test_a_page_cached_during_a_flush_is_rendered_again(): void
    {
        $cacher = $this->guarded();
        $this->cache($cacher, self::PAGE, 'v1', at: -10);
        $this->store->whenForgetting(fn () => $this->cache($cacher, self::PAGE, 'v2', at: -0.5));

        $cacher->flush();

        $this->assertNull($this->served($cacher, self::PAGE));

        $this->cache($cacher, self::PAGE, 'v3', at: 5);
        $this->assertSame('v3', $this->served($cacher, self::PAGE));
        $this->assertContains('/products/orange', $cacher->getUrls('http://default.test')->all());
    }

    public function test_a_page_cached_while_its_url_is_invalidated_is_rendered_again(): void
    {
        $cacher = $this->guarded();
        $this->cache($cacher, self::PAGE, 'v1', at: -10);
        $this->store->whenForgetting(fn () => $this->cache($cacher, self::PAGE, 'v2', at: -0.5));

        $cacher->invalidateUrl('/products/orange', 'http://default.test');

        $this->assertSame([], $cacher->getUrls('http://default.test')->all());
        $this->assertNull($this->served($cacher, self::PAGE));
    }

    public function test_a_page_rendered_before_its_path_was_cleared_is_a_miss_when_stored_after(): void
    {
        $cacher = $this->guarded();
        $request = $this->request(self::PAGE, at: 0);

        $this->now += 5;
        $cacher->invalidateUrls([self::PAGE]);
        $this->store($cacher, $request, 'rendered before the save');

        $this->assertNull($this->served($cacher, self::PAGE));
    }

    public function test_a_copy_rendered_before_a_clear_is_a_miss_when_stored_after_one_rendered_after(): void
    {
        $cacher = $this->guarded();
        $before = $this->request(self::PAGE, at: 0);

        $this->now += 5;
        $cacher->invalidateUrls([self::PAGE]);
        $this->cache($cacher, self::PAGE, 'rendered after the save', at: 2);
        $this->store($cacher, $before, 'rendered before the save');

        $this->assertNull($this->served($cacher, self::PAGE));
    }

    public function test_a_page_rendered_after_a_clear_is_served(): void
    {
        $cacher = $this->guarded();
        $cacher->flush();
        $cacher->invalidateUrl('/products/orange', 'http://default.test');

        $this->cache($cacher, self::PAGE, 'v1', at: GuardedApplicationCacher::CLOCK_MARGIN);

        $this->assertSame('v1', $this->served($cacher, self::PAGE));
    }

    public function test_a_page_rendered_within_the_clock_margin_of_a_clear_is_rendered_again(): void
    {
        $cacher = $this->guarded();
        $cacher->flush();

        $this->cache($cacher, self::PAGE, 'v1', at: GuardedApplicationCacher::CLOCK_MARGIN / 2);

        $this->assertNull($this->served($cacher, self::PAGE));
    }

    public function test_clearing_a_path_takes_its_unlisted_pages_with_every_query_string(): void
    {
        $cacher = $this->guarded();
        $this->cache($cacher, self::PAGE.'?size=50', 'v1', at: 0);
        $this->cache($cacher, 'http://default.test/products/orange-cedar', 'other', at: 0);
        $this->cache($cacher, 'http://other.test/products/orange', 'other host', at: 0);
        $this->unlist('http://default.test');

        $this->now += 10;
        $cacher->invalidateUrl('/products/orange', 'http://default.test');

        $this->assertNull($this->served($cacher, self::PAGE.'?size=50'));
        $this->assertSame('other', $this->served($cacher, 'http://default.test/products/orange-cedar'));
        $this->assertSame('other host', $this->served($cacher, 'http://other.test/products/orange'));
    }

    public function test_a_wildcard_takes_the_unlisted_pages_under_it(): void
    {
        $cacher = $this->guarded();
        $this->cache($cacher, 'http://default.test/blog/one', 'one', at: 0);
        $this->cache($cacher, 'http://default.test/blogroll', 'roll', at: 0);
        $this->cache($cacher, 'http://default.test/about', 'about', at: 0);
        $this->unlist('http://default.test');

        $this->now += 10;
        $cacher->invalidateUrls(['http://default.test/blog/*', 'http://default.test/news/*']);

        $this->assertNull($this->served($cacher, 'http://default.test/blog/one'));
        $this->assertSame('roll', $this->served($cacher, 'http://default.test/blogroll'));
        $this->assertSame('about', $this->served($cacher, 'http://default.test/about'));

        $this->cache($cacher, 'http://default.test/blog/one', 'one again', at: 20);
        $this->assertSame('one again', $this->served($cacher, 'http://default.test/blog/one'));
    }

    public function test_a_wildcard_on_a_query_string_takes_the_unlisted_pages_it_names(): void
    {
        $cacher = $this->guarded();
        $this->cache($cacher, 'http://default.test/search?q=shoes', 'shoes', at: 0);
        $this->cache($cacher, 'http://default.test/search?page=2', 'page two', at: 0);
        $this->unlist('http://default.test');

        $this->now += 10;
        $cacher->invalidateUrls(['http://default.test/search?q=*']);

        $this->assertNull($this->served($cacher, 'http://default.test/search?q=shoes'));
        $this->assertSame('page two', $this->served($cacher, 'http://default.test/search?page=2'));
    }

    public function test_a_wildcard_is_recorded_while_the_url_list_is_locked(): void
    {
        $cacher = $this->guarded()->waitingNoLonger();
        $this->cache($cacher, 'http://default.test/blog/one', 'one', at: 0);
        $this->unlist('http://default.test');
        $this->store->lock('static-cache:urls', 10)->acquire();

        $this->now += 10;
        $cacher->invalidateUrls(['http://default.test/blog/*']);

        $this->assertNull($this->served($cacher, 'http://default.test/blog/one'));
    }

    public function test_a_wildcard_that_waited_for_the_lock_keeps_a_newer_one_written_meanwhile(): void
    {
        $cacher = $this->guarded();
        $this->cache($cacher, 'http://default.test/blog/one', 'one', at: 1.5);
        $this->unlist('http://default.test');
        $this->store->whenLocking(function () use ($cacher) {
            $this->now += 2;
            $cacher->recordClears('http://default.test', [], ['/blog/']);
        });

        $cacher->recordClears('http://default.test', [], ['/blog/']);

        $this->assertSame(['/blog/' => $this->now], $this->store->get(self::under('http://default.test')));
        $this->assertNull($this->served($cacher, 'http://default.test/blog/one'));
    }

    public function test_an_older_wider_wildcard_keeps_a_newer_narrower_one(): void
    {
        $cacher = $this->guarded();
        $cacher->invalidateUrls(['http://default.test/*']);
        $wider = $this->now;

        $this->now += 10;
        $cacher->invalidateUrls(['http://default.test/blog/*']);

        $this->assertSame(['/' => $wider, '/blog/' => $this->now], $this->store->get(self::under('http://default.test')));
    }

    public function test_a_wildcard_outlives_the_pages_expiry_and_an_hour(): void
    {
        $cacher = $this->guarded(['expiry' => 10]);
        $cacher->invalidateUrls(['http://default.test/blog/*']);
        $blog = $this->now;

        $this->now += 71 * 60;
        $cacher->invalidateUrls(['http://default.test/news/*']);

        $this->assertSame(['/blog/' => $blog, '/news/' => $this->now], $this->store->get(self::under('http://default.test')));
    }

    public function test_a_flush_leaves_the_wildcards_alone_while_the_url_list_is_locked(): void
    {
        $cacher = $this->guarded()->waitingNoLonger();
        $cacher->invalidateUrls(['http://default.test/blog/*']);
        $this->store->lock('static-cache:urls', 10)->acquire();

        $this->now += 10;
        $cacher->flush();

        $this->assertSame(['/blog/' => $this->now - 10], $this->store->get(self::under('http://default.test')));
    }

    public function test_a_flush_or_a_wider_wildcard_drops_the_wildcards_it_covers(): void
    {
        $cacher = $this->guarded();
        $under = self::under('http://default.test');

        $cacher->invalidateUrls(['http://default.test/blog/*', 'http://default.test/news/*']);
        $this->now += 10;
        $cacher->invalidateUrls(['http://default.test/blog/2026/*', 'http://default.test/*']);
        $this->assertSame(['/' => $this->now], $this->store->get($under));

        $this->now += 10;
        $cacher->flush();
        $this->assertNull($this->store->get($under));
    }

    public function test_a_page_statamics_own_cacher_stored_is_rendered_again(): void
    {
        $this->cache(new ApplicationCacher(new Repository($this->store), []), self::PAGE, 'before the upgrade', at: 0);

        $this->assertNull($this->served($this->guarded(), self::PAGE));
    }

    public function test_a_page_whose_url_cannot_be_listed_is_not_kept(): void
    {
        $cacher = $this->guarded()->waitingNoLonger();
        $this->store->lock('static-cache:urls', 10)->acquire();

        $this->cache($cacher, self::PAGE, 'v1', at: 0);

        $this->assertSame([], $cacher->getUrls('http://default.test')->all());
        $this->assertNull($this->store->get('static-cache:responses:'.md5(self::PAGE)));
        $this->assertNull($this->store->get('static-cache:generated-at:'.md5(self::PAGE)));
    }

    public function test_a_paths_clear_lasts_an_hour_longer_than_its_pages(): void
    {
        $cacher = $this->guarded(['expiry' => 10]);
        $this->cache($cacher, self::PAGE, 'v1', at: 0);
        $cacher->invalidateUrl('/products/orange', 'http://default.test');
        $record = 'static-cache:core-web:cleared:'.md5('http://default.test/products/orange');

        $this->travel(11)->minutes();
        $this->assertNull($this->store->get('static-cache:responses:'.md5(self::PAGE)));
        $this->assertSame($this->now, $this->store->get($record));

        $this->travel(60)->minutes();
        $this->assertNull($this->store->get($record));
    }

    public function test_a_site_flush_records_all_its_pages_and_named_paths_their_own(): void
    {
        $cacher = $this->guarded();
        $this->cache($cacher, 'http://default.test/a', 'a', at: 0);
        $this->cache($cacher, 'https://default.test/campaigns/?brand=x', 'campaigns', at: 0);
        $this->cache($cacher, 'http://other.test/b', 'b', at: 0);
        $this->unlist('http://default.test');
        $this->unlist('https://default.test');

        $this->now += 10;
        (new SiteCache($cacher))->flushPaths(Site::get('default'), ['/campaigns']);

        $this->assertNull($this->served($cacher, 'https://default.test/campaigns/?brand=x'));
        $this->assertSame('a', $this->served($cacher, 'http://default.test/a'));

        (new SiteCache($cacher))->flush(Site::get('default'));

        $this->assertNull($this->served($cacher, 'http://default.test/a'));
        $this->assertSame('b', $this->served($cacher, 'http://other.test/b'));
    }

    public function test_a_site_flush_takes_all_its_pages_and_none_of_another_sites_under_its_path(): void
    {
        $this->shareHost();
        $cacher = $this->guarded();
        $this->cache($cacher, 'http://shared.test/a', 'a', at: 0);
        $this->cache($cacher, 'https://shared.test/otherwise?x=1', 'otherwise', at: 0);
        $this->cache($cacher, 'http://shared.test/other/b', 'b', at: 0);
        $this->unlist('http://shared.test');
        $this->unlist('https://shared.test');

        $this->now += 10;
        (new SiteCache($cacher))->flush(Site::get('default'));

        $this->assertNull($this->served($cacher, 'http://shared.test/a'));
        $this->assertNull($this->served($cacher, 'https://shared.test/otherwise?x=1'));
        $this->assertSame('b', $this->served($cacher, 'http://shared.test/other/b'));

        (new SiteCache($cacher))->flush(Site::get('other'));

        $this->assertNull($this->served($cacher, 'http://shared.test/other/b'));
    }

    public function test_a_sites_paths_and_wildcards_leave_another_sites_pages_under_its_path_alone(): void
    {
        $this->shareHost();
        $cacher = $this->guarded();
        $this->cache($cacher, 'http://shared.test/products/p', 'p', at: 0);
        $this->cache($cacher, 'http://shared.test/other', 'other', at: 0);
        $this->cache($cacher, 'http://shared.test/other/b', 'b', at: 0);
        $this->cache($cacher, 'http://shared.test/other/x/c', 'c', at: 0);
        $this->unlist('http://shared.test');

        $this->now += 10;
        (new SiteCache($cacher))->flushPaths(Site::get('default'), ['/other', '/other/x/*']);

        $this->assertSame('other', $this->served($cacher, 'http://shared.test/other'));
        $this->assertSame('c', $this->served($cacher, 'http://shared.test/other/x/c'));
        $this->assertSame('p', $this->served($cacher, 'http://shared.test/products/p'));

        (new SiteCache($cacher))->flushPaths(Site::get('default'), ['/*']);

        $this->assertNull($this->served($cacher, 'http://shared.test/products/p'));
        $this->assertSame('b', $this->served($cacher, 'http://shared.test/other/b'));
        $this->assertSame('other', $this->served($cacher, 'http://shared.test/other'));
    }

    public function test_forgetting_and_rendering_again_without_background_recache_record_the_pages_they_take(): void
    {
        config(['statamic.static_caching.background_recache' => false]);
        $this->shareHost();
        $cacher = $this->guarded();

        foreach (['a', 'b', 'c', 'other/d', 'other/e'] as $path) {
            $this->cache($cacher, "http://shared.test/{$path}", $path, at: 0);
        }

        $this->unlist('http://shared.test');

        $this->now += 10;
        $cache = new SiteCache($cacher);
        $cache->forget(Site::get('default'), ['http://shared.test/a']);
        $cache->forgetMany(['other' => ['http://shared.test/other/d']]);
        $cache->refreshPaths(Site::get('default'), ['/b']);

        $this->assertNull($this->served($cacher, 'http://shared.test/a'));
        $this->assertNull($this->served($cacher, 'http://shared.test/other/d'));
        $this->assertNull($this->served($cacher, 'http://shared.test/b'));
        $this->assertSame('c', $this->served($cacher, 'http://shared.test/c'));
        $this->assertSame('other/e', $this->served($cacher, 'http://shared.test/other/e'));

        $cache->refresh(Site::get('other'));

        $this->assertNull($this->served($cacher, 'http://shared.test/other/e'));
        $this->assertSame('c', $this->served($cacher, 'http://shared.test/c'));
    }

    public function test_rendering_again_in_the_background_keeps_serving_the_cached_pages(): void
    {
        Queue::fake();
        config(['statamic.static_caching.background_recache' => true]);
        $cacher = $this->guarded();
        $this->cache($cacher, 'http://default.test/a', 'a', at: 0);
        $this->cache($cacher, 'http://default.test/b', 'b', at: 0);

        $this->now += 10;
        $cache = new SiteCache($cacher);
        $cache->refreshPaths(Site::get('default'), ['/a']);
        $cache->refresh(Site::get('default'));

        $this->assertSame('a', $this->served($cacher, 'http://default.test/a'));
        $this->assertSame('b', $this->served($cacher, 'http://default.test/b'));
    }

    public function test_of_two_sites_at_one_address_the_one_whose_url_names_its_host_owns_the_page(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => '/', 'locale' => 'sv_SE'],
            'de' => ['name' => 'De', 'url' => 'https://example.de/', 'locale' => 'de_DE'],
        ]);
        $cacher = $this->guarded();
        $this->cache($cacher, 'https://example.de/x', 'x', at: 0);
        $this->unlist('https://example.de');

        $this->now += 10;
        $this->onRequest('http://default.test/cp');
        (new SiteCache($cacher))->flush(Site::get('de'));

        $this->onRequest('https://example.de/x');
        $this->assertNull($this->served($cacher, 'https://example.de/x'));
    }

    public function test_a_site_at_a_relative_url_owns_its_pages_on_the_host_that_serves_them(): void
    {
        Site::setSites(['default' => ['name' => 'Default', 'url' => '/', 'locale' => 'sv_SE']]);
        $cacher = $this->guarded();
        $this->cache($cacher, 'https://www.example.test/x', 'x', at: 0);
        $this->unlist('https://www.example.test');

        $this->now += 10;
        $this->onRequest('https://example.test/cp');
        (new SiteCache($cacher))->flush(Site::get('default'));

        $this->onRequest('https://www.example.test/x');
        $this->assertNull($this->served($cacher, 'https://www.example.test/x'));
    }

    public function test_a_site_whose_url_names_its_host_owns_the_pages_under_a_relative_sites_path(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => '/', 'locale' => 'sv_SE'],
            'en' => ['name' => 'En', 'url' => '/en/', 'locale' => 'en_GB'],
            'de' => ['name' => 'De', 'url' => 'https://example.de/', 'locale' => 'de_DE'],
        ]);
        $cacher = $this->guarded();
        $this->cache($cacher, 'https://example.de/en/x', 'x', at: 0);
        $this->unlist('https://example.de');

        // A flush queued on the host's own root, where the relative sites resolve to it as well.
        $this->now += 10;
        $this->onRequest('https://example.de/cp');
        (new SiteCache($cacher))->flush(Site::get('de'));

        $this->onRequest('https://example.de/en/x');
        $this->assertNull($this->served($cacher, 'https://example.de/en/x'));
    }

    public function test_a_page_on_a_sites_own_scheme_belongs_to_the_site_find_by_url_names(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => '/', 'locale' => 'sv_SE'],
            'en' => ['name' => 'En', 'url' => '/en/', 'locale' => 'en_GB'],
            'de' => ['name' => 'De', 'url' => 'https://example.de/', 'locale' => 'de_DE'],
            'at' => ['name' => 'At', 'url' => 'https://example.de/at/', 'locale' => 'de_AT'],
            'fr' => ['name' => 'Fr', 'url' => '{{ config:app.url }}/fr/', 'locale' => 'fr_FR'],
        ]);
        $roots = [
            'http://default.test/x' => [],
            'https://example.de/x' => [],
            'http://default.test/index.php/x' => ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => '/var/www/public/index.php'],
            'http://default.test/sub/index.php/x' => ['SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/var/www/public/sub/index.php'],
        ];

        foreach ($roots as $root => $server) {
            $request = Request::create($root, server: $server);
            $this->app->instance('request', $request);
            $this->app['url']->setRequest($request);

            foreach (['http://default.test', 'https://example.de'] as $host) {
                foreach (['/', '/x', '/en', '/en/x?y=1', '/enx', '/at/x', '/fr/x', '/sub/en/x'] as $path) {
                    $this->assertSame(Site::findByUrl($host.$path)?->handle(), SiteCache::siteOf($host.$path), $host.$path.' on '.$root);
                }
            }
        }
    }

    public function test_a_cached_page_says_when_it_was_generated(): void
    {
        if (! method_exists(ApplicationCacher::class, 'getGeneratedAt')) {
            $this->markTestSkipped('Statamic before 6.41 keeps no generated-at time.');
        }

        $this->freezeSecond();
        $cacher = $this->guarded();
        $this->cache($cacher, self::PAGE, 'v1', at: 0);

        $this->assertSame(now()->getTimestamp(), $cacher->getGeneratedAt(self::PAGE));
    }

    public function test_the_half_strategy_runs_the_guarded_cacher_unless_turned_off(): void
    {
        config(['statamic.static_caching.strategy' => 'half']);

        $this->assertInstanceOf(GuardedApplicationCacher::class, $this->freshCacher());

        config(['core-web.static_cache.guard' => null]);
        $this->assertInstanceOf(GuardedApplicationCacher::class, $this->freshCacher());

        config(['core-web.static_cache.guard' => false]);
        $this->assertSame(ApplicationCacher::class, $this->freshCacher()::class);
    }

    public function test_a_dedicated_store_emptied_by_a_flush_keeps_its_record(): void
    {
        config([
            'statamic.static_caching.strategy' => 'half',
            'cache.stores.static_cache' => ['driver' => 'array'],
        ]);
        $cacher = $this->freshCacher();
        $request = Request::create(self::PAGE, server: ['REQUEST_TIME_FLOAT' => microtime(true) - 10]);

        $this->app->make(StaticCacheManager::class)->flush();
        $this->store($cacher, $request, 'rendered before the flush');

        $this->assertNull($this->served($cacher, self::PAGE));
    }

    /** A site at the root of shared.test and one under its path, /other. */
    private function shareHost(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
        ]);
    }

    /** @param  array<string, mixed>  $config */
    private function guarded(array $config = []): ClockedCacher
    {
        return new ClockedCacher(new Repository($this->store), $config, fn () => $this->now);
    }

    private function freshCacher(): Cacher
    {
        $this->app->forgetInstance(StaticCacheManager::class);

        return $this->app->make(Cacher::class);
    }

    /** Caches a page as Statamic's middleware does, for a request that started `at` seconds from now. */
    private function cache(ApplicationCacher $cacher, string $url, string $content, float $at): void
    {
        $this->store($cacher, $this->request($url, $at), $content);
    }

    private function request(string $url, float $at): Request
    {
        return Request::create($url, server: ['REQUEST_TIME_FLOAT' => $this->now + $at]);
    }

    private function store(ApplicationCacher $cacher, Request $request, string $content): void
    {
        $cacher->cachePage($request, $content);
        event(new ResponsePrepared($request, new Response($content)));
    }

    private function served(ApplicationCacher $cacher, string $url): ?string
    {
        $request = Request::create($url);

        return $cacher->hasCachedPage($request) ? $cacher->getCachedPage($request)->content : null;
    }

    private static function under(string $domain): string
    {
        return 'static-cache:core-web:cleared-under:'.md5($domain);
    }

    /** Serves the rest of the test as a request to the URL, which a site's relative URL resolves against. */
    private function onRequest(string $url): void
    {
        $request = Request::create($url);
        $this->app->instance('request', $request);
        $this->app['url']->setRequest($request);
    }

    /** Drops the domain's URL list and keeps its pages: what a lost write to the list leaves. */
    private function unlist(string $domain): void
    {
        $this->store->forget('static-cache:'.md5($domain).'.urls');
    }
}

/** The guarded cacher on the test's clock. */
class ClockedCacher extends GuardedApplicationCacher
{
    /** @param  array<string, mixed>  $config */
    public function __construct(Repository $cache, array $config, private Closure $clock)
    {
        parent::__construct($cache, $config);
    }

    public function waitingNoLonger(): static
    {
        $this->lockWait = 0;

        return $this;
    }

    protected function now(): float
    {
        return ($this->clock)();
    }
}

/**
 * An array store that runs another request in the middle of a clear: right after the first page it forgets, or before
 * the next lock it is asked for.
 */
class InterleavingStore extends ArrayStore
{
    private ?Closure $visitor = null;

    private ?Closure $locker = null;

    public function whenForgetting(Closure $visitor): void
    {
        $this->visitor = $visitor;
    }

    public function whenLocking(Closure $locker): void
    {
        $this->locker = $locker;
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        if ($this->locker !== null) {
            $locker = $this->locker;
            $this->locker = null;
            $locker();
        }

        return parent::lock($name, $seconds, $owner);
    }

    public function forget($key)
    {
        $forgotten = parent::forget($key);

        if ($this->visitor !== null && str_starts_with($key, 'static-cache:responses:')) {
            $visitor = $this->visitor;
            $this->visitor = null;
            $visitor();
        }

        return $forgotten;
    }
}
