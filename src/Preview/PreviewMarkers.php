<?php

namespace Inly\CoreWeb\Preview;

use Illuminate\Support\HtmlString;

/**
 * The attributes that mark a field or a block for Core's preview frame
 * (`@coreField('title')`, `@coreField('title', true)` for one typed into
 * in place, `@coreBlock($loop->index)`), printed only while a page renders
 * for that frame, so a public or cached page carries none.
 */
final class PreviewMarkers
{
    public static function field(string $field, bool $editable = false): HtmlString
    {
        if (! self::active()) {
            return new HtmlString('');
        }

        return new HtmlString('data-core-field="'.e($field).'"'.($editable ? ' data-core-editable' : ''));
    }

    public static function block(int $index): HtmlString
    {
        return new HtmlString(self::active() ? 'data-core-block="'.$index.'"' : '');
    }

    private static function active(): bool
    {
        return app()->resolved(PreviewContext::class) && PreviewContext::current()->isRendering();
    }
}
