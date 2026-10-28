<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Favicons\FaviconController;

// No web middleware: the same for every visitor. A real public/favicon.ico is served before this route is reached.
Route::get('favicon.ico', [FaviconController::class, 'ico'])->name('core-web.favicons.ico');
Route::get('site.webmanifest', [FaviconController::class, 'manifest'])->name('core-web.favicons.manifest');

// A site under a path of a host it shares (https://example.com/en) has its manifest under that path.
Route::get('{site_path}/site.webmanifest', [FaviconController::class, 'manifest'])
    ->where('site_path', '[A-Za-z0-9_/-]+')
    ->name('core-web.favicons.manifest.site');
