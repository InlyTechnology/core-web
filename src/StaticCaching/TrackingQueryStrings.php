<?php

namespace Inly\CoreWeb\StaticCaching;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Pagination\Paginator;

/**
 * The query parameters ad and analytics platforms add to a landing page, kept out of the static cache's key. Each
 * click carries its own values, so every visit from a campaign cached a full page of its own that no one read again,
 * and Statamic matches the names exactly (no `utm_*`), so the list lives here where one edit reaches every site.
 *
 * A page is then cached under its address without them, as rendered for the visitor who brought them. Whatever reads
 * them on the server must render inside `@nocache`, which re-renders per request with the visitor's own query.
 * Pagination links (`withQueryString()`, which Statamic's paginated tags call) leave them out for that reason, and
 * leave out the token Statamic adds to a page's address to render it again, as Statamic's own paginator does.
 */
final class TrackingQueryStrings
{
    /** Added to the site's own statamic.static_caching.disallowed_query_strings. */
    public static function apply(Repository $config): void
    {
        $tracking = array_values(array_filter((array) $config->get('core-web.static_cache.tracking_query_strings', []), 'is_string'));
        $strategy = $config->get('statamic.static_caching.strategy');

        // `full` fills nocache regions from the browser, which sends the address it has, parameters and all: Statamic
        // looks the regions up under that address and finds none, since they were stored under the one without them.
        $leftOut = $strategy === 'full' ? [] : $tracking;

        // A cached link carrying the token would let anyone render any page over its cached copy.
        if ($config->get('statamic.static_caching.background_recache')) {
            $leftOut[] = (string) $config->get('statamic.static_caching.recache_token_parameter', '__recache');
        }

        // Laravel's own resolver hands a paginator the whole query: cached, the page would credit the first visitor's
        // campaign with every later visitor's next page. Uncached, the visitor keeps their own on every page.
        if (filled($strategy) && $leftOut !== []) {
            Paginator::queryStringResolver(fn (): array => array_diff_key(app('request')->query(), array_flip($leftOut)));
        }

        if ($tracking === [] || $strategy === 'full') {
            return;
        }

        $own = (array) $config->get('statamic.static_caching.disallowed_query_strings', []);

        $config->set('statamic.static_caching.disallowed_query_strings', array_values(array_unique([...$own, ...$tracking])));
    }
}
