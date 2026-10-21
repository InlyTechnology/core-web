<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Tests\TestCase;

class SiteCoreBootTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['env'] = 'production';
        $app['config']->set('core-web.core', ['url' => 'https://core.example', 'client_id' => 'site-client']);
        $app['config']->set('core-web.client.base_url', '');
        $app['config']->set('core-web.client.client_id', '');
    }

    public function test_a_server_boots_with_the_committed_core(): void
    {
        $this->assertSame('https://core.example', CoreClient::fromConfig()->baseUrl());
        $this->assertSame('site-client', config('core-web.client.client_id'));
    }
}
