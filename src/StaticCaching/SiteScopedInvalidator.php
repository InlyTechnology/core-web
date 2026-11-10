<?php

namespace Inly\CoreWeb\StaticCaching;

use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Globals\Variables;
use Statamic\Contracts\Structures\Tree;
use Statamic\Facades\Site as Sites;
use Statamic\Sites\Site;
use Statamic\StaticCaching\DefaultInvalidator;

/**
 * The `all` rule one site at a time, for a multisite install whose sites
 * share no pages: Statamic's own rule clears (or renders again) every site's
 * pages for a save on one of them. What Statamic reports without a site still
 * goes to its rule; docs/static-cache.md has the details.
 */
class SiteScopedInvalidator extends DefaultInvalidator
{
    public function invalidate(mixed $item): void
    {
        $sites = $this->rules === 'all' ? $this->sitesOf($item) : [];

        if ($sites === []) {
            parent::invalidate($item);

            return;
        }

        $cache = new SiteCache($this->cacher);

        foreach ($sites as $site) {
            if (! $this->refreshing) {
                $cache->flush($site);

                continue;
            }

            $cache->forget($site, [...$this->getItemUrls($item), ...$this->getItemOldUrls($item)]);
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
        $set = $variables->globalSet();

        // The set can be deleted before the queued invalidation runs.
        if ($set === null) {
            return array_values(array_filter([$variables->site()]));
        }

        $origins = $set->origins();
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
