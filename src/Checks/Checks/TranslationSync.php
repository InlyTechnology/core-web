<?php

namespace Inly\CoreWeb\Checks\Checks;

use Inly\CoreWeb\Checks\Check;
use Inly\CoreWeb\Checks\ContentWalk;
use Inly\CoreWeb\Checks\Finding;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Drafts\DraftableCollections;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftBase;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Drafts\DraftWriter;
use Inly\CoreWeb\Drafts\EntryFields;
use Inly\CoreWeb\Drafts\StoredValues;
use Inly\CoreWeb\Translation\FieldShape;
use Inly\CoreWeb\Translation\SyncedTranslations;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Translations that follow their original (the `translation` feature) and no
 * longer match it: blocks that drifted in through an import or a file edit,
 * which a fix realigns as a draft, and texts that changed in the original
 * since they were translated or were never translated, left to an editor.
 */
class TranslationSync extends Check
{
    public function key(): string
    {
        return 'translation-sync';
    }

    public function description(): string
    {
        return 'Translations that follow their original but whose blocks or texts no longer match it.';
    }

    public function detect(): iterable
    {
        if (! CoreWebServiceProvider::enabled('translation')) {
            return;
        }

        foreach (ContentWalk::entries() as $entry) {
            $draft = EntryFields::draftOf($entry);

            if (SyncedTranslations::follows($draft)) {
                yield from $this->inEntry($entry, $draft);
            }
        }
    }

    /** @return list<Finding> */
    private function inEntry(EntryContract $entry, EntryContract $draft): array
    {
        $status = SyncedTranslations::status($draft);
        $writable = DraftableCollections::writable($entry->collectionHandle()) && $entry->revisionsEnabled();
        $findings = [];

        foreach ($status['drift'] as $handle) {
            $findings[] = $this->finding($entry, $handle, 'Its blocks no longer match the original it follows.', ['found' => 'drift'], $writable);
        }

        foreach (['outdated' => ':count texts changed in the original since they were translated.', 'untranslated' => ':count texts are not translated yet.'] as $kind => $message) {
            if ($status[$kind] !== []) {
                $findings[] = $this->finding($entry, $kind, str_replace(':count', (string) count($status[$kind]), $message), ['found' => $status[$kind]], false);
            }
        }

        return $findings;
    }

    /** @param  array<string, mixed>  $details */
    private function finding(EntryContract $entry, string $path, string $message, array $details, bool $fixable): Finding
    {
        return new Finding(
            check: $this->key(),
            subject: 'entry:'.$entry->id(),
            path: $path,
            message: $message,
            site: $entry->locale(),
            label: (string) $entry->value('title'),
            cpUrl: $entry->editUrl(),
            details: $details,
            fixable: $fixable,
        );
    }

    /** Realigns every drifted field of the entry at once, as one draft. */
    public function fix(Finding $finding, DraftAuthor $author): array
    {
        $entry = Entry::find(substr($finding->subject, strlen('entry:'))) ?? abort(404);
        $draft = EntryFields::draftOf($entry);

        if (! $finding->fixable || ! SyncedTranslations::follows($draft) || SyncedTranslations::status($draft)['drift'] === []) {
            throw new DraftRefused('not_fixable', __('core-web::drafts.nothing_to_fix'), status: 422);
        }

        SyncedTranslations::align($draft);
        $data = EntryFields::stored($draft);
        // The sources are recomputed when the draft is published, as every save of a following translation does.
        $handles = array_keys(FieldShape::synced($entry->blueprint()));

        return app(DraftWriter::class)->write($author, [
            'collection' => $entry->collectionHandle(),
            'entry_id' => (string) $entry->id(),
            'site' => $entry->locale(),
            'fields' => array_intersect_key($data, array_flip($handles)),
            'base_hash' => app(DraftBase::class)->hash($entry),
        ], new StoredValues);
    }
}
