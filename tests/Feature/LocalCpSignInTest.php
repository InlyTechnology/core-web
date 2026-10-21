<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\CmsSignIn\LocalSignIn;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Tests\CmsSignInTestCase;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/** A local checkout without Core signs its CP in as the developer's live-site account, through content:login's token. */
class LocalCpSignInTest extends CmsSignInTestCase
{
    protected array $features = ['cms_sign_in', 'content_sync'];

    private const ME = 'remote.test/!/core-web/sync/me';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        config(['core-web.client.base_url' => '', 'core-web.content_sync.remote_url' => 'https://remote.test']);
        UserGroup::make()->handle('marketing')->title('Marketing')->save();
        RemoteSite::store('https://remote.test', 'cws_'.str_repeat('a', 48), ['name' => 'Ann', 'email' => 'ann@example.com'], now()->addDays(30)->toIso8601String());
    }

    protected function tearDown(): void
    {
        RemoteSite::forget();

        parent::tearDown();
    }

    private function live(array $overrides = []): void
    {
        Http::swap(new Factory);
        Http::fake([self::ME => Http::response([
            'id' => 'live-1',
            'name' => 'Ann',
            'email' => 'Ann@Example.com',
            'super' => false,
            'roles' => ['editor', 'gone'],
            'groups' => ['marketing', 'gone'],
            ...$overrides,
        ])]);
    }

    public function test_the_login_page_goes_to_the_local_sign_in(): void
    {
        $this->get($this->cp('auth/login'))->assertRedirect(route('core-web.local-sign-in', ['next' => '/cp']));
    }

    public function test_a_guest_on_a_cp_page_keeps_it_as_next(): void
    {
        $this->get($this->cp('collections'))->assertRedirect(route('core-web.local-sign-in', ['next' => '/cp/collections']));
    }

    public function test_it_signs_in_as_the_live_account_with_its_roles_and_groups(): void
    {
        $this->live();

        $this->get(route('core-web.local-sign-in', ['next' => '/cp/collections']))->assertRedirect('/cp/collections');

        $user = User::findByEmail('Ann@Example.com') ?? User::all()->first();
        $this->assertSame('live-1', $user->get(LocalSignIn::MARKER));
        $this->assertSame('Ann', $user->name());
        $this->assertFalse($user->isSuper());
        $this->assertSame(['editor'], $user->explicitRoles()->keys()->all());
        $this->assertSame(['marketing'], $user->groups()->keys()->all());
        $this->assertSame((string) $user->id(), (string) Auth::guard('web')->id());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer cws_'.str_repeat('a', 48)));
    }

    public function test_a_super_user_stays_super_and_a_demotion_lands_at_the_next_sign_in(): void
    {
        $this->live(['super' => true, 'roles' => []]);
        $this->get(route('core-web.local-sign-in'))->assertRedirect(cp_route('index'));
        $this->assertTrue(User::all()->first()->isSuper());

        Auth::guard('web')->logout();
        $this->live(['super' => false, 'roles' => []]);
        $this->get(route('core-web.local-sign-in'))->assertRedirect(cp_route('index'));

        $this->assertCount(1, User::all());
        $this->assertFalse(User::all()->first()->isSuper());
    }

    public function test_the_mirrored_account_stays_signed_in(): void
    {
        $this->live();
        $this->get(route('core-web.local-sign-in'));

        $response = $this->get($this->cp());

        $this->assertStringNotContainsString('local-sign-in', (string) $response->headers->get('Location'));
        $this->assertNotNull(Auth::guard('web')->user());
    }

    public function test_an_unsigned_machine_is_told_to_run_content_login(): void
    {
        RemoteSite::forget();

        $this->get(route('core-web.local-sign-in'))->assertStatus(401)->assertSee('php artisan content:login');
    }

    public function test_a_revoked_login_is_told_to_sign_in_again(): void
    {
        Http::fake([self::ME => Http::response(['error' => 'sync_token_refused'], 401)]);

        $this->get(route('core-web.local-sign-in'))->assertStatus(401)->assertSee('php artisan content:login');
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_a_live_site_without_the_endpoint_says_so(): void
    {
        Http::fake([self::ME => Http::response(['message' => 'Not found'], 404)]);

        $this->get(route('core-web.local-sign-in'))->assertStatus(503)->assertSee('predates local sign-in');
    }

    public function test_an_answer_without_a_usable_address_signs_nobody_in(): void
    {
        $this->live(['email' => '../evil@example.com']);

        $this->get(route('core-web.local-sign-in'))->assertStatus(503);
        $this->assertCount(0, User::all());
    }

    public function test_an_account_mirrored_from_another_live_user_is_left_alone(): void
    {
        User::make()->email('ann@example.com')->set(LocalSignIn::MARKER, 'live-2')->save();
        $this->live();

        $this->get(route('core-web.local-sign-in'))->assertForbidden();
        $this->assertSame('live-2', User::all()->first()->get(LocalSignIn::MARKER));
    }

    public function test_an_open_redirect_is_dropped(): void
    {
        $this->live();

        $this->get(route('core-web.local-sign-in', ['next' => '//evil.test/x']))->assertRedirect(cp_route('index'));
    }

    public function test_it_applies_only_on_a_local_machine_without_core(): void
    {
        $this->live();
        config(['core-web.client.base_url' => 'https://core.test']);
        $this->get(route('core-web.local-sign-in'))->assertNotFound();

        config(['core-web.client.base_url' => '']);
        $this->app['env'] = 'production';
        $this->get(route('core-web.local-sign-in'))->assertNotFound();
        $this->get($this->cp('auth/login'))->assertStatus(503);
    }

    public function test_a_mirrored_account_is_refused_once_it_no_longer_applies(): void
    {
        $this->live();
        $this->get(route('core-web.local-sign-in'));

        config(['core-web.client.base_url' => 'https://core.test']);

        $this->assertNull(Auth::guard('web')->getProvider()->retrieveById(User::all()->first()->id()));
    }
}
