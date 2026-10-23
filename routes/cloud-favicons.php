<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Cloud\PeakFaviconController;

// Only on Cloud, where the favicons disk is on the bucket (CoreWebServiceProvider), outside the `web` group.
Route::get('favicons/{path}', PeakFaviconController::class)
    ->where('path', '.+')
    ->name('core-web.cloud.favicon');
