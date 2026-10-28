<?php

namespace Inly\CoreWeb\Support;

use Statamic\Facades\Icon;

/**
 * The site's own icons, `icons.folder`, as the Statamic icon set `site`, so
 * any icon field (a button's, a blueprint's own) picks from them.
 */
final class SiteIcons
{
    public const SET = 'site';

    /** Registers the folder and names the set, or null when the site names none. */
    public static function register(): ?string
    {
        $folder = config('core-web.icons.folder');

        if (! is_string($folder) || ! is_dir($folder)) {
            return null;
        }

        Icon::register(self::SET, $folder);

        return self::SET;
    }
}
