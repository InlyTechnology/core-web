<?php

namespace Inly\CoreWeb\Translation;

use Statamic\Events\EntrySaving;

/**
 * Keeps following translations aligned on every save. An original's save
 * reaches them too, since Statamic saves an entry's direct descendants
 * after it.
 */
final class AlignTranslations
{
    public function saving(EntrySaving $event): void
    {
        $event->entry->remove(SyncedTranslations::SEEN);

        if (SyncedTranslations::follows($event->entry)) {
            SyncedTranslations::align($event->entry);
        }
    }
}
