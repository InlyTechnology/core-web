<?php

namespace Inly\CoreWeb\Tests\Feature;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Facades\Date;
use Inly\CoreWeb\StaticCaching\GuardedApplicationCacher;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Events\StaticCacheCleared;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InterleavingStore;
        Date::setTestNow(Date::createFromTimestamp(1_000_000));
    }

    public function test_statamics_cacher_keeps_a_page_cached_during_a_flush_through_every_later_flush(): void
    {
        $cacher = new ApplicationCacher(new Repository($this->store), []);
        $this->cache($cacher, self::PAGE, 'v1', at: 0);
        $this->store->whenForgetting(fn () => $this->cache($cacher, self::PAGE, 'v2', at: 1));

        $cacher->flush();
        $this->assertSame([], $cacher->getUrls('http://default.test')->all());

        $this->travel(60)->seconds();
        $cacher->flush();
        $cacher->invalidateUrl('/products/orange', 'http://default.test');

        // Upstream's bug, kept as the guard's reason: once this fails, Statamic lists the page and the guard can go.
        $this->assertSame('v2', $this->served($cacher, self::PAGE));
    }

    public function test_a_page_cached_during_a_flush_is_rendered_again(): void
    {
        $cacher = $this->guarded();
        $this->cache($cacher, self::PAGE, 'v1', at: -10);
        $this->store->whenForgetting(fn () => $this->cache($cacher, self::PAGE, 'v2', at: 0));

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
        $this->store->whenForgetting(fn () => $this->cache($cacher, self::PAGE, 'v2', at: 0));

        $cacher->invalidateUrl('/products/orange', 'http://default.test');

        $this->assertSame([], $cacher->getUrls('http://default.test')->all());
        $this->assertNull($this->served($cacher, self::PAGE));
    }

    public function test_a_page_rendered_before_its_path_was_cleared_is_a_miss_when_stored_after(): void
    {
        $cacher = $this->guarded();
        $request = $this->request(self::PAGE, at: 0);

        $this->travel(5)->seconds();
        $cacher->invalidateUrls([self::PAGE]);
        $this->store($cacher, $request, 'rendered before the save');

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

        $this->travel(10)->seconds();
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

        $this->travel(10)->seconds();
        $cacher->invalidateUrls(['http://default.test/blog/*', 'http://default.test/news/*']);

        $this->assertNull($this->served($cacher, 'http://default.test/blog/one'));
        $this->assertSame('roll', $this->served($cacher, 'http://default.test/blogroll'));
        $this->assertSame('about', $this->served($cacher, 'http://default.test/about'));

        $this->cache($cacher, 'http://default.test/blog/one', 'one again', at: 20);
        $this->assertSame('one again', $this->served($cacher, 'http://default.test/blog/one'));
    }

    public function test_a_page_statamics_own_cacher_stored_is_rendered_again(): void
    {
        $this->cache(new ApplicationCacher(new Repository($this->store), []), self::PAGE, 'before the upgrade', at: 0);

        $this->assertNull($this->served($this->guarded(), self::PAGE));
    }

    public function test_a_page_whose_url_cannot_be_listed_is_not_kept(): void
    {
        $cacher = new class(new Repository($this->store), []) extends GuardedApplicationCacher
        {
            protected int $lockWait = 0;
        };
        $this->store->lock('static-cache:urls', 10)->acquire();

        $this->cache($cacher, self::PAGE, 'v1', at: 0);

        $this->assertSame([], $cacher->getUrls('http://default.test')->all());
        $this->assertNull($this->store->get('static-cache:responses:'.md5(self::PAGE)));
    }

    public function test_the_half_strategy_runs_the_guarded_cacher_unless_turned_off(): void
    {
        config(['statamic.static_caching.strategy' => 'half']);

        $this->assertInstanceOf(GuardedApplicationCacher::class, $this->freshCacher());

        config(['core-web.static_cache.guard' => false]);

        $this->assertSame(ApplicationCacher::class, $this->freshCacher()::class);
    }

    public function test_clearing_the_whole_store_is_recorded_after_the_store_is_emptied(): void
    {
        config(['statamic.static_caching.strategy' => 'half']);
        $cacher = $this->freshCacher();
        $this->cache($cacher, self::PAGE, 'v1', at: 0);

        $this->travel(10)->seconds();
        StaticCacheCleared::dispatch();

        $this->assertNull($this->served($cacher, self::PAGE));
    }

    private function guarded(): GuardedApplicationCacher
    {
        return new GuardedApplicationCacher(new Repository($this->store), []);
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
        return Request::create($url, server: ['REQUEST_TIME_FLOAT' => Date::now()->getTimestamp() + $at]);
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
