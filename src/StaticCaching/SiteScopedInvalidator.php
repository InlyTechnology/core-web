<?php

namespace Inly\CoreWeb\StaticCaching;

use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Globals\Variables;
use Statamic\Contracts\Structures\Tree;
use Statamic\Sites\Site;
use Statamic\StaticCaching\DefaultInvalidator;

/**
 * The `all` rule, one site at a time, for a multisite install whose sites
 * share no pages: an entry, global or tree saved on one site flushes only
 * that site's cached pages. What belongs to no one site (an asset, a
 * taxonomy term, a form) still flushes everything. Set it as
 * statamic.static_caching.invalidation.class to use it.
 */
class SiteScopedInvalidator extends DefaultInvalidator
{
    public function invalidate($item)
    {
        $site = $this->siteOf($item);

        if ($this->rules !== 'all' || $this->refreshing || $site === null) {
            parent::invalidate($item);

            return;
        }

        (new SiteCache($this->cacher))->flush($site);
    }

    private function siteOf(mixed $item): ?Site
    {
        return match (true) {
            $item instanceof Entry, $item instanceof Variables, $item instanceof Tree => $item->site(),
            default => null,
        };
    }
}
