<?php

namespace Inly\CoreWeb\StaticCaching;

use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Globals\Variables;
use Statamic\Contracts\Structures\Tree;
use Statamic\Facades\Site as Sites;
use Statamic\Sites\Site;
use Statamic\StaticCaching\DefaultInvalidator;

/**
 * The `all` rule one site at a time, for sites that share no pages:
 * Statamic's own rule clears every site's pages for a save on one of them, or
 * renders again those on the static cache's base URL, which is none when that
 * URL has a path. What Statamic reports without a site clears every site's
 * pages, or renders again those on each site's host; docs/static-cache.md has
 * the details.
 */
class SiteScopedInvalidator extends DefaultInvalidator
{
    // No return type, as Statamic's, so a site's subclass written for an earlier release still loads.
    public function invalidate(mixed $item)
    {
        $sites = $this->rules === 'all' ? $this->sitesOf($item) : [];

        if ($sites === []) {
            // Statamic's rule renders again only the base URL's pages, so a site on another host would keep its old copy.
            if ($this->rules === 'all' && $this->refreshing) {
                (new SiteCache($this->cacher))->refreshAll();

                return;
            }

            parent::invalidate($item);

            return;
        }

        // All of it at once: forgetting page by page rewrites the cacher's URL list for every page.
        if (! $this->refreshing && count($sites) === Sites::all()->count()) {
            $this->cacher->flush();

            return;
        }

        $cache = new SiteCache($this->cacher);
        // A global has no URLs of its own under `all`, and reading its rules needs its set, which may be gone.
        $urls = $this->refreshing && ! $item instanceof Variables
            ? [...$this->getItemUrls($item), ...$this->getItemOldUrls($item)]
            : [];

        foreach ($sites as $site) {
            if (! $this->refreshing) {
                $cache->flush($site);

                continue;
            }

            $cache->forget($site, $urls);
            $cache->refresh($site);
        }

        if ($item instanceof Entry) {
            $this->forgetLocalizations($cache, $item);
        }
    }

    /** Each other copy of the entry links to it from a language switcher or hreflang, so it is forgotten on its own site. */
    private function forgetLocalizations(SiteCache $cache, Entry $entry): void
    {
        $root = $entry->root();
        $urls = [];

        foreach ($root->descendants()->merge([$root]) as $localization) {
            if ($localization->id() !== $entry->id() && ! $localization->isRedirect()) {
                $urls[$localization->locale()][] = $localization->absoluteUrl();
            }
        }

        // One pass over each host's cached pages for every copy: an origin's save reports each of its copies too.
        $cache->forgetMany($urls);
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
