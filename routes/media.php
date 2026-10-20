<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Media\MediaFileController;

// Only on a checkout reading the live site's pulled index (CoreWebServiceProvider), outside the `web` group.
Route::get(trim((string) config('statamic.routes.action', '!'), '/').'/core-web/media/{disk}/{path}', MediaFileController::class)
    ->where('disk', '[A-Za-z0-9_-]+')
    ->where('path', '.+')
    ->name('core-web.media.file');
