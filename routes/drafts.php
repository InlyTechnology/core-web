<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Drafts\DraftPreview;
use Inly\CoreWeb\Drafts\DraftPreviewController;

// Only the signed link a draft's source hands the person opens it.
Route::get((string) config('core-web.drafts.preview.path', 'core-preview/{id}'), DraftPreviewController::class)
    ->where('id', '[A-Za-z0-9_-]+')
    ->middleware((array) config('core-web.drafts.preview.middleware', ['throttle:60,1', 'signed:relative']))
    ->name(DraftPreview::routeName());
