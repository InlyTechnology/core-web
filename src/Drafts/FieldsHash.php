<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Contracts\Entries\Entry;

/** The hash of the fields a draft of the entry builds on (its working copy's, when it has one). */
class FieldsHash implements DraftBase
{
    public function hash(Entry $entry): string
    {
        return hash('sha256', json_encode(self::sorted(EntryFields::of(EntryFields::draftOf($entry))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Keys sorted at every depth, lists left in order, so the hash does not depend on the order fields were saved in. */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
