<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Preview\PagePreview;
use Inly\CoreWeb\Preview\PagePreviewController;

// Opened by Core's frame in the person's browser, so it is not sealed: only the signed link Core asked the site for opens it.
Route::get((string) config('core-web.preview.page_path', 'core-preview/page/{id}'), [PagePreviewController::class, 'frame'])
    ->where('id', '[A-Za-z0-9_-]+')
    ->middleware([...(array) config('core-web.preview.middleware', ['throttle:60,1']), 'signed:relative'])
    ->name(PagePreview::ROUTE);
