<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Client\SiteCore;
use Inly\CoreWeb\Tests\TestCase;

class SiteCoreTest extends TestCase
{
    private function applyIn(string $environment, array $client = []): void
    {
        config([
            'core-web.core' => ['url' => 'https://core.example', 'client_id' => 'site-client'],
            'core-web.client.base_url' => $client['base_url'] ?? '',
            'core-web.client.client_id' => $client['client_id'] ?? '',
        ]);
        $this->app['env'] = $environment;

        SiteCore::apply($this->app);
    }

    public function test_a_server_uses_the_committed_core(): void
    {
        $this->applyIn('production');

        $this->assertSame('https://core.example', config('core-web.client.base_url'));
        $this->assertSame('site-client', config('core-web.client.client_id'));
    }

    public function test_env_wins_where_an_environment_pairs_with_another_core(): void
    {
        $this->applyIn('staging', ['base_url' => 'https://staging-core.example', 'client_id' => 'staging-client']);

        $this->assertSame('https://staging-core.example', config('core-web.client.base_url'));
        $this->assertSame('staging-client', config('core-web.client.client_id'));
    }

    public function test_a_local_checkout_and_the_test_suite_get_no_core_by_default(): void
    {
        $this->applyIn('local');
        $this->assertSame('', config('core-web.client.base_url'));

        $this->applyIn('testing');
        $this->assertSame('', config('core-web.client.client_id'));
    }
}
