<?php

namespace Inly\CoreWeb\Checks;

use Illuminate\Support\Arr;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftBase;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Drafts\DraftWriter;
use Inly\CoreWeb\Drafts\EntryFields;
use Inly\CoreWeb\Drafts\StoredValues;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\Targets\NavTarget;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Fields\Blueprint;

/**
 * For a check whose findings are links that should be entry references: what
 * a link becomes in the field it sits in, and the fixes that write every
 * fixable finding of an entry as one draft and of a navigation tree as one
 * proposal. An entry finding's fix is its `details.replacement`, a branch's
 * is its `details.entry_id`.
 */
trait PointsLinksAtEntries
{
    /** @return list<Finding> */
    abstract private function inEntry(EntryContract $entry): array;

    /**
     * @param  array<mixed>  $branches
     * @return list<Finding>
     */
    abstract private function inTree(string $handle, string $site, array $branches, string $prefix = ''): array;

    /** What the link becomes, when the field it sits in can hold an entry reference. */
    private function replacement(?Blueprint $blueprint, string $path, string $id, string $suffix): ?string
    {
        if (str_ends_with($path, '.attrs.href')) {
            return "statamic://entry::{$id}{$suffix}";
        }

        // A link field stores `entry::{id}` and nothing after it.
        if (! str_contains($path, '.') && $suffix === '' && $blueprint?->field($path)?->type() === 'link') {
            return "entry::{$id}";
        }

        return null;
    }

    /** Whether the navigation may hold the entry, which a branch pointing at it needs. */
    private function navHolds(string $handle, EntryContract $entry): bool
    {
        return Nav::find($handle)?->collections()->contains(fn ($collection) => $collection->handle() === $entry->collectionHandle()) ?? false;
    }

    /** Every fixable link of the entry at once, as one draft. */
    private function fixEntry(string $id, DraftAuthor $author): array
    {
        $entry = Entry::find($id) ?? abort(404);
        $fixes = array_filter($this->inEntry($entry), fn (Finding $finding) => $finding->fixable);

        if ($fixes === []) {
            throw new DraftRefused('not_fixable', __('core-web::drafts.nothing_to_fix'), status: 422);
        }

        $live = EntryFields::stored($entry);
        $data = EntryFields::stored(EntryFields::draftOf($entry));

        foreach ($fixes as $fix) {
            Arr::set($data, $fix->path, $fix->details['replacement']);
        }

        $tops = array_unique(array_map(fn (Finding $fix) => explode('.', $fix->path)[0], $fixes));
        // The author's own working copy restates what it changed already, so nothing it holds is lost.
        $restated = array_keys(array_filter($data, fn (mixed $value, string $key) => ($live[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        return app(DraftWriter::class)->write($author, [
            'collection' => $entry->collectionHandle(),
            'entry_id' => (string) $entry->id(),
            'site' => $entry->locale(),
            'fields' => Arr::only($data, array_values(array_unique([...$tops, ...$restated]))),
            'base_hash' => app(DraftBase::class)->hash($entry),
        ], new StoredValues);
    }

    /** Every fixable branch of the tree at once, as one proposal. */
    private function fixTree(string $handle, string $site, DraftAuthor $author): array
    {
        $target = NavTarget::make($handle, $site);
        $tree = $target->current();
        $fixes = array_filter($this->inTree($handle, $site, $tree), fn (Finding $finding) => $finding->fixable);

        if ($fixes === []) {
            throw new DraftRefused('not_fixable', __('core-web::drafts.nothing_to_fix'), status: 422);
        }

        $base = Proposals::base($target);

        foreach ($fixes as $fix) {
            $branch = Arr::get($tree, $fix->path);
            unset($branch['url']);
            $branch['entry'] = $fix->details['entry_id'];
            Arr::set($tree, $fix->path, $branch);
        }

        return app(Proposals::class)->propose($author, $target, $tree, $base);
    }
}
