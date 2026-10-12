<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Ping\PingController;
use Inly\CoreWeb\Sitemaps\SnapshotSitemapController;
use Inly\CoreWeb\Sitemaps\SnapshotSitemaps;

if (config('core-web.ping.enabled', true)) {
    Route::post((string) config('core-web.ping.path', 'api/core/objects'), PingController::class)
        ->middleware((array) config('core-web.ping.middleware', []))
        ->name((string) config('core-web.ping.name', 'api.core.objects'));
}

if (config('core-web.sitemaps.enabled', true)) {
    Route::get(SnapshotSitemaps::path('{type}'), SnapshotSitemapController::class)
        ->where('type', '[a-z0-9_-]+')
        ->middleware((array) config('core-web.sitemaps.middleware', []))
        ->name((string) config('core-web.sitemaps.name', 'core.sitemap'));

    // A site under a path of a host it shares (https://example.com/second) has its sitemaps under that path.
    Route::get('{site_path}/'.SnapshotSitemaps::path('{type}'), SnapshotSitemapController::class)
        ->where(['site_path' => '[A-Za-z0-9_/-]+', 'type' => '[a-z0-9_-]+'])
        ->middleware((array) config('core-web.sitemaps.middleware', []))
        ->name((string) config('core-web.sitemaps.name', 'core.sitemap').'.site');
}
