<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Chat\ChatController;

// Under Statamic's action prefix, outside the statamic.web group, so the static cache never stores a visitor's transcript.
Route::prefix(trim((string) config('statamic.routes.action', '!'), '/').'/core-web/chat/{agent}')
    ->where(['agent' => ChatController::AGENT_PATTERN])
    ->middleware((array) config('core-web.chat.middleware', ['web', 'throttle:60,1']))
    ->name((string) config('core-web.chat.name', 'core-web.chat').'.')
    ->group(function (): void {
        Route::get('/', [ChatController::class, 'show'])->name('show');
        Route::delete('/', [ChatController::class, 'forget'])->name('forget');
        // A turn and a handoff are model calls, so they get a tighter limit of their own.
        Route::post('messages', [ChatController::class, 'message'])->middleware('throttle:'.ChatController::TURN_LIMITER)->name('messages');
        Route::post('handoff', [ChatController::class, 'handoff'])->middleware('throttle:'.ChatController::TURN_LIMITER)->name('handoff');
    });
