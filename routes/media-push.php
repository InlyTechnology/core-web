<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\ContentSync\GuardSyncRequests;
use Inly\CoreWeb\Media\Push\HeldAssetsController;

// Under Statamic's action prefix, outside the statamic.web group; `core-web.sync` resolves the pushing person's token.
Route::prefix(trim((string) config('statamic.routes.action', '!'), '/').'/core-web/sync/assets')
    ->middleware([GuardSyncRequests::class, ...(array) config('core-web.media.push.middleware', ['throttle:core-web-sync', 'core-web.sync'])])
    ->group(function (): void {
        Route::post('check', [HeldAssetsController::class, 'check'])->name('core-web.sync.assets.check');
        Route::post('/', [HeldAssetsController::class, 'store'])->name('core-web.sync.assets.store');
    });
