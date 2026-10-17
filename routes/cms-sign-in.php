<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\CmsSignIn\HandoffController;
use Inly\CoreWeb\CmsSignIn\RevokeController;

// The paths are the ones Core is told for this site, so a site that moves them moves them in Core too.
Route::get((string) config('core-web.cms_sign_in.path', 'admin-sso'), HandoffController::class)
    ->middleware((array) config('core-web.cms_sign_in.middleware', []))
    ->name((string) config('core-web.cms_sign_in.name', 'core-web.cms-sign-in'));

// Outside the `web` group: Core's seal is the credential, so it needs no session or CSRF exemption.
Route::post((string) config('core-web.cms_sign_in.revoke.path', 'api/core/cms-users/revoke'), RevokeController::class)
    ->middleware((array) config('core-web.cms_sign_in.revoke.middleware', []))
    ->name((string) config('core-web.cms_sign_in.revoke.name', 'core-web.cms-users.revoke'));
