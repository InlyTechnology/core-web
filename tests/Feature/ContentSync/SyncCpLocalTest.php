<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Statamic\Facades\CP\Nav as CpNav;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** A local checkout refuses every sync call, so its CP has no sync pages to offer. */
class SyncCpLocalTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['content_sync'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['env'] = 'local';
    }

    protected function allowLocalRemote($app): void
    {
        $app['config']->set('core-web.content_sync.allow_local_remote', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->roles(['editor' => ['access cp', 'pull content']]);
    }

    public function test_a_local_site_has_no_tools_entry_and_no_sync_pages(): void
    {
        $this->withRealNav();
        $this->actingAs($this->cmsUser('ann@example.com', super: true));

        $this->assertNotContains('Content sync', $this->navItems());
        $this->assertFalse(Route::has('statamic.cp.core-web.sync.tokens'));
        $this->assertFalse(Route::has('statamic.cp.core-web.sync.authorize'));
        $this->get('/cp/core-web/sync/tokens')->assertNotFound();
        $this->postJson('/!/core-web/sync/device', ['machine' => 'x'])->assertForbidden()->assertJsonPath('error', 'local_remote');
    }

    #[DefineEnvironment('allowLocalRemote')]
    public function test_a_local_site_that_allows_being_a_remote_keeps_them(): void
    {
        $this->withRealNav();
        $this->actingAs($this->cmsUser('ann@example.com', roles: ['editor']));

        $this->assertContains('Content sync', $this->navItems());
        $this->get(cp_route('core-web.sync.tokens'))->assertOk();
    }

    /** @return list<string> */
    private function navItems(): array
    {
        return CpNav::build()->flatMap(fn (array $section) => $section['items'])->map->display()->values()->all();
    }
}
