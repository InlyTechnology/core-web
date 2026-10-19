<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Revisions\Revision;

/**
 * Where a draft came from, kept on the revision it was saved as: a key of
 * the revision's `attributes` beside Statamic's own (id, slug, published,
 * date, data), which the revision file stores as it is and a working copy
 * carries when it is read back. `via` names the source (`core-ai`,
 * `content-push`); the rest is the source's own (who, which request).
 */
final class DraftProvenance
{
    public const KEY = 'provenance';

    /**
     * @param  array<string, string|null>  $details
     * @return array<string, string>
     */
    public static function make(string $via, array $details = []): array
    {
        return array_filter(['via' => $via, ...$details], fn (?string $value) => filled($value));
    }

    /** @param  array<string, string>  $provenance */
    public static function stamp(Revision $revision, array $provenance): Revision
    {
        return $revision->attributes([...$revision->attributes(), self::KEY => $provenance]);
    }

    /** @return array<string, string>|null */
    public static function of(?Revision $revision): ?array
    {
        $provenance = $revision?->attributes()[self::KEY] ?? null;

        return is_array($provenance) && $provenance !== [] ? $provenance : null;
    }

    public static function via(?Revision $revision): ?string
    {
        $via = self::of($revision)['via'] ?? null;

        return is_string($via) ? $via : null;
    }
}
