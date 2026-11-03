<?php

namespace Inly\CoreWeb\Preview;

/** Puts the preview frame's script into a page rendered for Core's preview (core-web::preview.script). */
final class PreviewScript
{
    public static function inject(string $html, string $core): string
    {
        $script = view('core-web::preview.script', ['core' => $core])->render();
        $at = strripos($html, '</body>');

        return $at === false ? $html.$script : substr($html, 0, $at).$script.substr($html, $at);
    }
}
