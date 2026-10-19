<?php

namespace Inly\CoreWeb\Drafts;

use Inly\CoreWeb\Support\Fingerprint;
use Statamic\Contracts\Entries\Entry;

/** The hash of the fields a draft of the entry builds on (its working copy's, when it has one). */
class FieldsHash implements DraftBase
{
    public function hash(Entry $entry): string
    {
        return Fingerprint::of(EntryFields::of(EntryFields::draftOf($entry)));
    }
}
