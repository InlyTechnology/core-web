<?php

namespace Inly\CoreWeb\Content\Feed;

use Inly\CoreWeb\Drafts\DraftableCollections;
use Statamic\Contracts\Entries\Entry;

/** The collections the content feed serves: `content.feed.collections`, or the drafted ones when it names none. */
final class FeedCollections
{
    /** @return list<string> */
    public static function handles(): array
    {
        $named = array_values(array_map('strval', (array) config('core-web.content.feed.collections', [])));

        return $named !== [] ? $named : DraftableCollections::handles();
    }

    public static function serves(mixed $entry): bool
    {
        return $entry instanceof Entry && in_array($entry->collectionHandle(), self::handles(), true);
    }
}
