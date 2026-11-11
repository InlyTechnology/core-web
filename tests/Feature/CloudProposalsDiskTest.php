<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Tests\TestCase;

class CloudProposalsDiskTest extends TestCase
{
    protected array $features = ['cloud', 'drafts'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.cloud.detected', true);
        $app['config']->set('filesystems.disks.private', ['driver' => 's3', 'bucket' => 'fls-private']);
    }

    public function test_a_cloud_site_keeps_its_proposals_in_the_private_bucket_from_boot(): void
    {
        $this->assertSame('private', config('core-web.drafts.proposals.disk'));
    }
}
