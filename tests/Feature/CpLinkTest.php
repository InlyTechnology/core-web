<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Tests\TestCase;
use Statamic\Facades\CP\Nav as CpNav;
use Statamic\Facades\User;

class CpLinkTest extends TestCase
{
    protected array $features = ['cp_link'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withRealNav();
        config(['core-web.client.base_url' => 'https://core.test/']);
    }

    public function test_every_cp_user_has_a_link_to_core(): void
    {
        foreach ([$this->editor(), $this->super()] as $user) {
            $this->actingAs($user);

            $item = $this->navItem('Core');

            $this->assertNotNull($item, "{$user->email()} has no Core link.");
            $this->assertSame('https://core.test', $item->url());
            $this->assertSame('_blank', $item->attributes()['target'] ?? null);
            $this->assertSame('noopener noreferrer', $item->attributes()['rel'] ?? null);
            $this->assertStringContainsString('<svg', (string) $item->icon());
        }
    }

    public function test_the_link_sits_right_under_the_dashboard(): void
    {
        $this->actingAs($this->editor());

        $topLevel = CpNav::build()->first();

        $this->assertSame(['Dashboard', 'Core'], collect($topLevel['items'])->map->display()->take(2)->all());
    }

    public function test_the_site_names_the_link_and_picks_its_icon(): void
    {
        config(['core-web.cp_link.label' => 'Acme Core', 'core-web.cp_link.icon' => 'earth']);
        $this->actingAs($this->super());

        $item = $this->navItem('Acme Core');

        $this->assertNotNull($item);
        $this->assertSame('earth', $item->icon());
    }

    public function test_there_is_no_link_without_a_core_url(): void
    {
        config(['core-web.client.base_url' => '']);
        $this->actingAs($this->super());

        $this->assertNull($this->navItem('Core'));
    }

    private function navItem(string $display)
    {
        return CpNav::build()
            ->flatMap(fn (array $section) => $section['items'])
            ->first(fn ($item) => $item->display() === $display);
    }

    private function super()
    {
        return User::make()->id('cp-link-super')->email('cp-link-super@example.com')->makeSuper();
    }

    private function editor()
    {
        return User::make()->id('cp-link-editor')->email('cp-link-editor@example.com');
    }
}
