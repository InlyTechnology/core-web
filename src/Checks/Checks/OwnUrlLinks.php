<?php

namespace Inly\CoreWeb\Checks\Checks;

use Inly\CoreWeb\Checks\Check;
use Inly\CoreWeb\Checks\ContentWalk;
use Inly\CoreWeb\Checks\Finding;
use Inly\CoreWeb\Checks\PointsLinksAtEntries;
use Inly\CoreWeb\Checks\SiteLinks;
use Inly\CoreWeb\Drafts\DraftableCollections;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\EntryFields;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Nav;

/**
 * Links to the site's own pages typed as URLs, in entries and navigation
 * trees: they break silently when the page's slug changes, where an entry
 * reference follows it. A Bard link, a `link` field and a navigation branch
 * are fixed by pointing them at the entry (a Bard link keeps its query and
 * anchor); any other field is named for an editor.
 */
class OwnUrlLinks extends Check
{
    use PointsLinksAtEntries;

    private const LINK_KEYS = ['url', 'href', 'link'];

    public function key(): string
    {
        return 'own-url-links';
    }

    public function description(): string
    {
        return 'Links to the site\'s own pages typed as URLs, which break when a slug changes.';
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

    /** @return list<Finding> */
    private function inEntry(EntryContract $entry): array
    {
        $draft = EntryFields::draftOf($entry);
        $writable = DraftableCollections::writable($entry->collectionHandle()) && $entry->revisionsEnabled();
        $findings = [];

        foreach (ContentWalk::strings(EntryFields::stored($draft)) as $string) {
            if (! self::linkKey($string['key'])) {
                continue;
            }

            $resolved = SiteLinks::resolve($string['value'], $entry->locale());

            if ($resolved === null) {
                continue;
            }

            $replacement = $this->replacement($draft->blueprint(), $string['path'], $resolved['entry']->id(), $resolved['suffix']);

            $findings[] = new Finding(
                check: $this->key(),
                subject: 'entry:'.$entry->id(),
                path: $string['path'],
                message: "Links to “{$resolved['entry']->value('title')}” by URL; point it at the entry so it follows a slug change.",
                site: $entry->locale(),
                label: (string) $entry->value('title'),
                cpUrl: $entry->editUrl(),
                details: ['found' => $string['value'], 'entry_id' => (string) $resolved['entry']->id(), 'replacement' => $replacement],
                fixable: $writable && $replacement !== null,
            );
        }

        return $findings;
    }

    /**
     * @param  array<mixed>  $branches
     * @return list<Finding>
     */
    private function inTree(string $handle, string $site, array $branches, string $prefix = ''): array
    {
        $findings = [];

        foreach (array_values($branches) as $index => $branch) {
            $at = ltrim("{$prefix}.{$index}", '.');
            $resolved = filled($branch['url'] ?? null) && blank($branch['entry'] ?? null) ? SiteLinks::resolve((string) $branch['url'], $site) : null;

            if ($resolved !== null && $this->navHolds($handle, $resolved['entry'])) {
                $findings[] = new Finding(
                    check: $this->key(),
                    subject: "nav:{$handle}:{$site}",
                    path: $at,
                    message: 'The menu item “'.($branch['title'] ?? $branch['url'])."” links to “{$resolved['entry']->value('title')}” by URL; pick the entry instead.",
                    site: $site,
                    label: Nav::find($handle)?->title(),
                    cpUrl: Nav::find($handle)?->showUrl(),
                    details: ['found' => (string) $branch['url'], 'entry_id' => (string) $resolved['entry']->id(), 'suffix' => $resolved['suffix']],
                    fixable: $resolved['suffix'] === '',
                );
            }

            if (is_array($branch['children'] ?? null)) {
                array_push($findings, ...$this->inTree($handle, $site, $branch['children'], "{$at}.children"));
            }
        }

        return $findings;
    }

    public function fix(Finding $finding, DraftAuthor $author): array
    {
        if (str_starts_with($finding->subject, 'entry:')) {
            return $this->fixEntry(substr($finding->subject, strlen('entry:')), $author);
        }

        [, $handle, $site] = explode(':', $finding->subject) + [null, null, null];

        return $this->fixTree((string) $handle, (string) $site, $author);
    }

    private static function linkKey(string $key): bool
    {
        return in_array($key, self::LINK_KEYS, true) || str_ends_with($key, '_link') || str_ends_with($key, '_url');
    }
}
