<?php

namespace Inly\CoreWeb\Tests\Feature;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Events\ResponsePrepared;
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
        $this->store->lock('static-cache:urls', 10)->acquire();

        $this->now += 10;
        $cacher->invalidateUrls(['http://default.test/blog/*']);

        $this->assertNull($this->served($cacher, 'http://default.test/blog/one'));
    }

    public function test_a_flush_or_a_wider_wildcard_drops_the_wildcards_it_covers(): void
    {
        $cacher = $this->guarded();
        $under = 'static-cache:core-web:cleared-under:'.md5('http://default.test');

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

    public function test_a_site_flush_records_its_whole_host_and_named_paths_their_own(): void
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

    public function test_a_site_with_another_under_its_path_records_only_the_pages_it_finds(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://shared.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://shared.test/other/', 'locale' => 'sv_SE'],
        ]);
        $cacher = $this->guarded();
        $this->cache($cacher, 'http://shared.test/a', 'a', at: 0);
        $this->cache($cacher, 'http://shared.test/other/b', 'b', at: 0);
        $this->cache($cacher, 'http://shared.test/otherwise', 'otherwise', at: 0);
        $this->unlist('http://shared.test');

        $this->now += 10;
        (new SiteCache($cacher))->flush(Site::get('other'));

        $this->assertNull($this->served($cacher, 'http://shared.test/other/b'));
        $this->assertSame('otherwise', $this->served($cacher, 'http://shared.test/otherwise'));

        (new SiteCache($cacher))->flush(Site::get('default'));

        $this->assertSame('a', $this->served($cacher, 'http://shared.test/a'));
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

/** An array store that runs a visitor's request in the middle of a clear, right after the first page it forgets. */
class InterleavingStore extends ArrayStore
{
    private ?Closure $visitor = null;

    public function whenForgetting(Closure $visitor): void
    {
        $this->visitor = $visitor;
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
