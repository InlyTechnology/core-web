<?php

namespace Inly\CoreWeb\StaticCaching;

use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Globals\Variables;
use Statamic\Contracts\Structures\Tree;
use Statamic\Facades\Site as Sites;
use Statamic\Sites\Site;
use Statamic\StaticCaching\DefaultInvalidator;

/**
 * The `all` rule, one site at a time, for a multisite install whose sites
 * share no pages: an entry, global or collection tree saved on one site
 * flushes only that site's cached pages, and a global those of every site
 * whose copy inherits its values. With background_recache on, the saved
 * item's own pages on the site are forgotten at once, so the next visitor
 * sees the change, and the site's other pages are rendered again in the
 * background (SiteCache::refresh()). What Statamic reports without a site
 * (a navigation, an asset, a taxonomy term, a form) still flushes or
 * refreshes everything. Set it as statamic.static_caching.invalidation.class
 * to use it.
 */
class SiteScopedInvalidator extends DefaultInvalidator
{
    public function invalidate($item)
    {
        $sites = $this->rules === 'all' ? $this->sitesOf($item) : [];

        if ($sites === []) {
            parent::invalidate($item);

            return;
        }

        $cache = new SiteCache($this->cacher);
        $urls = $this->refreshing ? [...$this->getItemUrls($item), ...$this->getItemOldUrls($item)] : [];

        foreach ($sites as $site) {
            if (! $this->refreshing) {
                $cache->flush($site);

                continue;
            }

            // An unrouted entry has no URL, which the cacher would read as the home page.
            $own = array_filter($urls, fn (mixed $url) => is_string($url) && $url !== '' && $cache->owns($site, $url));

            if ($own !== []) {
                $this->cacher->invalidateUrls(array_values($own));
            }

            $cache->refresh($site);
        }
    }

    /** @return list<Site> */
    private function sitesOf(mixed $item): array
    {
        return match (true) {
            $item instanceof Variables => $this->inheritingSites($item),
            $item instanceof Entry, $item instanceof Tree => array_values(array_filter([$item->site()])),
            default => [],
        };
    }

    /**
     * The global's own site and every site whose copy of the set reads its
     * values through an origin, since saving a global saves no other copy.
     *
     * @return list<Site>
     */
    private function inheritingSites(Variables $variables): array
    {
        $origins = $variables->globalSet()->origins();
        $handles = [$variables->locale()];

        do {
            $inheriting = $origins
                ->filter(fn (?string $origin, string $site) => in_array($origin, $handles, true) && ! in_array($site, $handles, true))
                ->keys()
                ->all();
            $handles = [...$handles, ...$inheriting];
        } while ($inheriting !== []);

        return array_values(array_filter(array_map(fn (string $handle) => Sites::get($handle), $handles)));
    }
}
