<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inly\CoreWeb\Consent\Consent;
use Inly\CoreWeb\Consent\ConsentSnapshot;
use Inly\CoreWeb\Consent\ConsentUtilityController;
use Inly\CoreWeb\Consent\ConsentVendors;
use Inly\CoreWeb\Tests\TestCase;
use InvalidArgumentException;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class ConsentTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

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
        (require __DIR__.'/../../database/migrations/2026_10_27_000000_add_core_web_consent_snapshots.php')->up();
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

    public function test_vendors_with_a_consent_api_get_its_denied_defaults_in_the_head(): void
    {
        config(['core-web.consent.vendors' => ['google_analytics', 'microsoft_uet', 'microsoft_clarity']]);

        $html = Blade::render('<x-core-web::consent.head />');

        $this->assertSame(['uet', 'clarity'], Consent::script()['signals']);
        $this->assertStringContainsString("window.uetq.push('consent', 'default', { ad_storage: 'denied' })", $html);
        $this->assertStringContainsString("window.clarity('consentv2', { ad_Storage: has('marketing'), analytics_Storage: has('statistics') })", $html);
        $this->assertLessThan(strpos($html, 'window.uetq'), strpos($html, "gtag('consent', 'default'"));
    }

    public function test_a_site_without_such_vendors_defines_no_vendor_globals(): void
    {
        $html = Blade::render('<x-core-web::consent.head />');

        $this->assertSame([], Consent::script()['signals']);
        $this->assertStringContainsString("gtag('consent', 'default'", $html);
        $this->assertStringNotContainsString('window.uetq =', $html);
        $this->assertStringNotContainsString('window.clarity =', $html);
    }

    public function test_the_signals_still_apply_with_consent_mode_off(): void
    {
        config(['core-web.consent.vendors' => ['microsoft_clarity'], 'core-web.consent.google.consent_mode' => false]);

        $html = Blade::render('<x-core-web::consent.head />');

        $this->assertStringNotContainsString("gtag('consent', 'default'", $html);
        $this->assertStringContainsString("window.clarity('consentv2'", $html);
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

    public function test_a_site_with_only_necessary_cookies_shows_no_banner(): void
    {
        config(['core-web.consent.vendors' => []]);

        $this->assertFalse(Consent::asks());
        $this->assertSame('', trim(Blade::render('<x-core-web::consent.banner />')));
        $this->assertSame('', trim(Blade::render('<x-core-web::consent.settings-link />')));
        $this->assertStringContainsString('core_consent', Blade::render('<x-core-web::consent.declaration />'));
    }

    public function test_editors_set_the_banner_s_title_text_and_cookie_page_on_a_global(): void
    {
        Collection::make('pages')->routes('/{slug}')->save();
        Entry::make()->id('cookies')->collection('pages')->locale('default')->slug('kakor')->data(['title' => 'Kakor'])->save();
        $set = GlobalSet::make('configuration')->title('Configuration');
        $set->save();
        $set->in('default')->data(['consent_title' => 'Kakor här', 'consent_text' => 'Vi frågar först.', 'consent_policy_link' => 'entry::cookies'])->save();
        config(['core-web.consent.global' => 'configuration', 'core-web.consent.policy_url' => '/fallback']);

        $html = Blade::render('<x-core-web::consent.banner />');

        $this->assertStringContainsString('Kakor här', $html);
        $this->assertStringContainsString('Vi frågar först.', $html);
        $this->assertStringContainsString('href="/kakor"', $html);
        $this->assertStringContainsString('href="/override"', Blade::render('<x-core-web::consent.banner policy-url="/override" />'));
        $this->assertSame(['consent_title', 'consent_text', 'consent_policy_link'], Fieldset::find('core-web::consent')->fields()->all()->keys()->all());
    }

    public function test_the_cookie_page_can_be_an_address_a_file_or_a_page_picked_in_the_earlier_field(): void
    {
        Collection::make('pages')->routes('/{slug}')->save();
        Entry::make()->id('cookies')->collection('pages')->locale('default')->slug('kakor')->data(['title' => 'Kakor'])->save();
        $set = GlobalSet::make('configuration')->title('Configuration');
        $set->save();
        config(['core-web.consent.global' => 'configuration']);
        $url = function (array $data) use ($set): ?string {
            $set->in('default')->data($data)->save();

            return Consent::editorial()['policy_url'];
        };

        $this->assertSame('https://example.com/kakor', $url(['consent_policy_link' => 'https://example.com/kakor']));
        $this->assertSame('/kakpolicy', $url(['consent_policy_link' => '/kakpolicy']));
        $this->assertNull($url(['consent_policy_link' => 'javascript:alert(1)']));
        $this->assertNull($url(['consent_policy_link' => '//evil.example']));
        $this->assertSame('/kakor', $url(['consent_policy_page' => ['cookies']]));
        $this->assertSame('/kakor', $url(['consent_policy_link' => 'entry::cookies']));
        $this->assertNull($url(['consent_policy_link' => 'entry::gone']));

        $scratch = sys_get_temp_dir().'/core-web-consent-'.uniqid();
        File::ensureDirectoryExists($scratch);
        File::put($scratch.'/kakor.pdf', '%PDF');
        config(['filesystems.disks.consent-assets' => ['driver' => 'local', 'root' => $scratch, 'url' => '/files']]);
        AssetContainer::make('assets')->disk('consent-assets')->save();
        $this->assertSame('/files/kakor.pdf', $url(['consent_policy_link' => 'asset::assets::kakor.pdf']));
        File::deleteDirectory($scratch);
    }

    public function test_a_cookie_page_missing_on_the_current_site_falls_back_to_policy_url(): void
    {
        Site::setSites(['default' => ['name' => 'Svenska', 'url' => '/', 'locale' => 'sv'], 'en' => ['name' => 'English', 'url' => '/en/', 'locale' => 'en']]);
        Collection::make('pages')->routes('/{slug}')->sites(['default', 'en'])->save();
        Entry::make()->id('cookies')->collection('pages')->locale('default')->slug('kakor')->data(['title' => 'Kakor'])->save();
        $set = GlobalSet::make('configuration')->title('Configuration');
        $set->sites(['default' => null, 'en' => 'default'])->save();
        $set->in('default')->data(['consent_policy_page' => 'cookies'])->save();
        config(['core-web.consent.global' => 'configuration', 'core-web.consent.policy_url' => '/en/cookies']);
        Site::setCurrent('en');

        $this->assertStringContainsString('href="/en/cookies"', Blade::render('<x-core-web::consent.banner />'));
    }

    public function test_an_empty_global_keeps_the_default_texts_and_policy_url(): void
    {
        $set = GlobalSet::make('configuration')->title('Configuration');
        $set->save();
        $set->in('default')->data(['consent_title' => ' '])->save();
        config(['core-web.consent.global' => 'configuration', 'core-web.consent.policy_url' => '/cookies']);

        $html = Blade::render('<x-core-web::consent.banner />');

        $this->assertStringContainsString('We use cookies', $html);
        $this->assertStringContainsString('href="/cookies"', $html);
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
        $this->assertSame(['id', 'consent_id', 'site', 'action', 'categories', 'policy_version', 'created_at', 'fingerprint', 'locale'], array_keys((array) $row));
    }

    public function test_a_choice_names_the_banner_it_was_made_on_and_keeps_that_banner_once(): void
    {
        app()->setLocale(Site::current()->lang());
        $fingerprint = Consent::script()['fingerprint'];
        $choice = ['action' => 'accept_all', 'categories' => ['statistics', 'marketing'], 'version' => 1, 'site' => 'default', 'fingerprint' => $fingerprint];

        $this->postJson('/!/core-web/consent', ['id' => '0b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b'] + $choice)->assertNoContent();
        $this->postJson('/!/core-web/consent', ['id' => '1b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b'] + $choice)->assertNoContent();

        $this->assertSame([$fingerprint, $fingerprint], DB::table('core_web_consents')->pluck('fingerprint')->all());
        $this->assertSame(1, DB::table('core_web_consent_snapshots')->count());

        $banner = ConsentSnapshot::find($fingerprint);
        $this->assertSame('Vi använder kakor', $banner['texts']['title']);
        $this->assertSame('Neka alla', $banner['texts']['buttons']['reject_all']);
        $this->assertSame(['bk_id'], array_column($banner['categories']['marketing'][1]['cookies'], 'name'));
        $this->assertSame('sv', DB::table('core_web_consents')->value('locale'));
    }

    public function test_a_page_rendered_in_another_language_than_its_site_s_keeps_the_banner_in_that_language(): void
    {
        app()->setLocale('en');
        $script = Consent::script();

        $this->postJson('/!/core-web/consent', ['id' => '0b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b', 'action' => 'reject_all', 'categories' => [], 'version' => 1, 'site' => 'default', 'fingerprint' => $script['fingerprint'], 'lang' => $script['lang']])->assertNoContent();

        $this->assertSame('en', DB::table('core_web_consents')->value('locale'));
        $this->assertSame('We use cookies', ConsentSnapshot::find($script['fingerprint'])['texts']['title']);
    }

    public function test_a_choice_on_a_cached_banner_is_logged_under_its_own_fingerprint_without_replacing_today_s(): void
    {
        $this->postJson('/!/core-web/consent', [
            'id' => '0b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b',
            'action' => 'reject_all',
            'categories' => [],
            'version' => 1,
            'site' => 'default',
            'fingerprint' => str_repeat('a', 64),
        ])->assertNoContent();

        $this->assertSame(str_repeat('a', 64), DB::table('core_web_consents')->value('fingerprint'));
        $this->assertSame(0, DB::table('core_web_consent_snapshots')->count());
    }

    public function test_the_fingerprint_follows_the_texts_while_the_scope_follows_only_what_is_agreed_to(): void
    {
        $fingerprint = Consent::script()['fingerprint'];
        $scope = Consent::script()['scope'];

        app('translator')->addLines(['*.We use cookies' => 'Cookies, please'], 'en');

        $this->assertNotSame($fingerprint, Consent::script()['fingerprint']);
        $this->assertSame($scope, Consent::script()['scope']);

        config(['core-web.consent.vendors' => ['google_analytics', 'hubspot']]);

        $this->assertNotSame($scope, Consent::script()['scope']);
    }

    public function test_a_returning_choice_from_before_a_vendor_was_added_is_asked_for_again(): void
    {
        $html = Blade::render('<x-core-web::consent.head />');

        $this->assertStringContainsString('read.s[settings.site] === settings.scope', $html);
        $this->assertStringContainsString('\\u0022scope\\u0022:\\u0022'.ConsentSnapshot::scope().'\\u0022', $html);
    }

    public function test_consent_show_prints_a_visitor_s_choices_and_the_banner_as_json(): void
    {
        app()->setLocale(Site::current()->lang());
        $fingerprint = Consent::script()['fingerprint'];
        $this->postJson('/!/core-web/consent', ['id' => '0b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b', 'action' => 'custom', 'categories' => ['statistics'], 'version' => 1, 'site' => 'default', 'fingerprint' => $fingerprint])->assertNoContent();

        $this->artisan('consent:show', ['id' => '0B6F3A9E-7d1c-4e2a-9f3b-2c1d4e5f6a7b'])
            ->expectsOutputToContain('Vi använder kakor')
            ->expectsOutputToContain('bk_id')
            ->assertSuccessful();
        $this->artisan('consent:show', ['id' => '9b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b'])->assertFailed();
        $this->artisan('consent:show')->expectsOutputToContain(substr($fingerprint, 0, 12))->assertSuccessful();
    }

    public function test_the_consent_log_utility_shows_and_exports_a_visitor_s_evidence_to_a_super_user_only(): void
    {
        app()->setLocale(Site::current()->lang());
        $id = '0b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b';
        $this->postJson('/!/core-web/consent', ['id' => $id, 'action' => 'accept_all', 'categories' => ['statistics', 'marketing'], 'version' => 1, 'site' => 'default', 'fingerprint' => Consent::script()['fingerprint']])->assertNoContent();

        $this->actingAs(User::make()->id('consent-editor')->email('consent-editor@example.com'));
        $this->assertNotSame(200, $this->get(cp_route('utilities.consent', ['id' => $id]))->getStatusCode());
        $this->assertNotSame(200, $this->get(cp_route('utilities.consent.export', ['id' => $id]))->getStatusCode());

        $this->actingAs(User::make()->id('consent-super')->email('consent-super@example.com')->makeSuper());
        $html = $this->get(cp_route('utilities.consent', ['id' => $id]))->assertOk()->viewData('page')['props']['html'];
        $this->assertStringContainsString('Vi använder kakor', $html);
        $this->assertStringContainsString('<tbody v-pre>', $html);

        $export = $this->get(cp_route('utilities.consent.export', ['id' => $id]))->assertOk();
        $this->assertStringContainsString('attachment', (string) $export->headers->get('Content-Disposition'));
        $this->assertSame($id, $export->json('consent_id'));
        $this->assertSame(['statistics', 'marketing'], $export->json('choices.0.categories'));
        $this->get(cp_route('utilities.consent.export', ['id' => '9b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b']))->assertNotFound();
    }

    public function test_prune_keeps_a_banner_while_a_choice_names_it(): void
    {
        DB::table('core_web_consent_snapshots')->insert([
            ['fingerprint' => str_repeat('a', 64), 'site' => 'default', 'locale' => 'en_US', 'policy_version' => 1, 'content' => '{}', 'created_at' => now()->subDays(2000)],
            ['fingerprint' => str_repeat('b', 64), 'site' => 'default', 'locale' => 'en_US', 'policy_version' => 1, 'content' => '{}', 'created_at' => now()->subDays(2000)],
        ]);
        DB::table('core_web_consents')->insert(['consent_id' => 'b', 'site' => 'default', 'action' => 'reject_all', 'categories' => '[]', 'policy_version' => 1, 'fingerprint' => str_repeat('b', 64), 'created_at' => now()->subDays(10)]);

        $this->artisan('consent:prune')->assertSuccessful();

        $this->assertSame([str_repeat('b', 64)], DB::table('core_web_consent_snapshots')->pluck('fingerprint')->all());
    }

    public function test_a_site_that_has_not_run_the_snapshots_migration_still_logs_the_choice(): void
    {
        Schema::drop('core_web_consent_snapshots');
        Schema::drop('core_web_consents');
        (require __DIR__.'/../../database/migrations/2026_10_23_000000_create_core_web_consents_table.php')->up();

        $this->postJson('/!/core-web/consent', ['id' => '0b6f3a9e-7d1c-4e2a-9f3b-2c1d4e5f6a7b', 'action' => 'reject_all', 'categories' => [], 'version' => 1, 'site' => 'default', 'fingerprint' => Consent::script()['fingerprint']])->assertNoContent();

        $this->assertSame(1, DB::table('core_web_consents')->count());
    }

    public function test_the_consent_utility_says_what_to_run_before_the_snapshots_migration(): void
    {
        Schema::drop('core_web_consent_snapshots');

        $data = ConsentUtilityController::data(request());

        $this->assertFalse($data['ready']);
        $this->assertSame([], $data['totals']);
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
