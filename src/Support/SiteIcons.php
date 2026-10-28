<?php

namespace Inly\CoreWeb\Support;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Icon;

/**
 * The site's own icons, `icons.folder`, as the Statamic icon set `site`, so
 * any icon field (a button's, a blueprint's own) picks from them.
 */
final class SiteIcons
{
    public const SET = 'site';

    /**
     * Registers the set, over Statamic's own icons while the folder is unset
     * or missing, since a field naming `set: site` fails its whole screen on
     * a set that does not exist. True when it holds the site's icons.
     */
    public static function register(): bool
    {
        $folder = config('core-web.icons.folder');
        $found = is_string($folder) && is_dir($folder);

        if (! $found && filled($folder)) {
            Log::warning('core-web.icons.folder is not a folder on this server', ['folder' => $folder]);
        }

        Icon::register(self::SET, $found ? $folder : statamic_path('resources/svg/icons'));

        return $found;
    }
}
