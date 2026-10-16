<?php

namespace Inly\CoreWeb\ControlPanel;

use Statamic\Facades\CP\Nav;

/**
 * A link to the Core backoffice right under Dashboard in the control panel,
 * opened in a new tab so unsaved CP work stays open. Every CP user sees it;
 * without a Core URL there is no link.
 */
final class CoreNavLink
{
    public static function register(): void
    {
        Nav::extend(function ($nav): void {
            $url = self::url();

            if ($url === '') {
                return;
            }

            $nav->create((string) config('core-web.cp_link.label', 'Core'))
                ->section('Top Level')
                ->url($url)
                ->icon(self::icon())
                ->attributes(['target' => '_blank', 'rel' => 'noopener noreferrer']);
        });
    }

    /**
     * CORE_URL, or on a local machine without one the Core `core:pull` reads,
     * once the pull client is set up: the Core a developer's data came from.
     */
    public static function url(): string
    {
        $url = rtrim((string) config('core-web.client.base_url'), '/');

        if ($url !== '') {
            return $url;
        }

        if (! app()->environment('local')
            || blank(config('core-web.pull.client_id'))
            || blank(config('core-web.pull.client_secret'))) {
            return '';
        }

        return rtrim((string) config('core-web.pull.base_url'), '/');
    }

    /** The configured icon (a Statamic icon name or an SVG), or Core's mark. */
    private static function icon(): string
    {
        $icon = config('core-web.cp_link.icon');

        return filled($icon) ? (string) $icon : (string) file_get_contents(__DIR__.'/../../resources/svg/core.svg');
    }
}
