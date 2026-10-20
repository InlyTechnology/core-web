<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Tests\TestCase;

class MediaViewCacheTest extends TestCase
{
    protected array $features = ['snapshots', 'chat', 'media'];

    public function test_view_cache_compiles_with_media_on(): void
    {
        $this->artisan('view:cache')->assertSuccessful();
        $this->artisan('view:clear');
    }
}
