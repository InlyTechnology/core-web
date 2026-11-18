<?php

namespace Inly\CoreWeb\Checks\Checks;

use Illuminate\Support\Arr;
use Inly\CoreWeb\Checks\Check;
use Inly\CoreWeb\Checks\ContentWalk;
use Inly\CoreWeb\Checks\Finding;
use Inly\CoreWeb\Checks\PointsLinksAtEntries;
use Inly\CoreWeb\Checks\SiteLinks;
use Inly\CoreWeb\Drafts\DraftableCollections;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Drafts\EntryFields;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\Targets\GlobalTarget;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Globals\Variables;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;

/**
 * Links into web archives and search caches, which content pasted from an
 * archived copy of a page keeps. See docs/content-checks.md#archived-links.
 */
class ArchivedLinks extends Check
{
    use PointsLinksAtEntries;

    private const WAYBACK = ['web.archive.org', 'wayback.archive.org', 'archive.org'];

    private const ARCHIVE_TODAY = ['archive.today', 'archive.ph', 'archive.is', 'archive.li', 'archive.vn', 'archive.md'];

    private const CACHES = ['webcache.googleusercontent.com', 'cachedview.nl'];

    /** archive.today's own pages, which are no copy of anything. */
    private const ARCHIVE_TODAY_PAGES = ['submit', 'search', 'wip'];

    /** A Wayback snapshot path saved without its host, which 404s on the site: an address or a host and path follow the timestamp. */
    private const RELATIVE_WAYBACK = '~^/web/\d{4,14}(?:\*|[a-z]{2}_)?/(?:https?:|[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}/)~i';

    /** What ends a sentence after a link written in text, never part of the link; a `)` only when the link opens none. */
    private const TRAILING = '.,;:!?';

    public function key(): string
    {
        return 'archived-links';
    }

    public function description(): string
    {
        return 'Links to archived copies of pages (the Wayback Machine, archive.today, search caches) instead of the pages themselves.';
    }

