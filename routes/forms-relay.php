<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Forms\FormRelay;
use Inly\CoreWeb\Forms\LocalFormRelayController;

// Only on a local checkout (CoreWebServiceProvider), outside the `web` group: the embed posts without a CSRF token, as it does to Core.
Route::prefix(trim((string) config('statamic.routes.action', '!'), '/').'/core-web/forms-relay')
    ->controller(LocalFormRelayController::class)
    ->name('core-web.forms-relay.')
    ->group(function (): void {
        // The embed's own paths under the Core address it is given.
        Route::get('public/forms/embed/{file}', 'script')->whereIn('file', FormRelay::EMBED_FILES)->name('script');
        Route::get('api/forms/{uuid}/definition', 'definition')->whereUuid('uuid')->name('definition');
        Route::get('api/forms/{uuid}/nonce', 'nonce')->whereUuid('uuid')->name('nonce');
        Route::post('api/forms/{uuid}/submit', 'submit')->whereUuid('uuid')->name('submit');
    });
