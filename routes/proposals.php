<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Proposals\ProposalsController;

// In the CP, behind its auth: each action checks that the editor may edit the proposal's target.
Route::prefix('core-web/proposals')->name('core-web.proposals.')->controller(ProposalsController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('{id}', 'show')->where('id', '[0-9a-f-]{36}')->name('show');
    Route::post('{id}/apply', 'apply')->where('id', '[0-9a-f-]{36}')->name('apply');
    Route::post('{id}/discard', 'discard')->where('id', '[0-9a-f-]{36}')->name('discard');
});
