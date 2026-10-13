<?php

namespace Inly\CoreWeb\Tests;

use Inly\CoreWeb\CoreWebServiceProvider;
use Statamic\Facades\Site;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = CoreWebServiceProvider::class;

    /** @var list<string> */
    protected array $features = ['snapshots'];

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshots in the test's array store, which each test starts empty (SnapshotCacheStoreTest checks the real one).
        config(['core-web.snapshots.store' => null]);

        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://other.test/', 'locale' => 'sv_SE'],
        ]);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'http://default.test');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('statamic.editions.pro', true);

        foreach ($this->features as $feature) {
            $app['config']->set("core-web.features.{$feature}", true);
        }
    }
}
