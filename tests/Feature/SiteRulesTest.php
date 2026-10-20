<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Tests\TestCase;
use Tests\Central\CoreWebRulesTest;

/** The rules site-ci copies from `rules/` into a site, run against this test's app as the site. */
class SiteRulesTest extends TestCase
{
    protected array $features = ['media'];

    /** @var array<string, array<string, mixed>> */
    private array $siteDisks = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach ($this->siteDisks as $disk => $config) {
            $app['config']->set("filesystems.disks.{$disk}", $config);
        }
    }

    protected function setUp(): void
    {
        $this->siteDisks = $this->name() === 'test_a_site_keeping_its_own_assets_disk_is_reported'
            ? ['assets' => ['driver' => 'local', 'root' => '/tmp']]
            : [];

        parent::setUp();

        // The rule suite extends the site's own Tests\TestCase, which a package has not got.
        if (! class_exists(\Tests\TestCase::class)) {
            class_alias(TestCase::class, \Tests\TestCase::class);
        }
        require_once __DIR__.'/../../rules/CoreWebRulesTest.php';
    }

    public function test_a_site_keeping_its_own_assets_disk_is_reported(): void
    {
        $this->assertSame(["disk 'assets' (config/filesystems.php or a service provider)"], $this->rule('siteDefinedMediaDisks')[0]);
    }

    public function test_a_site_leaving_the_media_disks_to_core_web_passes(): void
    {
        $this->assertSame([], $this->rule('siteDefinedMediaDisks')[0]);
    }

    public function test_a_site_without_the_media_feature_is_not_checked(): void
    {
        config(['core-web.features.media' => false]);

        $this->assertSame([], $this->rule('siteDefinedMediaDisks')[0]);
    }

    /** @return array{list<string>, string} */
    private function rule(string $rule): array
    {
        return (fn () => $this->{$rule}())->call(new CoreWebRulesTest('test_the_site_follows_the_rule'));
    }
}
