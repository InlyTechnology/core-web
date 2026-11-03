<?php

namespace Inly\CoreWeb\StaticCaching;

use Illuminate\Contracts\Config\Repository;

/**
 * The query parameters ad and analytics platforms add to a landing page, kept out of the static cache's key. Each
 * click carries its own values, so every visit from a campaign cached a full page of its own that no one read again,
 * and Statamic matches the names exactly (no `utm_*`), so the list lives here where one edit reaches every site.
 *
 * A page is then cached under its address without them, as rendered for the visitor who brought them. Whatever reads
 * them on the server must render inside `@nocache`, which re-renders per request with the visitor's own query.
 */
final class TrackingQueryStrings
{
    /** Added to the site's own statamic.static_caching.disallowed_query_strings. */
    public static function apply(Repository $config): void
    {
        $tracking = array_values(array_filter((array) $config->get('core-web.static_cache.tracking_query_strings', []), 'is_string'));

        // `full` fills nocache regions from the browser, which sends the address it has, parameters and all: Statamic
        // looks the regions up under that address and finds none, since they were stored under the one without them.
        if ($tracking === [] || $config->get('statamic.static_caching.strategy') === 'full') {
            return;
        }

        $own = (array) $config->get('statamic.static_caching.disallowed_query_strings', []);

        $config->set('statamic.static_caching.disallowed_query_strings', array_values(array_unique([...$own, ...$tracking])));
    }
}
