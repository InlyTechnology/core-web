<?php

namespace Inly\CoreWeb\Checks;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/** Typed links to the site's own pages: which entry a URL or path names, and what is left of it (a query, an anchor). */
final class SiteLinks
{
    /**
     * The entry a link resolves to on one of the site's sites, with the
     * query and anchor after its path; null for anything else (another
     * host, an entry reference already, mailto:, an anchor alone).
     *
     * @param  string|null  $site  the site a relative path is read on
     * @return array{entry: EntryContract, suffix: string}|null
     */
    public static function resolve(string $value, ?string $site): ?array
    {
        $value = trim($value);

        if ($value === '' || str_starts_with($value, '//') || str_starts_with($value, '#') || str_contains($value, '::') || preg_match('~^[a-z][a-z0-9+.-]*:(?!//)~i', $value) === 1) {
            return null;
        }

        [$site, $path] = self::locate($value, $site) ?? [null, null];

        if ($site === null) {
            return null;
        }

        $suffix = '';

        if (preg_match('~^([^?#]*)([?#].*)$~', $path, $m) === 1) {
            [$path, $suffix] = [$m[1], $m[2]];
        }

        $uri = '/'.trim($path, '/');
        $entry = Entry::findByUri($uri, $site);

        return $entry === null ? null : ['entry' => $entry, 'suffix' => $suffix];
    }

    /** @return array{0: string, 1: string}|null the site and the path on it */
    private static function locate(string $value, ?string $site): ?array
    {
        if (str_starts_with($value, '/')) {
            return $site === null ? null : self::onSite($site, $value);
        }

        if (preg_match('~^https?://~i', $value) !== 1) {
            return null;
        }

        // The site whose URL the link starts with, the longest first, so a site under a path of another's host wins.
        $match = Site::all()
            ->sortByDesc(fn ($candidate) => strlen((string) $candidate->absoluteUrl()))
            ->first(fn ($candidate) => self::startsWith($value, rtrim((string) $candidate->absoluteUrl(), '/')));

        if ($match === null) {
            return null;
        }

        return [$match->handle(), substr($value, strlen(rtrim((string) $match->absoluteUrl(), '/'))) ?: '/'];
    }

    /** @return array{0: string, 1: string}|null */
    private static function onSite(string $site, string $path): ?array
    {
        $prefix = rtrim((string) parse_url((string) Site::get($site)?->absoluteUrl(), PHP_URL_PATH), '/');

        if ($prefix !== '' && ! self::startsWith($path, $prefix)) {
            return null;
        }

        return [$site, substr($path, strlen($prefix)) ?: '/'];
    }

    private static function startsWith(string $value, string $prefix): bool
    {
        if (! str_starts_with(strtolower($value), strtolower($prefix))) {
            return false;
        }

        $next = substr($value, strlen($prefix), 1);

        return $next === '' || in_array($next, ['/', '?', '#'], true);
    }
}
