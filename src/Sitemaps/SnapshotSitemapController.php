<?php

namespace Inly\CoreWeb\Sitemaps;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteModel;

/**
 * /sitemap-core-{type}.xml at every site's address (its host, and its path
 * on a host it shares with other sites): the pages a snapshot's documents
 * have on that site (Snapshot::sitemap()), from that site's partition only. Pages served by routes over Core's data are absent
 * from the CMS's own sitemap, so each type advertises its own.
 *
 * 404 for a type without a sitemap or with no URLs on the site; 503 while
 * nothing at all can be read, since an empty sitemap reads as "every one of
 * these URLs is gone".
 */
class SnapshotSitemapController
{
    public function __invoke(Request $request, SnapshotSitemaps $sitemaps): Response
    {
        $site = Site::current();
        $type = (string) $request->route('type');

        // Statamic's 404, so the not-found page renders with its cascade: only
        // at the site's own path, and only for a type with a sitemap there.
        if (! self::atSitePath($site, (string) $request->route('site_path')) || ! $sitemaps->declares($type, $site->handle())) {
            throw new NotFoundHttpException;
        }

        abort_if($sitemaps->isDegraded($type, $site->handle()), 503);

        $entries = $sitemaps->entries($type, $site->handle(), $site->absoluteUrl());

        if ($entries === []) {
            throw new NotFoundHttpException;
        }

        return response(self::xml($entries))->header('Content-Type', 'application/xml');
    }

    private static function atSitePath(SiteModel $site, string $path): bool
    {
        return trim($path, '/') === trim((string) parse_url($site->absoluteUrl(), PHP_URL_PATH), '/');
    }

    /** @param  list<array{loc: string, lastmod: ?string, images: list<string>}>  $entries */
    private static function xml(array $entries): string
    {
        $images = array_filter($entries, fn (array $entry) => $entry['images'] !== []) !== [];
        $e = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            .($images ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '').">\n";

        foreach ($entries as $entry) {
            $xml .= "    <url>\n        <loc>{$e($entry['loc'])}</loc>\n";

            if ($entry['lastmod'] !== null) {
                $xml .= "        <lastmod>{$e($entry['lastmod'])}</lastmod>\n";
            }

            foreach ($entry['images'] as $image) {
                $xml .= "        <image:image>\n            <image:loc>{$e($image)}</image:loc>\n        </image:image>\n";
            }

            $xml .= "    </url>\n";
        }

        return $xml.'</urlset>'."\n";
    }
}
