<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\ContentSync\AuthenticateSyncToken;
use Inly\CoreWeb\ContentSync\GuardSyncRequests;
use Inly\CoreWeb\ContentSync\SyncApiController;
use Inly\CoreWeb\ContentSync\SyncLimits;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Forms\FormRelay;
use Inly\CoreWeb\Forms\FormRelayController;

// Under Statamic's action prefix, outside the `web` group: no session, no CSRF, never behind the static cache.
Route::prefix(trim((string) config('statamic.routes.action', '!'), '/').'/core-web/sync')
    ->middleware([GuardSyncRequests::class, ...(array) config('core-web.content_sync.middleware', ['throttle:'.SyncLimits::REQUESTS])])
    ->name('core-web.sync.')
    ->controller(SyncApiController::class)
    ->group(function (): void {
        // Starting a login needs no token, so it gets a tight limit of its own.
        Route::post('device', 'device')->middleware('throttle:'.SyncLimits::DEVICE)->name('device');
        Route::post('token', 'token')->name('token');
        Route::post('logout', 'logout')->middleware(AuthenticateSyncToken::ALIAS)->name('logout');
        Route::get('export', 'export')->middleware(AuthenticateSyncToken::ALIAS)->name('export');
        Route::get('snapshots/{type}', 'snapshot')->middleware(AuthenticateSyncToken::ALIAS)->where('type', '[A-Za-z0-9_.-]+')->name('snapshot');

        // A local checkout's Core forms, relayed to this site's Core (FormRelay).
        if (CoreWebServiceProvider::enabled('forms')) {
            Route::controller(FormRelayController::class)
                ->middleware(AuthenticateSyncToken::ALIAS)
                ->prefix('forms')
                ->name('forms.')
                ->group(function (): void {
                    Route::get('embed/{file}', 'script')->whereIn('file', FormRelay::EMBED_FILES)->name('script');
                    Route::get('{uuid}/definition', 'definition')->whereUuid('uuid')->name('definition');
                    Route::get('{uuid}/nonce', 'nonce')->whereUuid('uuid')->name('nonce');
                    Route::post('{uuid}/submit', 'submit')->whereUuid('uuid')->middleware('throttle:'.SyncLimits::FORM_ENTRIES)->name('submit');
                    Route::post('{uuid}/entries', 'entry')->whereUuid('uuid')->middleware('throttle:'.SyncLimits::FORM_ENTRIES)->name('entries');
                });
        }
    });
