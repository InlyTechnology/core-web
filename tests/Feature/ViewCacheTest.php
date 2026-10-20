<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Tests\TestCase;

/** `php artisan optimize` compiles every view of the core-web namespace, whichever features are on. */
class ViewCacheTest extends TestCase
{
    protected array $features = ['snapshots', 'chat', 'drafts', 'checks'];

    public function test_view_cache_compiles_with_media_off(): void
    {
        $this->assertFalse(config('core-web.features.media'));

        $this->artisan('view:cache')->assertSuccessful();
        $this->artisan('view:clear');
    }
}
