<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inly\CoreWeb\CmsSignIn\SsoUserProvider;
use Inly\CoreWeb\Tests\CmsSignInTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\User;

/**
 * The CP is signed into from Core only: the login page goes to Core's sign-in,
 * Statamic's own ways in are refused, and an account SSO did not create cannot
 * sign in at all.
 */
class CpSignInTest extends CmsSignInTestCase
{
    private const TO_CORE = 'https://core.test/sso/cms?site=production&next=%2Fcp';

    protected function setUp(): void
    {
        parent::setUp();

        config(['core-web.client.base_url' => 'https://core.test']);
    }

    public function test_the_guard_s_provider_admits_only_what_core_provisioned(): void
    {
        $this->assertSame(SsoUserProvider::DRIVER, config('auth.providers.users.driver'));
        $this->assertInstanceOf(SsoUserProvider::class, Auth::guard('web')->getProvider());
    }

    public function test_the_cp_login_page_redirects_to_core(): void
    {
        $this->get('/cp/auth/login')->assertRedirect(self::TO_CORE);
    }

    public function test_the_redirect_names_this_site_to_core(): void
    {
        config(['core-web.cms_sign_in.site' => 'staging']);

        $this->get('/cp/auth/login')->assertRedirect('https://core.test/sso/cms?site=staging&next=%2Fcp');
    }

    public function test_core_s_sign_in_path_is_configurable(): void
    {
        config(['core-web.cms_sign_in.core.sign_in' => 'cms/sign-in']);

        $this->get('/cp/auth/login')->assertRedirect('https://core.test/cms/sign-in?site=production&next=%2Fcp');
    }

    public function test_a_local_checkout_without_core_url_signs_in_through_the_remote_core(): void
    {
        $this->app['env'] = 'local';
        config([
            'core-web.client.base_url' => '',
            'core-web.remote.base_url' => 'https://remote-core.test/',
            'core-web.remote.client_id' => 'remote',
            'core-web.remote.client_secret' => 'remote-secret',
        ]);

        $origin = rawurlencode(rtrim(url('/'), '/'));

        $this->get('/cp/auth/login')->assertRedirect("https://remote-core.test/sso/cms?site=local&origin={$origin}&next=%2Fcp");
    }

    public function test_without_core_url_or_a_remote_secret_there_is_no_way_in(): void
    {
        $this->app['env'] = 'local';
        config([
            'core-web.client.base_url' => '',
            'core-web.remote.base_url' => 'https://remote-core.test',
            'core-web.remote.client_id' => 'remote',
            'core-web.remote.client_secret' => '',
        ]);

        $this->get('/cp/auth/login')->assertStatus(503);
    }

    public function test_without_core_there_is_no_way_into_the_cp(): void
    {
        config(['core-web.client.base_url' => '']);

        $this->get('/cp/auth/login')->assertStatus(503);
    }

    public function test_a_guest_on_a_cp_page_goes_to_core_with_that_page_as_next(): void
    {
        config(['core-web.cms_sign_in.site' => 'staging']);

        $location = $this->get('/cp/collections/pages?view=list')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://core.test/sso/cms?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['site' => 'staging', 'next' => '/cp/collections/pages?view=list'], $query);
    }

    public function test_a_guest_opening_the_cp_root_goes_to_core(): void
    {
        $this->get('/cp')->assertRedirect(self::TO_CORE);
    }

