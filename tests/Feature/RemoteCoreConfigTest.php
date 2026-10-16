<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Tests\TestCase;

class RemoteCoreConfigTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.pull', [
            'base_url' => 'https://legacy-core.test',
            'client_id' => 'legacy',
            'client_secret' => 'legacy-secret',
        ]);
        $app['config']->set('core-web.remote.client_secret', 'remote-secret');
    }

    public function test_a_site_config_still_naming_pull_fills_what_remote_leaves_blank(): void
    {
        $this->assertSame('https://legacy-core.test', config('core-web.remote.base_url'));
        $this->assertSame('legacy', config('core-web.remote.client_id'));
        $this->assertSame('remote-secret', config('core-web.remote.client_secret'));
    }
}
