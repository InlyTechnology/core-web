<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Contracts\Entries\Entry;

/**
 * The hash an update names to say which version of an entry it was written
 * against, so a draft never undoes an edit made after its source read the
 * entry. A site whose sources read entries through a projection of their
 * own binds that projection's hash.
 */
interface DraftBase
{
    public function hash(Entry $entry): string;
}
