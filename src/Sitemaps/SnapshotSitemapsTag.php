<?php

namespace Inly\CoreWeb\Sitemaps;

use Statamic\Facades\Site;
use Statamic\Tags\Tags;

/**
 * {{ core_sitemaps }} … {{ url }} … {{ /core_sitemaps }}: the snapshot
 * sitemaps with URLs on the current site (or `site="handle"`), for the
 * robots.txt Sitemap: lines and the sitemap index. Nothing is listed by hand.
 */
class SnapshotSitemapsTag extends Tags
{
    protected static $handle = 'core_sitemaps';

    /** @return list<array{type: string, url: string}> */
    public function index(): array
    {
        $site = Site::get((string) $this->params->get('site')) ?? Site::current();
        $root = rtrim($site->absoluteUrl(), '/');

        return array_map(fn (string $type) => [
            'type' => $type,
            'url' => $root.'/'.SnapshotSitemaps::path($type),
        ], app(SnapshotSitemaps::class)->types($site->handle()));
    }
}
