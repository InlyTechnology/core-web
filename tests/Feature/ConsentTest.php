<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Inly\CoreWeb\Consent\Consent;
use Inly\CoreWeb\Consent\ConsentVendors;
use Inly\CoreWeb\Tests\TestCase;
use InvalidArgumentException;
use Statamic\Facades\Site;

class ConsentTest extends TestCase
{
    protected array $features = ['consent'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('core-web.consent.vendors', ['google_analytics', 'hubspot', 'booking' => ['name' => 'Booking', 'category' => 'marketing', 'cookies' => [['name' => 'bk_id', 'purpose' => 'Keeps the booking.', 'days' => 30]]]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../database/migrations/2026_10_23_000000_create_core_web_consents_table.php')->up();
    }

    public function test_a_site_block_naming_only_its_vendors_keeps_the_other_defaults(): void
    {
        $this->assertSame('basic', config('core-web.consent.google.mode'));
        $this->assertTrue(config('core-web.consent.log.enabled'));
        $this->assertTrue(config('core-web.consent.branding.show_mark'));
    }

    public function test_the_declared_vendors_are_grouped_by_category_with_the_site_s_own_cookies_as_necessary(): void
    {
        $categories = ConsentVendors::byCategory();

        $this->assertSame(['necessary', 'statistics', 'marketing'], array_keys($categories));
        $this->assertSame(['site'], array_keys($categories['necessary']));
        $this->assertSame(['google_analytics'], array_keys($categories['statistics']));
        $this->assertSame(['hubspot', 'booking'], array_keys($categories['marketing']));
        $this->assertContains('core_consent', array_column($categories['necessary']['site']['cookies'], 'name'));
    }

    public function test_an_unknown_vendor_without_a_category_fails_loudly(): void
    {
        config(['core-web.consent.vendors' => ['made_up']]);

        $this->expectException(InvalidArgumentException::class);

        ConsentVendors::declared();
    }

    public function test_the_script_settings_name_only_the_optional_categories_and_their_cookies(): void
    {
        $settings = Consent::script();

        $this->assertSame(['statistics', 'marketing'], $settings['categories']);
        $this->assertSame(['_ga', '_ga_*'], $settings['cookies']['statistics']);
        $this->assertContains('bk_id', $settings['cookies']['marketing']);
        $this->assertSame('/!/core-web/consent', $settings['log']);
    }

    public function test_the_head_denies_every_google_signal_by_default(): void
    {
        $html = Blade::render('<x-core-web::consent.head />');

        $this->assertStringContainsString('window.coreConsent', $html);
        $this->assertStringContainsString("gtag('consent', 'default'", $html);
        $this->assertStringContainsString("analytics_storage: 'denied'", $html);
        $this->assertStringContainsString("ad_user_data: 'denied'", $html);
    }

    public function test_the_banner_offers_reject_all_beside_accept_all_in_the_same_style(): void
    {
        $html = Blade::render('<x-core-web::consent.banner policy-url="/cookies" />');

        $this->assertMatchesRegularExpression('/class="core-consent__button" data-consent-reject-all/', $html);
        $this->assertMatchesRegularExpression('/class="core-consent__button" data-consent-accept-all/', $html);
        $this->assertStringContainsString('href="/cookies"', $html);
        $this->assertMatchesRegularExpression('/value="necessary"\s+checked disabled/', $html);
        $this->assertStringContainsString('value="marketing"', $html);
        $this->assertStringNotContainsString('value="preferences"', $html, 'a category no vendor uses is not asked for');
        $this->assertStringContainsString('Core Consent', $html);
    }

    public function test_a_corner_button_reopens_the_settings_where_configured(): void
    {
        $this->assertMatchesRegularExpression('/class="core-consent__fab core-consent__fab--bottom-left" data-consent-open data-consent-fab/', Blade::render('<x-core-web::consent.banner />'));

        config(['core-web.consent.button' => 'bottom-right']);
        $this->assertStringContainsString('core-consent__fab--bottom-right', Blade::render('<x-core-web::consent.banner />'));

        config(['core-web.consent.button' => false]);
        $this->assertStringNotContainsString('data-consent-fab', Blade::render('<x-core-web::consent.banner />'));
    }

    public function test_the_branding_override_restyles_the_banner_and_can_hide_the_mark(): void
    {
        config(['core-web.consent.branding' => ['accent' => '#c00', 'logo' => '/logo.svg', 'show_mark' => false, 'font' => 'x; } body { display: none']]);

        $html = Blade::render('<x-core-web::consent.banner />');

        $this->assertStringContainsString('style="--core-consent-accent: #c00"', $html, 'a value that would break out of the declaration is dropped');
        $this->assertStringContainsString('src="/logo.svg"', $html);
        $this->assertStringNotContainsString('Core Consent', $html);
    }

    public function test_held_back_scripts_and_embeds_render_inert(): void
    {
        $html = Blade::render("@consentScript('statistics') window.counted = true @endconsentScript <x-core-web::consent.gate category=\"marketing\"><iframe src=\"https://example.com\"></iframe></x-core-web::consent.gate>");

        $this->assertStringContainsString('<script type="text/plain" data-consent="statistics"> window.counted = true </script>', $html);
        $this->assertStringContainsString('<template><iframe src="https://example.com"></iframe></template>', $html);
        $this->assertStringContainsString('data-consent-grant="marketing"', $html);
    }

    public function test_tag_manager_waits_for_statistics_or_marketing_in_basic_mode_and_loads_at_once_in_advanced(): void
    {
        $this->assertStringContainsString('<script type="text/plain" data-consent="statistics marketing" data-consent-match="any">', Blade::render('<x-core-web::consent.google-tag-manager id="GTM-ABC" />'));

        config(['core-web.consent.google.mode' => 'advanced']);
        $html = Blade::render('<x-core-web::consent.google-tag-manager id="GTM-ABC" />');

        $this->assertStringNotContainsString('text/plain', $html);
        $this->assertStringContainsString("'GTM-ABC'", $html);
    }

    public function test_the_declaration_lists_every_cookie_with_its_lifetime(): void
    {
        $html = Blade::render('<x-core-web::consent.declaration />');

        $this->assertStringContainsString('<code>hubspotutk</code>', $html);
        $this->assertStringContainsString('<code>bk_id</code>', $html);
        $this->assertStringContainsString('30 minutes', $html);
        $this->assertStringContainsString('data-consent-open', $html);
    }

    public function test_a_choice_is_logged_without_the_visitor_s_address(): void
    {
        $this->postJson('/!/core-web/consent', [
            'id' => '0b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b',
            'action' => 'custom',
            'categories' => ['statistics'],
            'version' => 1,
            'site' => 'other',
        ], ['Origin' => 'http://localhost'])->assertNoContent();

        $row = DB::table('core_web_consents')->first();

        $this->assertSame('0b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b', $row->consent_id);
        $this->assertSame('["statistics"]', $row->categories);
        $this->assertSame('other', $row->site);
        $this->assertSame(['id', 'consent_id', 'site', 'action', 'categories', 'policy_version', 'created_at'], array_keys((array) $row));
    }

    public function test_a_choice_from_another_origin_or_with_an_unknown_category_is_refused(): void
    {
        $choice = ['id' => '0b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b', 'action' => 'accept_all', 'categories' => ['statistics'], 'version' => 1, 'site' => 'default'];

        $this->postJson('/!/core-web/consent', $choice, ['Origin' => 'https://evil.test'])->assertForbidden();
        $this->postJson('/!/core-web/consent', ['categories' => ['necessary']] + $choice)->assertUnprocessable();
        $this->postJson('/!/core-web/consent', ['site' => 'made-up'] + $choice)->assertUnprocessable();
        $this->assertSame(0, DB::table('core_web_consents')->count());
    }

    public function test_prune_deletes_rows_older_than_the_retention(): void
    {
        DB::table('core_web_consents')->insert([
            ['consent_id' => 'a', 'site' => 'default', 'action' => 'reject_all', 'categories' => '[]', 'policy_version' => 1, 'created_at' => now()->subDays(1826)],
            ['consent_id' => 'b', 'site' => 'default', 'action' => 'reject_all', 'categories' => '[]', 'policy_version' => 1, 'created_at' => now()->subDays(10)],
        ]);

        $this->artisan('consent:prune')->assertSuccessful();

        $this->assertSame(['b'], DB::table('core_web_consents')->pluck('consent_id')->all());
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn (Event $event): bool => str_contains($event->command, 'consent:prune')));
    }

    public function test_a_site_of_a_multisite_install_declares_its_own_vendors_and_branding(): void
    {
        config(['core-web.consent.sites.other' => ['vendors' => ['meta_pixel'], 'branding' => ['accent' => '#c00']]]);

        $this->assertSame(['google_analytics', 'hubspot', 'booking'], array_slice(array_keys(ConsentVendors::declared()), 1));

        Site::setCurrent('other');

        $this->assertSame(['meta_pixel'], array_slice(array_keys(ConsentVendors::declared()), 1));
        $this->assertSame('#c00', Consent::setting('branding')['accent']);
        $this->assertTrue(Consent::setting('branding')['show_mark'], 'a site\'s branding keeps the defaults it does not name');
        $this->assertSame('other', Consent::script()['site']);
    }

    public function test_without_the_log_there_is_no_route_and_no_log_url(): void
    {
        config(['core-web.consent.log.enabled' => false]);

        $this->assertNull(Consent::script()['log']);
    }
}
