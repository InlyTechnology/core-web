<?php

namespace Inly\CoreWeb\Drafts;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Revisions\Revision as RevisionContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Revision;

/**
 * The drafts a source made (DraftProvenance) that nobody has published yet,
 * in the collections sources may draft: every working copy still carrying a
 * provenance, and every unpublished entry nobody has saved since a source's
 * revision. Read from the revisions store, so it works on the Stache and the
 * Eloquent driver alike.
 */
class OpenDrafts
{
    /**
     * Newest first, only the entries the user may view, at most `$limit` of them.
     *
     * @param  list<string>  $via  the sources to keep; every source when empty
     * @return array{total: int, drafts: list<array{entry: EntryContract, title: string, via: string, message: ?string, at: ?CarbonInterface, edit_url: string, kind: 'working_copy'|'new_entry'}>}
     */
    public function for(?User $user, array $via = [], ?int $limit = null): array
    {
        $handles = $this->collections();

        if ($user === null || $handles === []) {
            return ['total' => 0, 'drafts' => []];
        }

        $found = [...$this->workingCopies($handles, $via), ...$this->newEntries($handles, $via)];
        $found = array_values(array_filter($found, fn (array $draft): bool => $user->can('view', $draft['entry'])));
        usort($found, fn (array $a, array $b): int => ($b['revision']->date()?->getTimestamp() ?? 0) <=> ($a['revision']->date()?->getTimestamp() ?? 0));

        return [
            'total' => count($found),
            'drafts' => array_map(fn (array $draft): array => $this->row(...$draft), array_slice($found, 0, $limit)),
        ];
    }

    /** @return list<string> the draftable collections that keep revisions (on, and Statamic Pro) */
    private function collections(): array
    {
        return array_values(array_filter(
            DraftableCollections::handles(),
            fn (string $handle): bool => (bool) Collection::findByHandle($handle)?->revisionsEnabled(),
        ));
    }

    /**
     * @param  list<string>  $handles
     * @param  list<string>  $via
     * @return list<array{entry: EntryContract, revision: RevisionContract, kind: 'working_copy'}>
     */
    private function workingCopies(array $handles, array $via): array
    {
        $found = [];

        foreach (Revision::query()->where('action', 'working')->get() as $copy) {
            // Older Eloquent rows carry no id in their attributes; the key ends with it.
            $id = $copy->attributes()['id'] ?? Str::afterLast((string) $copy->key(), '/');

            if (! $this->keeps($copy, $via) || ! is_string($id) || ! ($entry = Entry::find($id)) || ! in_array($entry->collectionHandle(), $handles, true)) {
                continue;
            }

            $found[$entry->id()] = ['entry' => $entry, 'revision' => $copy, 'kind' => 'working_copy'];
        }

        return array_values($found);
    }

    /**
     * Unpublished entries whose latest revision a source wrote. An editor's CP
     * save of an unpublished entry writes no revision, only the entry, so one
     * modified after the revision is the editor's and leaves the list.
     *
     * @param  list<string>  $handles
     * @param  list<string>  $via
     * @return list<array{entry: EntryContract, revision: RevisionContract, kind: 'new_entry'}>
     */
    private function newEntries(array $handles, array $via): array
    {
        $found = [];

        foreach (Entry::query()->whereIn('collection', $handles)->where('published', false)->get() as $entry) {
            if ($entry->hasWorkingCopy() || ! $this->keeps($revision = $entry->latestRevision(), $via)) {
                continue;
            }

            $modified = $entry->lastModified()?->getTimestamp();

            if ($modified !== null && $revision->date() !== null && $modified > $revision->date()->getTimestamp()) {
                continue;
            }

            $found[] = ['entry' => $entry, 'revision' => $revision, 'kind' => 'new_entry'];
        }

        return $found;
    }

    /** @param  list<string>  $via */
    private function keeps(?RevisionContract $revision, array $via): bool
    {
        $source = DraftProvenance::via($revision);

        return $source !== null && ($via === [] || in_array($source, $via, true));
    }

    /** @param  'working_copy'|'new_entry'  $kind */
    private function row(EntryContract $entry, RevisionContract $revision, string $kind): array
    {
        $message = trim((string) $revision->message());
        $title = $kind === 'working_copy' ? ($revision->attributes()['data']['title'] ?? null) : null;

        // The source's message names the person it drafted for, where the CP account may be a shared one.
        if ($message === '' && $user = $revision->user()) {
            $message = (string) ($user->name() ?: $user->email());
        }

        return [
            'entry' => $entry,
            'title' => (string) ($title ?? $entry->value('title') ?? $entry->id()),
            'via' => (string) DraftProvenance::via($revision),
            'message' => $message !== '' ? $message : null,
            'at' => $revision->date(),
            'edit_url' => (string) $entry->editUrl(),
            'kind' => $kind,
        ];
    }
}
