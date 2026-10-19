<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Client\RemoteCoreLogin;
use Inly\CoreWeb\ControlPanel\CoreNavLink;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Tests\TestCase;

/** `core:pull --login`: a browser approval in Core in place of a shared secret, reading as the site's own client. */
class PullSnapshotsLoginTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $polls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Sleep::fake(syncWithCarbon: true);
        RemoteCoreLogin::forget();
        config([
            'core-web.remote.base_url' => 'https://prod.test',
            'core-web.remote.client_id' => 'site-client',
            'core-web.remote.client_secret' => '',
        ]);

        app(SnapshotRegistry::class)->register(Snapshot::make('things')
            ->ttl(fresh: 900, stale: 3600, retry: 60)
            ->pull(fn (): array => collect(iterator_to_array(CoreClient::fromConfig()->objects('things'), false))
                ->mapWithKeys(fn (array $document) => [$document['data']['id'] => $document['data']])
                ->all()));
    }

    protected function tearDown(): void
    {
        RemoteCoreLogin::forget();

        parent::tearDown();
    }

    /** @param  list<array<string, mixed>>  $polls  the token endpoint's answers, in order */
    private function fakeCore(array $polls, int $tokenStatus = 200): void
    {
        $this->polls = $polls;

        Http::swap(new Factory);
        Http::fake([
            'prod.test/api/local-dev/logins' => Http::response([
                'device_code' => 'device-1',
                'user_code' => 'BCDF-GHJK',
                'verification_uri' => 'https://prod.test/local-dev/approve',
                'verification_uri_complete' => 'https://prod.test/local-dev/approve?code=BCDF-GHJK',
                'expires_in' => 600,
                'interval' => 5,
            ]),
            'prod.test/api/local-dev/logins/token' => function () {
                $answer = array_shift($this->polls) ?? ['error' => 'expired_token'];

                return Http::response($answer, isset($answer['error']) ? 400 : 200);
            },
            'prod.test/api/local-dev/logins/revoke' => Http::response(null, 204),
            'prod.test/oauth/token' => $tokenStatus === 200
                ? Http::response(['access_token' => 'site-token', 'expires_in' => 3600])
                : Http::response(['error' => 'invalid_grant'], $tokenStatus),
            'prod.test/api/v1/objects/things*' => Http::response([
                'data' => [['id' => 'prod-1', 'type' => 'things']],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1],
            ]),
        ]);
    }

    public function test_login_approves_in_core_stores_the_token_and_pulls_as_the_site_s_client(): void
    {
        $this->fakeCore([['error' => 'authorization_pending'], ['login_token' => 'login-1', 'expires_at' => now()->addDays(30)->toIso8601String(), 'approved_by' => 'Mattias']]);

        $this->artisan('core:pull --login')
            ->expectsOutputToContain('https://prod.test/local-dev/approve?code=BCDF-GHJK')
            ->expectsOutputToContain('approved by Mattias')
            ->expectsOutputToContain('things: 1 documents pinned from prod.test')
            ->assertSuccessful();

        $this->assertSame('login-1', RemoteCoreLogin::stored('https://prod.test', 'site-client')?->token());
        $this->assertSame('600', substr(sprintf('%o', fileperms(RemoteCoreLogin::path())), -3));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://prod.test/oauth/token'
            && $request['grant_type'] === RemoteCoreLogin::GRANT
            && $request['client_id'] === 'site-client'
            && $request['login_token'] === 'login-1'
            && ! isset($request['client_secret']));
    }

    public function test_a_stored_login_pulls_without_asking_again(): void
    {
        $this->fakeCore([['login_token' => 'login-1', 'expires_at' => now()->addDays(30)->toIso8601String()]]);
        $this->artisan('core:pull --login')->assertSuccessful();
        Cache::flush();

        $this->artisan('core:pull')->doesntExpectOutputToContain('Approve this machine')->assertSuccessful();
    }

    public function test_slow_down_waits_longer_between_polls(): void
    {
        $this->fakeCore([['error' => 'slow_down'], ['login_token' => 'login-1']]);

        $this->artisan('core:pull --login')->assertSuccessful();

        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(10)->seconds()]);
    }

    public function test_a_denied_login_stores_nothing_and_pulls_nothing(): void
    {
        $this->fakeCore([['error' => 'access_denied']]);

        $this->artisan('core:pull --login')->expectsOutputToContain('denied in Core')->assertFailed();

        $this->assertNull(RemoteCoreLogin::stored('https://prod.test', 'site-client'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/v1/objects'));
    }

    public function test_without_a_login_or_secret_a_script_is_told_to_log_in(): void
    {
        $this->fakeCore([]);

        $this->artisan('core:pull', ['--no-interaction' => true])->expectsOutputToContain('core:pull --login')->assertFailed();
    }

    public function test_a_login_core_no_longer_honours_is_forgotten(): void
    {
        $this->fakeCore([['login_token' => 'login-1']]);
        $this->artisan('core:pull --login')->assertSuccessful();
        Cache::flush();
        $this->fakeCore([], tokenStatus: 400);

        $this->artisan('core:pull')->expectsOutputToContain('no longer accepts')->assertFailed();

        $this->assertNull(RemoteCoreLogin::stored('https://prod.test', 'site-client'));
    }

    public function test_a_login_for_another_core_or_client_is_not_used(): void
    {
        $this->fakeCore([['login_token' => 'login-1']]);
        $this->artisan('core:pull --login')->assertSuccessful();

        $this->assertNull(RemoteCoreLogin::stored('https://other.test', 'site-client'));
        $this->assertNull(RemoteCoreLogin::stored('https://prod.test', 'other-client'));
    }

    public function test_logout_revokes_in_core_and_forgets_the_login(): void
    {
        $this->fakeCore([['login_token' => 'login-1']]);
        $this->artisan('core:pull --login')->assertSuccessful();

        $this->artisan('core:pull --logout')->assertSuccessful();

        $this->assertFileDoesNotExist(RemoteCoreLogin::path());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://prod.test/api/local-dev/logins/revoke' && $request['login_token'] === 'login-1');
    }

    public function test_a_secret_still_pulls_without_a_login(): void
    {
        config(['core-web.remote.client_secret' => 'pull-secret']);
        $this->fakeCore([]);

        $this->artisan('core:pull')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://prod.test/oauth/token' && $request['grant_type'] === 'client_credentials');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/local-dev/'));
    }

    public function test_login_needs_https(): void
    {
        config(['core-web.remote.base_url' => 'http://prod.example.com']);
        $this->app['env'] = 'production';
        $this->fakeCore([]);

        $this->assertFalse(CoreClient::secureUrl('http://prod.example.com'));
    }

    public function test_the_cp_link_points_at_the_remote_core_once_logged_in(): void
    {
        $this->app['env'] = 'local';
        config(['core-web.client.base_url' => '']);
        $this->assertSame('', CoreNavLink::url());

        $this->fakeCore([['login_token' => 'login-1']]);
        $this->artisan('core:pull --login');

        $this->assertSame('https://prod.test', CoreNavLink::url());
    }
}
