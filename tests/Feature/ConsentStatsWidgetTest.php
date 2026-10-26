<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Inly\CoreWeb\Consent\ConsentStats;
use Inly\CoreWeb\Tests\TestCase;
use Inly\CoreWeb\Widgets\ConsentStatsWidget;
use Statamic\Widgets\Loader;

/** The `consent_stats` dashboard widget: how visitors answered the banner over a window of the consent log. */
class ConsentStatsWidgetTest extends TestCase
{
    protected array $features = ['consent'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('core-web.consent.vendors', ['google_analytics', 'booking' => ['name' => 'Booking', 'category' => 'marketing', 'cookies' => [['name' => 'bk_id', 'purpose' => 'Keeps the booking.', 'days' => 30]]]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../database/migrations/2026_10_23_000000_create_core_web_consents_table.php')->up();
    }

    /** @param  list<string>  $categories */
    private function choice(string $action, array $categories, int $daysAgo = 1, string $site = 'default'): void
    {
        DB::table('core_web_consents')->insert(['consent_id' => fake()->uuid(), 'site' => $site, 'action' => $action, 'categories' => json_encode($categories), 'policy_version' => 1, 'created_at' => now()->subDays($daysAgo)]);
    }

    /** @param  array<string, mixed>  $config */
    private function render(array $config = []): string
    {
        return (string) app(Loader::class)->load('consent_stats', ['type' => 'consent_stats', ...$config])->html();
    }

    public function test_it_is_registered_with_the_consent_log(): void
    {
        $this->assertSame(ConsentStatsWidget::class, app('statamic.widgets')->get('consent_stats'));
    }

    public function test_it_counts_choices_in_the_window_by_action_and_category(): void
    {
        $this->choice('accept_all', ['preferences', 'statistics', 'marketing']);
        $this->choice('accept_all', ['preferences', 'statistics', 'marketing']);
        $this->choice('custom', ['statistics']);
        $this->choice('reject_all', []);
        $this->choice('accept_all', ['statistics'], daysAgo: 40);

        $stats = app(ConsentStats::class)->since(now()->subDays(30));

        $this->assertSame(4, $stats['total']);
        $this->assertSame(['accept_all' => 2, 'reject_all' => 1, 'custom' => 1], $stats['actions']);
        $this->assertSame(['preferences' => 2, 'statistics' => 3, 'marketing' => 2], $stats['categories']);

        $html = $this->render();

        $this->assertStringContainsString('Last 30 days', $html);
        $this->assertStringContainsString('>4<', $html);
        $this->assertStringContainsString('Accepted all <span class="tabular-nums">50%', $html);
        $this->assertStringContainsString('Rejected all <span class="tabular-nums">25%', $html);
        $this->assertStringContainsString('Statistics', $html);
        $this->assertStringContainsString('75%', $html);
        $this->assertStringNotContainsString('Preferences', $html, 'Only the categories the site asks for.');
    }

    public function test_it_narrows_to_a_site_and_says_when_nothing_was_logged(): void
    {
        $this->choice('accept_all', ['statistics'], site: 'other');

        $this->assertSame(0, app(ConsentStats::class)->since(now()->subDays(30), 'default')['total']);
        $this->assertStringContainsString('No choices logged in this period', $this->render(['site' => 'default', 'days' => 7]));
        $this->assertStringContainsString('Last 7 days', $this->render(['site' => 'default', 'days' => 7]));
    }
}
