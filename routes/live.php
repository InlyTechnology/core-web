<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Live\CoreLive;
use Inly\CoreWeb\Live\LiveController;

// Under Statamic's action prefix, outside the statamic.web group, so the static cache never stores an answer.
Route::get(trim((string) config('statamic.routes.action', '!'), '/').'/core-web/live/{name}', LiveController::class)
    ->where('name', CoreLive::NAME_PATTERN)
    ->middleware((array) config('core-web.live.middleware', ['throttle:60,1']))
    ->name((string) config('core-web.live.name', 'core-web.live'));
