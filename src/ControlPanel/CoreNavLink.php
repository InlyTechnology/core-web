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

            $label = config('core-web.cp_link.label');

            $nav->create(filled($label) ? (string) $label : 'Core')
                ->section('Top Level')
                ->url($url)
                ->icon(self::icon())
                ->attributes(['target' => '_blank', 'rel' => 'noopener noreferrer']);
        });
    }

    /** CORE_URL: the Core this site talks to. */
    public static function url(): string
    {
        return rtrim((string) config('core-web.client.base_url'), '/');
    }

    /** Statamic takes a value as markup only when it starts with `<svg`, so a configured SVG is trimmed first. */
    private static function icon(): string
    {
        $icon = config('core-web.cp_link.icon');

        return filled($icon) ? trim((string) $icon) : (string) file_get_contents(__DIR__.'/../../resources/svg/core.svg');
    }
}
