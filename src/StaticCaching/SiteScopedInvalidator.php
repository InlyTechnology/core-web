<?php

namespace Inly\CoreWeb\StaticCaching;

use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Globals\Variables;
use Statamic\Contracts\Structures\Tree;
use Statamic\Sites\Site;
use Statamic\StaticCaching\DefaultInvalidator;

/**
 * The `all` rule, one site at a time, for a multisite install whose sites
 * share no pages: an entry, global or collection tree saved on one site
 * flushes only that site's cached pages. With background_recache on, the
 * saved item's own pages are forgotten at once, so the next visitor sees
 * the change, and the site's other pages are rendered again in the
 * background (SiteCache::refresh()). What Statamic reports without a site
 * (a navigation, an asset, a taxonomy term, a form) still flushes or
 * refreshes everything. Set it as statamic.static_caching.invalidation.class
 * to use it.
 */
class SiteScopedInvalidator extends DefaultInvalidator
{
    public function invalidate($item)
    {
        $site = $this->siteOf($item);

        if ($this->rules !== 'all' || $site === null) {
            parent::invalidate($item);

            return;
        }

        $cache = new SiteCache($this->cacher);

        if (! $this->refreshing) {
            $cache->flush($site);

            return;
        }

        $this->cacher->invalidateUrls([...$this->getItemUrls($item), ...$this->getItemOldUrls($item)]);
        $cache->refresh($site);
    }

    private function siteOf(mixed $item): ?Site
    {
        return match (true) {
            $item instanceof Entry, $item instanceof Variables, $item instanceof Tree => $item->site(),
            default => null,
        };
    }
}