    public function detect(): iterable
    {
        foreach (ContentWalk::entries() as $entry) {
            yield from $this->inEntry($entry);
        }

        foreach (ContentWalk::globals() as $global) {
            yield from $this->inGlobal($global['handle'], $global['site'], $global['variables']);
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

        return $this->inValues(EntryFields::stored($draft), $draft->blueprint(), $entry->locale(), $writable, false, fn (string $path, string $message, array $details, bool $fixable) => new Finding(
            check: $this->key(),
            subject: 'entry:'.$entry->id(),
            path: $path,
            message: $message,
            site: $entry->locale(),
            label: (string) $entry->value('title'),
            cpUrl: $entry->editUrl(),
            details: $details,
            fixable: $fixable,
        ));
    }

    /** @return list<Finding> */
    private function inGlobal(string $handle, string $site, Variables $variables): array
    {
        // A global's proposal holds only its blueprint's fields, so a link under any other key is an editor's.
        return $this->inValues($variables->data()->all(), $variables->blueprint(), $site, true, true, fn (string $path, string $message, array $details, bool $fixable) => new Finding(
            check: $this->key(),
            subject: "global:{$handle}:{$site}",
            path: $path,
            message: $message,
            site: $site,
            label: $variables->globalSet()?->title(),
            cpUrl: $variables->editUrl(),
            details: $details,
            fixable: $fixable,
        ));
    }

    /**
     * Every archive link in stored values, fixable where the whole value is the link and its field can hold an entry reference.
     *
     * @param  array<mixed>  $values
     * @param  bool  $blueprintFieldsOnly  whether only a value under one of the blueprint's fields can be fixed
     * @param  callable(string, string, array<string, mixed>, bool): Finding  $finding  made from the path, message, details and fixable
     * @return list<Finding>
     */
    private function inValues(array $values, ?Blueprint $blueprint, string $site, bool $writable, bool $blueprintFieldsOnly, callable $finding): array
    {
        $findings = [];

        foreach (ContentWalk::strings($values) as $string) {
            $inField = ! $blueprintFieldsOnly || ($blueprint?->hasField(explode('.', $string['path'])[0]) ?? false);

            foreach (self::links($string['value']) as $found) {
                $archive = self::archive($found);
                $resolved = self::resolve($archive['original'], $site);
                $replacement = $resolved !== null && $found === self::trimTrailing($string['value'])
                    ? $this->replacement($blueprint, $string['path'], (string) $resolved['entry']->id(), $resolved['suffix'])
                    : null;
                $fixable = $writable && $inField && $replacement !== null;

                $findings[] = $finding(
                    $string['path'],
                    self::message($archive, $resolved['entry'] ?? null, $fixable),
                    [...self::details($found, $archive, $resolved), 'replacement' => $replacement],
                    $fixable,
                );
            }
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
            $url = self::trimTrailing((string) ($branch['url'] ?? ''));
            $archive = $url !== '' && blank($branch['entry'] ?? null) ? self::archive($url) : null;

            if ($archive !== null) {
                $resolved = self::resolve($archive['original'], $site);
                $fixable = $resolved !== null && $resolved['suffix'] === '' && $this->navHolds($handle, $resolved['entry']);

                $findings[] = new Finding(
                    check: $this->key(),
                    subject: "nav:{$handle}:{$site}",
                    path: $at,
                    message: 'The menu item “'.($branch['title'] ?? $url).'”: '.lcfirst(self::message($archive, $resolved['entry'] ?? null, $fixable)),
                    site: $site,
                    label: Nav::find($handle)?->title(),
                    cpUrl: Nav::find($handle)?->showUrl(),
                    details: [...self::details($url, $archive, $resolved), 'suffix' => $resolved['suffix'] ?? null],
                    fixable: $fixable,
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

        [$kind, $handle, $site] = explode(':', $finding->subject) + [null, null, null];

        if ($kind === 'global') {
            return $this->fixGlobal((string) $handle, (string) $site, $author);
        }

        return $this->fixTree((string) $handle, (string) $site, $author);
    }

    /** Every fixable link of the global's values at once, as one proposal. */
    private function fixGlobal(string $handle, string $site, DraftAuthor $author): array
    {
        $target = GlobalTarget::make($handle, $site);
        $fixes = array_filter($this->inGlobal($handle, $site, GlobalSet::find($handle)->in($site)), fn (Finding $finding) => $finding->fixable);

        if ($fixes === []) {
            throw new DraftRefused('not_fixable', __('core-web::drafts.nothing_to_fix'), status: 422);
        }

        $base = Proposals::base($target);
        $value = $target->current();

        foreach ($fixes as $fix) {
            Arr::set($value, $fix->path, $fix->details['replacement']);
        }

        return app(Proposals::class)->propose($author, $target, $value, $base);
    }

    /** A link without the punctuation that ends a sentence after it: a closing `)` stays when the link opened one (`Foo_(bar)`). */
    private static function trimTrailing(string $value): string
    {
        $value = trim($value);

        do {
            $before = $value;
            $value = rtrim($value, self::TRAILING);

            if (str_ends_with($value, ')') && substr_count($value, ')') > substr_count($value, '(')) {
                $value = substr($value, 0, -1);
            }
        } while ($value !== $before);

        return $value;
    }

    /**
     * The archive links in a string: the string itself when it is one link with nothing around it, or each one written in its text.
     *
     * @return list<string>
     */
    private static function links(string $value): array
    {
        $value = trim($value);
        $whole = self::trimTrailing($value);

        if (preg_match('~\s~', $whole) !== 1 && self::archive($whole) !== null) {
            return [$whole];
        }

        if (preg_match_all('~https?://[^\s"\'<>\[\]{}|\\\\^`]+~i', $value, $m) === 0) {
            return [];
        }

        $links = array_map(fn (string $url) => self::trimTrailing($url), $m[0]);

        return array_values(array_unique(array_filter($links, fn (string $url) => self::archive($url) !== null)));
    }

    /**
     * The archive a URL points into and the address it archived, when it can be read from the URL; null for any other URL.
     *
     * @return array{host: string, original: ?string}|null
     */
    private static function archive(string $url): ?array
    {
        if (preg_match(self::RELATIVE_WAYBACK, $url) === 1) {
            return ['host' => 'web.archive.org', 'original' => self::wayback($url)];
        }

        if (preg_match('~^https?://~i', $url) !== 1) {
            return null;
        }

        $host = self::bareHost($url);
        $rest = (string) preg_replace('~^https?://[^/?#]*~i', '', $url);

        // Only what follows the Wayback Machine's /web/ is an archived copy; archive.org's other pages, and /web/ itself, are its own.
        if (in_array($host, self::WAYBACK, true)) {
            return preg_match('~^/web/[^?#]~i', $rest) === 1 ? ['host' => $host, 'original' => self::wayback($rest)] : null;
        }

        // The front page of an archive or a cache is not a copy of anything.
        if (ltrim($rest, '/') === '') {
            return null;
        }

        if (in_array($host, self::ARCHIVE_TODAY, true) && self::archiveTodayPage($rest)) {
            return null;
        }

        return match (true) {
            $host === 'webcache.googleusercontent.com' => ['host' => $host, 'original' => self::googleCache($url) ?? self::embedded($rest)],
            in_array($host, [...self::ARCHIVE_TODAY, ...self::CACHES], true) => ['host' => $host, 'original' => self::embedded($rest)],
            default => null,
        };
    }

    /** Whether an archive.today address is the archive's own page: its front page with a query, or submit, search and wip. */
    private static function archiveTodayPage(string $rest): bool
    {
        $segment = strtolower(explode('/', trim((string) parse_url('http://x'.$rest, PHP_URL_PATH), '/'))[0]);

        return $segment === '' || in_array($segment, self::ARCHIVE_TODAY_PAGES, true);
    }

    /** `/web/20250512152946if_/https://site.se/page/` is `https://site.se/page/`. */
    private static function wayback(string $rest): ?string
    {
        if (preg_match('~^/web/(?:(?:\d{1,14}\*?|\*)(?:[a-z]{2}_)?/)?(.+)$~i', $rest, $m) !== 1) {
            return null;
        }

        return self::address($m[1]);
    }

    /** `search?q=cache:{id}:https://site.se/page/+site` is `https://site.se/page/`. */
    private static function googleCache(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $q = is_string($query['q'] ?? null) ? $query['q'] : '';

        if (preg_match('~^cache:(?:[\w-]{8,16}:(?!//))?(\S+)~i', $q, $m) !== 1) {
            return null;
        }

        return self::address($m[1]);
    }

    /** An address written inside an archive URL (`archive.ph/2024.05.12-152946/https://site.se/page/`). */
    private static function embedded(string $rest): ?string
    {
        if (preg_match('~(https?(?::|%3a)(?:/|%2f).+)$~i', $rest, $m) !== 1) {
            return null;
        }

        return self::address($m[1]);
    }

    /** The archived address as a URL: decoded, its scheme's slashes restored, `http://` before a bare host. */
    private static function address(string $value): ?string
    {
        if (preg_match('~^https?%3a~i', $value) === 1) {
            $value = rawurldecode($value);
        }

        if (preg_match('~^(https?):/+(.*)$~i', $value, $m) === 1) {
            return $m[2] === '' ? null : strtolower($m[1]).'://'.$m[2];
        }

        if (preg_match('~^[a-z0-9-]+(?:\.[a-z0-9-]+)+(?:[:/?#]|$)~i', $value) === 1) {
            return 'http://'.$value;
        }

        return null;
    }

    /**
     * The entry an archived address names, as own-url-links resolves a typed URL. An archive keeps the scheme and
     * host the page had then, so an address on a site's host, with or without `www.`, is read again on the site's
     * own scheme and host.
     *
     * @return array{entry: EntryContract, suffix: string}|null
     */
    private static function resolve(?string $original, string $site): ?array
    {
        if ($original === null) {
            return null;
        }

        if (($resolved = SiteLinks::resolve($original, $site)) !== null) {
            return $resolved;
        }

        $host = self::bareHost($original);

        foreach (Site::all() as $candidate) {
            $base = (string) $candidate->absoluteUrl();

            if ($host === '' || self::bareHost($base) !== $host || preg_match('~^https?://[^/?#]*~i', $base, $m) !== 1) {
                continue;
            }

            $resolved = SiteLinks::resolve((string) preg_replace('~^https?://[^/?#]*~i', $m[0], $original), $site);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private static function bareHost(string $url): string
    {
        return (string) preg_replace('~^www\.~', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    /** @param  array{host: string, original: ?string}  $archive */
    private static function message(array $archive, ?EntryContract $entry, bool $fixable): string
    {
        if ($entry !== null) {
            $action = $fixable ? 'point it at the entry' : 'link the page itself instead';

            return "Links to an archived copy of “{$entry->value('title')}” on {$archive['host']}; {$action}.";
        }

        if ($archive['original'] !== null) {
            return "Links to an archived copy of {$archive['original']} on {$archive['host']}; link the page itself, or remove the link.";
        }

        return "Links to an archived copy on {$archive['host']}; link the page itself, or remove the link.";
    }

    /**
     * @param  array{host: string, original: ?string}  $archive
     * @param  array{entry: EntryContract, suffix: string}|null  $resolved
     * @return array<string, mixed>
     */
    private static function details(string $found, array $archive, ?array $resolved): array
    {
        return [
            'found' => $found,
            'archive' => $archive['host'],
            'original' => $archive['original'],
            'entry_id' => $resolved === null ? null : (string) $resolved['entry']->id(),
        ];
    }
}
