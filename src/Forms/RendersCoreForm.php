<?php

namespace Inly\CoreWeb\Forms;

/**
 * A form the site renders with its own markup and posts to a Core form,
 * named in core-web.forms.renderers. What it renders is reported to Core,
 * which then asks an admin to confirm before saving a change that would
 * break it (a field removed or retyped, an option it shows dropped).
 */
interface RendersCoreForm
{
    /** The key of the Core form it posts to, as Core's form list names it. */
    public static function coreForm(): string;

    /**
     * The Core fields its markup renders: a field name, or a field name =>
     * the option values the markup writes out itself (not read from the
     * definition), which Core then keeps from being removed.
     *
     * @return array<int|string, string|list<string>>
     */
    public static function renders(): array;
}
