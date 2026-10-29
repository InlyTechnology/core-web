<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Seo\RobotsController;
use Inly\CoreWeb\Seo\SitemapController;

// No web middleware: the files carry no session and are the same for every visitor.
if (config('core-web.seo.robots.enabled', true)) {
    Route::get('robots.txt', RobotsController::class)->name('core-web.seo.robots');
}

if (config('core-web.seo.sitemap.enabled', true)) {
    Route::get('sitemaps.xml', [SitemapController::class, 'index'])->name('core-web.seo.sitemaps');
    Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('core-web.seo.sitemap');

    // A site under a path of a host it shares (https://example.com/en) has its sitemap under that path.
    Route::get('{site_path}/sitemap.xml', [SitemapController::class, 'sitemap'])
        ->where('site_path', '[A-Za-z0-9_/-]+')
        ->name('core-web.seo.sitemap.site');
}
