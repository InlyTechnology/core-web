<?php

namespace Inly\CoreWeb\Content\Feed;

use Statamic\Contracts\Entries\Entry;

/**
 * What a visitor gets at a page's address: `published`, `draft`, `scheduled` or `expired`.
 * A site that hides pages by rules of its own (a page open only between two dates) binds its own.
 */
interface PageStatus
{
    public function of(Entry $entry): string;
}
