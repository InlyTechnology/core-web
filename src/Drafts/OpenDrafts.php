<?php

namespace Inly\CoreWeb\Drafts;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Inly\CoreWeb\Proposals\Proposal;
use Inly\CoreWeb\Proposals\Proposals;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Revisions\Revision as RevisionContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Revision;

/**
 * What a source made (DraftProvenance) that nobody has decided yet: in the
 * collections sources may draft, every working copy still carrying a
 * provenance and every unpublished entry nobody has saved since a source's
 * revision; and every open proposal, the draft of content without revisions
 * (Proposals). Entries are read from the revisions store, so it works on the
 * Stache and the Eloquent driver alike.
 */
class OpenDrafts
{
    public function __construct(private Proposals $proposals) {}

    /**
     * Newest first, only what the user may view (an entry) or apply (a
     * proposal), at most `$limit` of them.
     *
     * @param  list<string>  $via  the sources to keep; every source when empty
     * @return array{total: int, drafts: list<array{title: string, via: string, message: ?string, at: ?CarbonInterface, edit_url: string, kind: 'working_copy'|'new_entry'|'proposal', stale: bool}>}
     */
    public function for(?User $user, array $via = [], ?int $limit = null): array
    {
        if ($user === null) {
            return ['total' => 0, 'drafts' => []];
        }

        $handles = $this->collections();
        $entries = $handles === [] ? [] : [...$this->workingCopies($handles, $via), ...$this->newEntries($handles, $via)];
        $entries = array_filter($entries, fn (array $draft): bool => $user->can('view', $draft['entry']));

        $found = [
            ...array_map(fn (array $draft): array => ['at' => $draft['revision']->date(), 'row' => fn (): array => $this->row(...$draft)], $entries),
            ...$this->openProposals($user, $via),
        ];
        usort($found, fn (array $a, array $b): int => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        return [
            'total' => count($found),
            'drafts' => array_map(fn (array $item): array => ($item['row'])(), array_slice($found, 0, $limit)),
        ];
    }

    /**
     * Open proposals on targets the user may apply them to.
     *
     * @param  list<string>  $via
     * @return list<array{at: CarbonInterface, row: \Closure(): array<string, mixed>}>
     */
    private function openProposals(User $user, array $via): array
    {
        $found = [];

        foreach ($this->proposals->open() as $proposal) {
            $source = $proposal->provenance['via'] ?? null;

            if (! is_string($source) || ($via !== [] && ! in_array($source, $via, true)) || ! ($target = $this->proposals->target($proposal)) || ! $target->mayEdit($user)) {
                continue;
            }

            $found[] = ['at' => $proposal->createdAt, 'row' => fn (): array => $this->proposalRow($proposal, $source)];
        }

        return $found;
    }

    private function proposalRow(Proposal $proposal, string $source): array
    {
        $described = $this->proposals->describe($proposal, undoable: false);
        $message = trim($proposal->message);

        return [
            'title' => (string) ($described['label'] ?? $proposal->target),
            'via' => $source,
            'message' => $message !== '' ? $message : null,
            'at' => $proposal->createdAt,
            'edit_url' => (string) $described['cp_url'],
            'kind' => 'proposal',
            'stale' => (bool) $described['stale'],
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
            'title' => (string) ($title ?? $entry->value('title') ?? $entry->id()),
            'via' => (string) DraftProvenance::via($revision),
            'message' => $message !== '' ? $message : null,
            'at' => $revision->date(),
            'edit_url' => (string) $entry->editUrl(),
            'kind' => $kind,
            'stale' => false,
        ];
    }
}
