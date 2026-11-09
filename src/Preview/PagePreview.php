<?php

namespace Inly\CoreWeb\Preview;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Inly\CoreWeb\Drafts\DraftPreview;
use Inly\CoreWeb\Drafts\EntryFields;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Core's preview frame of one of the site's own pages (Statamic connector API v1.7): a link the site signs itself, which
 * Core asks for sealed for reading and frames. It renders the page as it is in the CMS: its working copy when it has
 * one, else the entry, unpublished or scheduled too. See docs/object-preview.md#a-page-of-the-site.
 */
final class PagePreview
{
    public const ROUTE = 'core-web.preview.page';

    /** Core asks for a fresh link each time the frame opens, so a day outlasts a screen left open. */
    private const HOURS = 24;

    /** The link Core frames, or null for an entry with no page on the site. */
    public static function link(EntryContract $entry): ?string
    {
        if (blank(EntryFields::draftOf($entry)->absoluteUrl())) {
            return null;
        }

        $path = URL::temporarySignedRoute(self::ROUTE, Carbon::now()->addHours(self::HOURS), ['id' => $entry->id()], absolute: false);

        // On the origin of the page's own site, which Core takes the frame's messages from and the page's links are relative to.
        return (self::origin($entry->site()->absoluteUrl()) ?? rtrim(URL::to('/'), '/')).$path;
    }

    /**
     * The address to render: the page itself while it is live as it stands, else the working copy or the entry in
     * Live Preview, which shows what a visitor cannot.
     */
    public static function address(EntryContract $entry): ?string
    {
        $draft = EntryFields::draftOf($entry);

        if ($draft === $entry && $entry->status() === 'published') {
            $url = $entry->absoluteUrl();

            return blank($url) ? null : $url;
        }

        return DraftPreview::livePreviewUrl($draft);
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || blank($parts['scheme'] ?? null) || blank($parts['host'] ?? null)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
