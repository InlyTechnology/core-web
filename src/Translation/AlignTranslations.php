<?php

namespace Inly\CoreWeb\Translation;

use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;

/**
 * Keeps following translations aligned: a translation's own save takes the
 * original's blocks with its texts, and an original's save carries its
 * blocks and shared values to each translation that follows it.
 */
final class AlignTranslations
{
    public function saving(EntrySaving $event): void
    {
        if (SyncedTranslations::follows($event->entry)) {
            SyncedTranslations::align($event->entry);
        }
    }

    public function saved(EntrySaved $event): void
    {
        foreach ($event->entry->directDescendants() as $translation) {
            if (SyncedTranslations::follows($translation) && SyncedTranslations::align($translation)) {
                $translation->save();
            }
        }
    }
}
