<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inly\CoreWeb\StaticCaching\TrackingQueryStrings;
use Inly\CoreWeb\Tests\TestCase;

/** The ad and analytics parameters core-web keeps out of the static cache's key. */
class TrackingQueryStringsTest extends TestCase
{
    public function test_the_list_is_in_statamics_by_default(): void
    {
        $disallowed = config('statamic.static_caching.disallowed_query_strings');

        foreach (['utm_source', 'gclid', 'fbclid', 'hsa_kw', '_hsenc'] as $parameter) {
            $this->assertContains($parameter, $disallowed);
        }
    }

    public function test_the_sites_own_list_is_kept_and_nothing_twice(): void
    {
        config([
            'statamic.static_caching.disallowed_query_strings' => ['campaign_ref', 'gclid'],
            'core-web.static_cache.tracking_query_strings' => ['gclid', 'fbclid'],
        ]);

        TrackingQueryStrings::apply(config());

        $this->assertSame(['campaign_ref', 'gclid', 'fbclid'], config('statamic.static_caching.disallowed_query_strings'));
    }

    public function test_an_empty_list_adds_none(): void
    {
        config([
            'statamic.static_caching.disallowed_query_strings' => ['campaign_ref'],
            'core-web.static_cache.tracking_query_strings' => [],
        ]);

        TrackingQueryStrings::apply(config());

        $this->assertSame(['campaign_ref'], config('statamic.static_caching.disallowed_query_strings'));
    }

    public function test_pagination_links_leave_them_out_and_keep_the_rest(): void
    {
        config(['statamic.static_caching.strategy' => 'half']);
        TrackingQueryStrings::apply(config());
        $this->app->instance('request', Request::create('/news?q=boats&utm_source=newsletter&gclid=abc&page=1'));

        $this->assertSame('/news?q=boats&page=2', $this->nextPageUrl());
    }

    public function test_without_a_static_cache_pagination_links_keep_them(): void
    {
        $this->app->instance('request', Request::create('/news?utm_source=newsletter'));

        $this->assertSame('/news?utm_source=newsletter&page=2', $this->nextPageUrl());
    }

    public function test_an_empty_list_leaves_pagination_links_alone(): void
    {
        config(['statamic.static_caching.strategy' => 'half', 'core-web.static_cache.tracking_query_strings' => []]);
        TrackingQueryStrings::apply(config());
        $this->app->instance('request', Request::create('/news?utm_source=newsletter'));

        $this->assertSame('/news?utm_source=newsletter&page=2', $this->nextPageUrl());
    }

    public function test_with_background_recache_pagination_links_leave_out_the_render_token(): void
    {
        config([
            'statamic.static_caching.strategy' => 'full',
            'statamic.static_caching.background_recache' => true,
            'statamic.static_caching.recache_token_parameter' => '__recache',
        ]);
        TrackingQueryStrings::apply(config());
        $this->app->instance('request', Request::create('/news?q=boats&__recache=secret&gclid=abc'));

        $this->assertSame('/news?q=boats&gclid=abc&page=2', $this->nextPageUrl());
    }

    public function test_the_full_strategy_is_left_alone(): void
    {
        config([
            'statamic.static_caching.strategy' => 'full',
            'statamic.static_caching.disallowed_query_strings' => [],
            'core-web.static_cache.tracking_query_strings' => ['gclid'],
        ]);

        TrackingQueryStrings::apply(config());

        $this->assertSame([], config('statamic.static_caching.disallowed_query_strings'));
    }

    private function nextPageUrl(): ?string
    {
        return (new LengthAwarePaginator([], 30, 10, 1, ['path' => '/news']))->withQueryString()->nextPageUrl();
    }
}
