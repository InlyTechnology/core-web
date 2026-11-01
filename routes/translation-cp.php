<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Translation\TranslateFieldController;

// In the CP, behind its auth: the controller checks that the editor may edit the entry.
Route::post('core-web/translation/field', TranslateFieldController::class)->middleware('throttle:30,1')->name('core-web.translation.field');
