<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\ContentSync\SyncCpController;

// In the CP, behind its sign-in: whoever approves a machine is who its token acts as.
Route::prefix('core-web/sync')->name('core-web.sync.')->controller(SyncCpController::class)->group(function (): void {
    Route::get('authorize', 'authorize')->name('authorize');
    Route::post('authorize/approve', 'approve')->name('approve');
    Route::post('authorize/deny', 'deny')->name('deny');
    Route::get('tokens', 'tokens')->name('tokens');
    Route::post('tokens/{id}/revoke', 'revoke')->where('id', '[0-9a-f-]{36}')->name('revoke');
});