    public function test_an_inertia_visit_keeps_statamic_s_login_redirect(): void
    {
        $this->get('/cp/collections', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertRedirect(cp_route('login'));
    }

    public function test_an_expired_session_goes_back_through_core_to_the_page_it_was_on(): void
    {
        $location = $this->get('/cp/auth/login', ['Referer' => url('/cp/collections/pages/entries/abc?site=other')])
            ->assertRedirect()->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('/cp/collections/pages/entries/abc?site=other', $query['next']);
    }

    /** @return array<string, array{string}> */
    public static function refererNotFollowed(): array
    {
        return [
            'another site' => ['https://evil.test/cp/collections'],
            'not the CP' => ['/cars'],
            'the login page itself' => ['/cp/auth/login'],
        ];
    }

    #[DataProvider('refererNotFollowed')]
    public function test_a_referer_that_is_no_page_of_this_cp_lands_on_the_cp_start(string $referer): void
    {
        $this->get('/cp/auth/login', ['Referer' => str_starts_with($referer, '/') ? url($referer) : $referer])->assertRedirect(self::TO_CORE);
    }

    public function test_the_cp_never_asks_an_expired_session_for_a_password(): void
    {
        $user = $this->provisioned('anna@example.com', super: true);

        // Statamic draws its password dialog only while its own CP auth is on; off, a 401 sends the browser to the login page.
        $this->actingAs($user)->get('/cp/auth/login');
        $this->assertFalse(config('statamic.cp.auth.enabled'));
        $this->assertSame('/cp/auth/login', config('statamic.cp.auth.redirect_to'));
    }

    /** @return array<string, array{string, string}> */
    public static function statamicWaysIn(): array
    {
        return [
            'CP password login' => ['POST', '/cp/auth/login'],
            'CP forgot-password page' => ['GET', '/cp/auth/password/reset'],
            'CP reset-link request' => ['POST', '/cp/auth/password/email'],
            'CP reset page' => ['GET', '/cp/auth/password/reset/some-token'],
            'CP reset' => ['POST', '/cp/auth/password/reset'],
            'CP passkey login' => ['POST', '/cp/auth/passkeys'],
            'CP passkey options' => ['GET', '/cp/auth/passkeys/options'],
            'front-end password login' => ['POST', '/!/auth/login'],
            'front-end reset-link request' => ['POST', '/!/auth/password/email'],
            'front-end reset page' => ['GET', '/!/auth/password/reset/some-token'],
            'front-end reset' => ['POST', '/!/auth/password/reset'],
            'front-end registration' => ['POST', '/!/auth/register'],
            'front-end activation page' => ['GET', '/!/auth/activate/some-token'],
            'front-end activation' => ['POST', '/!/auth/activate'],
            'front-end passkey login' => ['POST', '/!/auth/passkeys/auth'],
            'front-end passkey options' => ['GET', '/!/auth/passkeys/options'],
            'OAuth redirect' => ['GET', '/oauth/github'],
            'OAuth callback' => ['GET', '/oauth/github/callback'],
        ];
    }

    #[DataProvider('statamicWaysIn')]
    public function test_statamic_s_own_ways_in_are_refused(string $method, string $uri): void
    {
        $this->provisioned('anna@example.com', password: 'known-password');

        $this->call($method, $uri, ['email' => 'anna@example.com', 'password' => 'known-password'])->assertForbidden();

        $this->assertGuest('web');
    }

    public function test_no_password_signs_anyone_in(): void
    {
        $this->provisioned('anna@example.com', password: 'known-password');
        User::make()->email('agency@example.org')->password('known-password')->makeSuper()->save();

        $this->assertFalse(Auth::guard('web')->attempt(['email' => 'anna@example.com', 'password' => 'known-password']));
        $this->assertFalse(Auth::guard('web')->attempt(['email' => 'agency@example.org', 'password' => 'known-password']));
        $this->assertGuest('web');
    }

    public function test_no_password_reset_link_is_sent(): void
    {
        Notification::fake();
        $this->provisioned('anna@example.com', password: 'known-password');

        $this->assertSame(PasswordBroker::INVALID_USER, Password::broker(config('statamic.users.passwords.resets'))->sendResetLink(['email' => 'anna@example.com']));
        $this->assertSame(PasswordBroker::INVALID_USER, Password::broker(config('statamic.users.passwords.activations'))->sendResetLink(['email' => 'anna@example.com']));

        Notification::assertNothingSent();
    }

    public function test_an_account_without_core_user_id_cannot_sign_in(): void
    {
        User::make()->id('unstamped')->email('agency@example.org')->makeSuper()->save();
        $stamped = $this->provisioned('anna@example.com');

        $provider = Auth::guard('web')->getProvider();
        $this->assertNull($provider->retrieveById('unstamped'));
        $this->assertSame($stamped->id(), $provider->retrieveById($stamped->id())?->id());

        $this->withSession([Auth::guard('web')->getName() => 'unstamped'])
            ->get('/cp')
            ->assertRedirect(self::TO_CORE);
        $this->assertGuest('web');
    }

    public function test_a_stamped_account_keeps_its_session(): void
    {
        $stamped = $this->provisioned('anna@example.com', super: true);

        $this->withSession([Auth::guard('web')->getName() => $stamped->id()])
            ->getJson('/cp/session-timeout')
            ->assertOk();
        $this->assertAuthenticated('web');
    }

    public function test_the_escape_hatch_needs_the_flag_outside_local(): void
    {
        config(['core-web.cms_sign_in.local_password_sign_in' => true]);

        $this->assertFalse(SsoUserProvider::localPasswordSignIn());
        $this->get('/cp/auth/login')->assertRedirect(self::TO_CORE);
    }

    public function test_the_escape_hatch_needs_the_flag_in_local(): void
    {
        $this->app['env'] = 'local';

        $this->assertFalse(SsoUserProvider::localPasswordSignIn());
        $this->get('/cp/auth/login')->assertRedirect(self::TO_CORE);
    }

    public function test_the_escape_hatch_restores_statamic_s_login_locally(): void
    {
        $this->app['env'] = 'local';
        config(['core-web.cms_sign_in.local_password_sign_in' => true]);
        User::make()->id('dev')->email('dev@example.org')->password('local-password')->makeSuper()->save();

        $this->assertTrue(SsoUserProvider::localPasswordSignIn());
        $this->assertNotNull(Auth::guard('web')->getProvider()->retrieveById('dev'));
        $this->assertTrue(Auth::guard('web')->attempt(['email' => 'dev@example.org', 'password' => 'local-password']));
    }

    public function test_the_escape_hatch_ships_closed(): void
    {
        $shipped = require dirname(__DIR__, 2).'/config/core-web.php';

        $this->assertFalse($shipped['cms_sign_in']['local_password_sign_in']);
        $this->assertFalse($shipped['features']['cms_sign_in']);
    }
}
