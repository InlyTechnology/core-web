<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Fields\Blueprint;

/**
 * Turns the fields a source sends into the values a draft stores. A site
 * whose drafts arrive in another shape than Statamic stores (an AI's block
 * format, image references to fetch) binds its own; StoredValues takes them
 * as they are.
 */
interface DraftValueNormaliser
{
    /**
     * Returns `data` (the values to store), `ignored` (paths sent but
     * dropped), `images_needed` (required asset paths left for an editor) and
     * `unchanged` (top-level fields sent exactly as stored).
     *
     * @param  array<string, mixed>  $input  the fields sent, without the slug and date
     * @param  array<string, mixed>  $base  the stored values the draft builds on
     * @return array{data: array<string, mixed>, ignored: list<string>, images_needed: list<string>, unchanged: list<string>}
     */
    public function normalise(Blueprint $blueprint, string $collection, string $site, array $input, array $base): array;
}
