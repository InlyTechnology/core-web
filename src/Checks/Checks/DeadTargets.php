<?php

namespace Inly\CoreWeb\Checks\Checks;

use Inly\CoreWeb\Checks\Check;
use Inly\CoreWeb\Checks\ContentWalk;
use Inly\CoreWeb\Checks\Finding;
use Inly\CoreWeb\Drafts\EntryFields;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;

/**
 * References to entries and assets that no longer exist: a link field or a
 * Bard link to a deleted page (`entry::{id}`, `statamic://entry::{id}`),
 * an asset reference to a deleted file, a navigation branch whose entry is
 * gone. Visitors meet them as dead links or missing images; which page or
 * file should take their place is an editor's call.
 */
class DeadTargets extends Check
{
    private const REFERENCE = '~^(?:statamic://)?(entry|asset)::([^?#]+)~';

    public function key(): string
    {
        return 'dead-targets';
    }

    public function description(): string
    {
        return 'Links and references to pages and files that no longer exist.';
    }

    public function detect(): iterable
    {
        foreach (ContentWalk::entries() as $entry) {
            yield from $this->inEntry($entry);
        }

        foreach (ContentWalk::navTrees() as $nav) {
            yield from $this->inTree($nav['handle'], $nav['site'], $nav['tree']);
        }
    }

    /** @return iterable<Finding> */
    private function inEntry(EntryContract $entry): iterable
    {
        foreach (ContentWalk::strings(EntryFields::stored(EntryFields::draftOf($entry))) as $string) {
            if (preg_match(self::REFERENCE, $string['value'], $m) !== 1 || self::exists($m[1], $m[2])) {
                continue;
            }

            yield new Finding(
                check: $this->key(),
                subject: 'entry:'.$entry->id(),
                path: $string['path'],
                message: $m[1] === 'entry' ? 'Links to a page that no longer exists.' : 'Refers to a file that no longer exists.',
                site: $entry->locale(),
                label: (string) $entry->value('title'),
                cpUrl: $entry->editUrl(),
                details: ['found' => $string['value']],
            );
        }
    }

    /**
     * @param  array<mixed>  $branches
     * @return iterable<Finding>
     */
    private function inTree(string $handle, string $site, array $branches, string $prefix = ''): iterable
    {
        foreach (array_values($branches) as $index => $branch) {
            $at = ltrim("{$prefix}.{$index}", '.');

            if (filled($branch['entry'] ?? null) && Entry::find((string) $branch['entry']) === null) {
                yield new Finding(
                    check: $this->key(),
                    subject: "nav:{$handle}:{$site}",
                    path: $at,
                    message: 'A menu item points at a page that no longer exists.',
                    site: $site,
                    label: Nav::find($handle)?->title(),
                    cpUrl: Nav::find($handle)?->showUrl(),
                    details: ['found' => (string) $branch['entry']],
                );
            }

            if (is_array($branch['children'] ?? null)) {
                yield from $this->inTree($handle, $site, $branch['children'], "{$at}.children");
            }
        }
    }

    private static function exists(string $type, string $id): bool
    {
        return $type === 'entry' ? Entry::find($id) !== null : Asset::find($id) !== null;
    }
}
