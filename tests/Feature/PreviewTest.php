<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Preview\Preview;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Facades\StaticCache;

class PreviewTest extends TestCase
{
    protected array $features = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Statamic's catch-all would answer the routes this test adds after it.
        $app['config']->set('statamic.routes.enabled', false);
        $app['config']->set('statamic.static_caching.strategy', 'half');
        $app['config']->set('statamic.static_caching.strategies.half', ['driver' => 'application', 'expiry' => null]);
    }

    public function test_a_marked_preview_needs_no_switch_and_stays_out_of_every_cache(): void
    {
        Route::get('draft-preview', function (): string {
            Preview::mark();

            return 'DRAFT PAGE';
        })->middleware('statamic.web');
        Route::get('released', fn (): string => 'RELEASED PAGE')->middleware('statamic.web');

        $this->get('/draft-preview')
            ->assertOk()
            ->assertHeader('X-Statamic-Uncacheable', 'true')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/released')->assertOk()->assertHeaderMissing('X-Statamic-Uncacheable');

        $stored = serialize((fn () => $this->storage)->call(StaticCache::cacheStore()->getStore()));
        $this->assertStringContainsString('RELEASED PAGE', $stored);
        $this->assertStringNotContainsString('DRAFT PAGE', $stored);
    }

    public function test_a_marked_preview_that_turns_the_visitor_away_is_not_cached_either(): void
    {
        Route::get('expired-preview', function (): never {
            Preview::mark();

            abort(404);
        })->middleware('statamic.web');

        $this->get('/expired-preview')->assertNotFound()->assertHeader('X-Statamic-Uncacheable', 'true');

        $this->assertStringNotContainsString('expired-preview', serialize((fn () => $this->storage)->call(StaticCache::cacheStore()->getStore())));
    }
}
