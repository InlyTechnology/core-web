<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Preview\ObjectPreviewController;

// Not sealed: Core's frame opens it in the person's browser. Without an applied state it renders only a public page.
Route::get((string) config('core-web.preview.path', 'core-preview/object'), ObjectPreviewController::class)
    ->middleware((array) config('core-web.preview.middleware', ['throttle:60,1']))
    ->name('core-web.preview.object');
