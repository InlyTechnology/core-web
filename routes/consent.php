<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Consent\ConsentController;

// No session and no CSRF: the banner posts from statically cached pages, and a row holds nothing about the visitor but their choice.
Route::post(trim((string) config('statamic.routes.action', '!'), '/').'/core-web/consent', ConsentController::class)
    ->middleware('throttle:core-web.consent')
    ->name('core-web.consent.store');
