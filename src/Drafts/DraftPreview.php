<?php

namespace Inly\CoreWeb\Drafts;

use Facades\Statamic\CP\LivePreview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Support\Str;

/**
 * The link a source hands a person to look at a draft on the site itself:
 * a signed link that holds for days (core-web.drafts.preview.days), each
 * visit minting a fresh Live Preview token (Statamic keeps one for an hour)
 * from the draft as it is then. Anyone holding the link sees the draft
 * until it expires; once the draft is published it leads to the live page.
 */
class DraftPreview
{
    public static function routeName(): string
    {
        return (string) config('core-web.drafts.preview.name', 'core.preview');
    }

    public function link(EntryContract $entry): string
    {
        $days = max(1, (int) config('core-web.drafts.preview.days', 7));

        return URL::to(URL::temporarySignedRoute(self::routeName(), Carbon::now()->addDays($days), ['id' => $entry->id()], absolute: false));
    }

    /** Where the link leads now: the draft in Live Preview, the live page once published, or nowhere. */
    public function open(string $id): ?string
    {
        $entry = Entry::find($id);

        if (! $entry || ! DraftableCollections::writable($entry->collectionHandle())) {
            return null;
        }

        $draft = EntryFields::draftOf($entry);

        if ($draft->published() && $draft === $entry) {
            return $entry->absoluteUrl();
        }

        $url = $draft->absoluteUrl();

        if (blank($url)) {
            return null;
        }

        $draft->setSupplement('live_preview', true);
        $token = LivePreview::tokenize(null, $draft)->token();

        // As the CP's preview does: a random parameter keeps any cache from serving one visit to the next.
        return $url.(str_contains($url, '?') ? '&' : '?').'live-preview='.Str::random().'&token='.$token;
    }
}
