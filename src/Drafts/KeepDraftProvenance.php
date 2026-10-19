<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Events\RevisionSaving;
use Statamic\Facades\Revision;

/**
 * A working copy a source drafted stays marked as one when an editor saves it
 * in the CP: the CP builds every save's working copy afresh, without the
 * draft's provenance or message, so both are carried over from the copy it
 * replaces. Publishing or discarding the copy ends it.
 */
class KeepDraftProvenance
{
    public function handle(RevisionSaving $event): void
    {
        $revision = $event->revision;

        if ($revision->action() !== 'working' || DraftProvenance::of($revision) !== null) {
            return;
        }

        $previous = Revision::findWorkingCopyByKey($revision->key());
        $provenance = DraftProvenance::of($previous);

        if ($provenance === null) {
            return;
        }

        DraftProvenance::stamp($revision, $provenance);

        if (blank($revision->message())) {
            $revision->message($previous->message());
        }
    }
}
