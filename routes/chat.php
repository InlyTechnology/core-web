<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Chat\ChatController;

// Under Statamic's action prefix, outside the statamic.web group, so the static cache never stores a visitor's transcript.
Route::prefix(trim((string) config('statamic.routes.action', '!'), '/').'/core-web/chat/{agent}')
    ->where(['agent' => '[A-Za-z0-9_-]+'])
    ->middleware((array) config('core-web.chat.middleware', ['web', 'throttle:60,1']))
    ->name((string) config('core-web.chat.name', 'core-web.chat').'.')
    ->group(function (): void {
        Route::get('/', [ChatController::class, 'show'])->name('show');
        Route::delete('/', [ChatController::class, 'forget'])->name('forget');
        Route::post('messages', [ChatController::class, 'message'])->name('messages');
        Route::post('handoff', [ChatController::class, 'handoff'])->name('handoff');
    });
