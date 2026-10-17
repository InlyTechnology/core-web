<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Content\CoreChecksController;
use Inly\CoreWeb\Content\CoreProposalsController;
use Inly\CoreWeb\Content\VerifyCoreEnvelope;
use Inly\CoreWeb\CoreWebServiceProvider;

// Protocol v1.2's checks and proposals, beside the site's own content endpoints. Outside the `web` group: no session,
// no CSRF, never behind the static cache. Reads are sealed for reading; a fix or a proposal makes only a draft or a
// proposal an editor publishes, so it is sealed for drafting.
Route::middleware((array) config('core-web.content.middleware', ['throttle:120,1']))
    ->prefix(trim((string) config('core-web.content.prefix', 'api/core/v1'), '/'))
    ->name('core-web.content.')
    ->group(function (): void {
        if (CoreWebServiceProvider::enabled('checks')) {
            Route::controller(CoreChecksController::class)->group(function (): void {
                Route::get('checks', 'index')->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('checks.index');
                Route::get('checks/{check}/findings', 'findings')->where('check', '[a-z0-9-]+')->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('checks.findings');

                // A fix lands as a draft or a proposal, so only a site that takes drafts offers one.
                if (CoreWebServiceProvider::enabled('drafts')) {
                    Route::post('checks/{check}/fix', 'fix')->where('check', '[a-z0-9-]+')->middleware(VerifyCoreEnvelope::ALIAS.':website.draft')->name('checks.fix');
                }
            });
        }

        if (CoreWebServiceProvider::enabled('drafts')) {
            Route::controller(CoreProposalsController::class)->group(function (): void {
                Route::get('proposal-target', 'target')->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('proposals.target');
                Route::post('proposals', 'store')->middleware(VerifyCoreEnvelope::ALIAS.':website.draft')->name('proposals.store');
                Route::get('proposals/{id}', 'show')->where('id', '[0-9a-f-]{36}')->middleware(VerifyCoreEnvelope::ALIAS.':website.draft')->name('proposals.show');
            });
        }
    });
