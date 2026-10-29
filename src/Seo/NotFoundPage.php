<?php

namespace Inly\CoreWeb\Seo;

use Illuminate\Support\Facades\View;
use Inly\CoreWeb\Support\Values;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;

/**
 * Renders a 404 with the entry editors picked for it (core-web.seo.not_found: a global and its entries field), so the
 * page's text, blocks and SEO fields are content. The current site's pick first, then the default site's.
 */
final class NotFoundPage
{
    public static function register(): void
    {
        View::composer((array) config('core-web.seo.not_found.views', ['errors/404', 'layout']), function (\Illuminate\View\View $view): void {
            if ((int) ($view['response_code'] ?? 0) !== 404 || ($entry = self::entry()) === null) {
                return;
            }

            $view->with($entry->toAugmentedArray())->with('page', $entry);
        });
    }

    public static function entry(): ?Entry
    {
        $global = GlobalSet::findByHandle((string) config('core-web.seo.not_found.global', 'configuration'));
        $field = (string) config('core-web.seo.not_found.field', 'error_404_entry');

        if ($global === null || $field === '') {
            return null;
        }

        foreach (array_unique([Site::current()->handle(), Site::default()->handle()]) as $site) {
            $entry = Values::first($global->in($site)?->augmentedValue($field));
            $entry = is_string($entry) ? Entries::find($entry) : $entry;

            if ($entry instanceof Entry) {
                return $entry;
            }
        }

        return null;
    }
}
