<?php

namespace Inly\CoreWeb\Content\Feed;

use Statamic\Contracts\Entries\Entry;

/** Statamic's own status, from the published flag and the collection's date behaviour. */
class StatamicPageStatus implements PageStatus
{
    public function of(Entry $entry): string
    {
        $status = (string) $entry->status();

        return in_array($status, ['published', 'draft', 'scheduled', 'expired'], true) ? $status : 'draft';
    }
}
