<?php

namespace Inly\CoreWeb\Seo;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Sitemaps\SnapshotSitemaps;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Site;

/**
 * /sitemap.xml at each site's address, and /sitemaps.xml: the index of every site's sitemap and of the snapshot
 * sitemaps (/sitemap-core-{type}.xml). A site that turned its sitemap off on the global gets Statamic's 404.
 */
final class SitemapController
{
    public function sitemap(Request $request): Response
    {
        $site = Site::current();

        if (! self::atSitePath($site->absoluteUrl(), (string) $request->route('site_path')) || ! Sitemaps::enabled($site->handle())) {
            throw new NotFoundHttpException;
        }

        $entries = Sitemaps::entries($site->handle());
        $alternates = array_filter($entries, fn (array $entry) => $entry['alternates'] !== []) !== [];
        $e = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            .($alternates ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '').">\n";

        foreach ($entries as $entry) {
            $xml .= "    <url>\n        <loc>{$e($entry['loc'])}</loc>\n";
            $xml .= $entry['lastmod'] ? "        <lastmod>{$e($entry['lastmod'])}</lastmod>\n" : '';
            $xml .= "        <changefreq>{$e($entry['changefreq'])}</changefreq>\n        <priority>{$e($entry['priority'])}</priority>\n";

            foreach ($entry['alternates'] as $alternate) {
                $xml .= "        <xhtml:link rel=\"alternate\" hreflang=\"{$e($alternate['hreflang'])}\" href=\"{$e($alternate['href'])}\"/>\n";
            }

            $xml .= "    </url>\n";
        }

        return self::xml($xml.'</urlset>'."\n");
    }

    public function index(): Response
    {
        $e = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $locations = [];

        foreach (Site::all() as $site) {
            $root = rtrim($site->absoluteUrl(), '/');

            if (Sitemaps::enabled($site->handle())) {
                $locations[] = $root.'/sitemap.xml';
            }

            if (CoreWebServiceProvider::enabled('snapshots') && config('core-web.sitemaps.enabled', true)) {
                foreach (app(SnapshotSitemaps::class)->types($site->handle()) as $type) {
                    $locations[] = $root.'/'.SnapshotSitemaps::path($type);
                }
            }
        }

        if ($locations === []) {
            throw new NotFoundHttpException;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach (array_unique($locations) as $location) {
            $xml .= "    <sitemap>\n        <loc>{$e($location)}</loc>\n    </sitemap>\n";
        }

        return self::xml($xml.'</sitemapindex>'."\n");
    }

    private static function atSitePath(string $url, string $path): bool
    {
        return trim($path, '/') === trim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    private static function xml(string $xml): Response
    {
        return response($xml)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
