<?php

namespace Inly\CoreWeb\Drafts;

use Illuminate\Support\Arr;
use Statamic\Contracts\Entries\Entry;

/**
 * An entry's fields as a draft reads and writes them: its stored values
 * without the entry's bookkeeping, with its slug and date.
 */
final class EntryFields
{
    /** Stored keys that are the entry's bookkeeping, never a draft's. */
    public const INTERNAL = ['id', 'blueprint', 'updated_by', 'updated_at', 'published', 'origin'];

    /** @return array<string, mixed> */
    public static function of(Entry $entry): array
    {
        $format = $entry->hasTime() ? 'Y-m-d H:i' : 'Y-m-d';

        return [
            ...self::stored($entry),
            'slug' => $entry->slug(),
            'date' => $entry->hasDate() ? $entry->date()?->format($format) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function stored(Entry $entry): array
    {
        return Arr::except($entry->data()->all(), self::INTERNAL);
    }

    /** The version a draft is built on: the working copy when the entry has one, else the entry. */
    public static function draftOf(Entry $entry): Entry
    {
        return $entry->revisionsEnabled() && $entry->hasWorkingCopy() ? $entry->fromWorkingCopy() : $entry;
    }
}
