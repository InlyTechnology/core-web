<?php

namespace Inly\CoreWeb\Seo;

use Illuminate\Support\Facades\Log;
use Inly\CoreWeb\Support\Values;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;

/**
 * The redirects global: an old path to an entry or an external address, tried only for a request that would 404, so
 * a live page always wins. An exact path matches first, then a pattern written between #s (`#^/blog/.*#`).
 */
final class Redirects
{
    /** @return array{to: string, status: int}|null */
    public static function for(string $path): ?array
    {
        $rows = collect(self::rows())->filter(fn ($row) => is_array($row) && trim((string) ($row['url_old'] ?? '')) !== '');
        $paths = array_unique([self::normalise($path), self::normalise(rawurldecode($path))]);

        $row = $rows->first(fn (array $row) => ! self::isPattern($row['url_old']) && array_intersect([self::normalise($row['url_old']), self::normalise(rawurldecode($row['url_old']))], $paths) !== [])
            ?? $rows->first(fn (array $row) => self::isPattern($row['url_old']) && self::matches($row['url_old'], $paths));

        if ($row === null) {
            return null;
        }

        $to = self::target($row);

        return $to === null ? null : ['to' => $to, 'status' => (int) ($row['response'] ?? 301) === 302 ? 302 : 301];
    }

    /** @return list<mixed> */
    private static function rows(): array
    {
        $handle = (string) config('core-web.seo.redirects_global', 'redirects');
        $variables = $handle === '' ? null : GlobalSet::findByHandle($handle)?->in(Site::current()->handle());

        return array_values((array) ($variables?->value('redirects') ?? []));
    }

    /** @param  array<string, mixed>  $row */
    private static function target(array $row): ?string
    {
        $id = Values::first($row['internal_url'] ?? null);

        if (is_string($id) && $id !== '') {
            $entry = Entry::find(str_replace('entry::', '', $id));
            $entry = $entry?->in(Site::current()->handle()) ?? $entry;

            if ($entry?->url() !== null) {
                return (string) $entry->absoluteUrl();
            }
        }

        $external = trim((string) ($row['external_url'] ?? ''));

        return $external === '' ? null : $external;
    }

    private static function isPattern(string $value): bool
    {
        return strlen($value) > 2 && str_starts_with($value, '#') && str_ends_with($value, '#');
    }

    /** @param  list<string>  $paths */
    private static function matches(string $pattern, array $paths): bool
    {
        foreach ($paths as $path) {
            $result = @preg_match($pattern, $path);

            if ($result === false) {
                Log::warning('core-web: a redirect pattern is not a valid regular expression.', ['pattern' => $pattern]);

                return false;
            }

            if ($result === 1) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $path): string
    {
        return '/'.trim((string) parse_url($path, PHP_URL_PATH), '/');
    }
}
