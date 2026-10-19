<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Routing\Router;
use Inly\CoreWeb\Tests\TestCase;

class MediaPushWithoutContentSyncTest extends TestCase
{
    protected array $features = ['media'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.media.push.accept', true);
    }

    public function test_the_push_endpoints_need_content_sync_for_their_login(): void
    {
        $this->assertFalse(app(Router::class)->has('core-web.sync.assets.store'));
        $this->postJson('/!/core-web/sync/assets/check', ['files' => []])->assertNotFound();
    }
}
