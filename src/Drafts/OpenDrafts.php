<?php

namespace Inly\CoreWeb\Drafts;

use Carbon\CarbonInterface;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Revisions\Revision as RevisionContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Revision;

/**
 * The drafts a source made (DraftProvenance) that nobody has published yet:
 * every working copy still carrying a provenance, and every unpublished entry
 * whose latest revision is the source's. Read from the revisions store, so it
 * works on the Stache and the Eloquent driver alike.
 */
class OpenDrafts
{
    /**
     * Newest first, only the entries the user may view.
     *
     * @param  list<string>  $via  the sources to keep; every source when empty
     * @return list<array{entry: EntryContract, title: string, via: string, message: ?string, at: ?CarbonInterface, edit_url: string, kind: 'working_copy'|'new_entry'}>
     */
    public function for(?User $user, array $via = []): array
    {
        if ($user === null || ! config('statamic.revisions.enabled')) {
            return [];
        }

        $drafts = [...$this->workingCopies($via), ...$this->newEntries($via)];

        $drafts = array_values(array_filter($drafts, fn (array $draft): bool => $user->can('view', $draft['entry'])));
        usort($drafts, fn (array $a, array $b): int => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        return $drafts;
    }

    /** @param  list<string>  $via */
    private function workingCopies(array $via): array
    {
        $drafts = [];

        foreach (Revision::query()->where('action', 'working')->get() as $copy) {
            if (! $this->keeps($copy, $via) || ! is_string($id = $copy->attributes()['id'] ?? null) || ! $entry = Entry::find($id)) {
                continue;
            }

            $drafts[$entry->id()] = $this->row($entry, $copy, 'working_copy', (string) ($copy->attributes()['data']['title'] ?? $entry->get('title') ?? $entry->id()));
        }

        return array_values($drafts);
    }

    /**
     * Unpublished entries whose latest revision a source wrote. An editor's
     * CP save of an entry without a working copy writes a revision of its own,
     * so the entry then counts as the editor's.
     *
     * @param  list<string>  $via
     */
    private function newEntries(array $via): array
    {
        $handles = Collection::all()->filter(fn ($collection) => $collection->revisionsEnabled())->map->handle()->values()->all();

        if ($handles === []) {
            return [];
        }

        $drafts = [];

        foreach (Entry::query()->whereIn('collection', $handles)->where('published', false)->get() as $entry) {
            if ($entry->hasWorkingCopy() || ! $this->keeps($revision = $entry->latestRevision(), $via)) {
                continue;
            }

            $drafts[] = $this->row($entry, $revision, 'new_entry', (string) ($entry->get('title') ?? $entry->id()));
        }

        return $drafts;
    }

    /** @param  list<string>  $via */
    private function keeps(?RevisionContract $revision, array $via): bool
    {
        $source = DraftProvenance::via($revision);

        return $source !== null && ($via === [] || in_array($source, $via, true));
    }

    /** @param  'working_copy'|'new_entry'  $kind */
    private function row(EntryContract $entry, RevisionContract $revision, string $kind, string $title): array
    {
        $user = $revision->user();
        $message = trim((string) $revision->message());

        return [
            'entry' => $entry,
            'title' => $title,
            'via' => (string) DraftProvenance::via($revision),
            // The source's message names the person it drafted for, where the CP account may be a shared one.
            'message' => $message !== '' ? $message : ($user === null ? null : (string) ($user->name() ?: $user->email())),
            'at' => $revision->date(),
            'edit_url' => (string) $entry->editUrl(),
            'kind' => $kind,
        ];
    }
}
