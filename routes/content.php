<?php

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Content\CoreBlocksController;
use Inly\CoreWeb\Content\CoreChecksController;
use Inly\CoreWeb\Content\CoreContentController;
use Inly\CoreWeb\Content\CoreDraftsController;
use Inly\CoreWeb\Content\CoreProposalsController;
use Inly\CoreWeb\Content\VerifyCoreEnvelope;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Preview\ObjectPagesController;

// The Statamic connector API's content feed (v1), drafts (v1.1), checks and proposals (v1.2), object pages (v1.5). Outside the `web`
// group: no session, no CSRF, never behind the static cache. Reads are sealed for reading; a fix or a proposal makes only a draft or a
// proposal an editor publishes, so it is sealed for drafting; only a publish is sealed for publishing.
Route::middleware((array) config('core-web.content.middleware', ['throttle:120,1']))
    ->prefix(trim((string) config('core-web.content.prefix', 'api/core/v1'), '/'))
    ->name('core-web.content.')
    ->group(function (): void {
        // The pages Core keeps a copy of, read by the Statamic connector's stock importer; a site with its own feed leaves it off.
        if (config('core-web.content.feed.enabled')) {
            Route::controller(CoreContentController::class)->group(function (): void {
                Route::get('content', 'index')->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('feed.index');
                Route::get('content/{id}', 'show')->where('id', '[A-Za-z0-9_-]+')->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('feed.show');
            });
        }

        // The Core records the site serves a page for (v1.5), from the snapshots that name their Core object.
        if (CoreWebServiceProvider::enabled('preview')) {
            Route::controller(ObjectPagesController::class)->group(function (): void {
                Route::get('object-pages', 'index')->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('object-pages.index');
                Route::get('object-page', 'show')->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('object-pages.show');
            });
        }

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
            Route::controller(CoreBlocksController::class)->group(function (): void {
                Route::get('blocks', 'index')->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('blocks.index');
                Route::get('blocks/{collection}/{handle}', 'show')->where(['collection' => '[A-Za-z0-9_-]+', 'handle' => '[A-Za-z0-9_-]+'])->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('blocks.show');
            });

            // A draft is published only by a call sealed for publishing.
            Route::controller(CoreDraftsController::class)->group(function (): void {
                Route::post('drafts', 'store')->middleware(VerifyCoreEnvelope::ALIAS.':website.draft')->name('drafts.store');
                Route::get('drafts/{id}', 'show')->where('id', '[A-Za-z0-9_-]+')->middleware(VerifyCoreEnvelope::ALIAS.':website.draft')->name('drafts.show');
                Route::post('drafts/{id}/publish', 'publish')->where('id', '[A-Za-z0-9_-]+')->middleware(VerifyCoreEnvelope::ALIAS.':website.publish')->name('drafts.publish');
            });

            Route::controller(CoreProposalsController::class)->group(function (): void {
                Route::get('proposal-target', 'target')->middleware(VerifyCoreEnvelope::ALIAS.':website.read')->name('proposals.target');
                Route::post('proposals', 'store')->middleware(VerifyCoreEnvelope::ALIAS.':website.draft')->name('proposals.store');
                Route::get('proposals/{id}', 'show')->where('id', '[0-9a-f-]{36}')->middleware(VerifyCoreEnvelope::ALIAS.':website.draft')->name('proposals.show');
            });
        }
    });
