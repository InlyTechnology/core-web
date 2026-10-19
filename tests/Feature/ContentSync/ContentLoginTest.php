<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\RemoteSiteError;
use Inly\CoreWeb\Tests\TestCase;

class ContentLoginTest extends TestCase
{
    protected array $features = ['content_sync'];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Process::fake();
        config(['core-web.content_sync.remote_url' => 'https://remote.test/']);
    }

    protected function tearDown(): void
    {
        RemoteSite::forget();

        parent::tearDown();
    }

    private function device(): array
    {
        return [
            'device_code' => 'device-code',
            'user_code' => 'BCDF-GHJK',
            'verification_uri' => 'https://remote.test/cp/core-web/sync/authorize',
            'verification_uri_complete' => 'https://remote.test/cp/core-web/sync/authorize?code=BCDF-GHJK',
            'expires_in' => 600,
            'interval' => 5,
        ];
    }

    public function test_a_login_polls_until_approved_and_stores_the_token(): void
    {
        Http::fake([
            'remote.test/!/core-web/sync/device' => Http::response($this->device()),
            'remote.test/!/core-web/sync/token' => Http::sequence()
                ->push(['error' => 'authorization_pending'], 400)
                ->push(['error' => 'slow_down'], 400)
                ->push(['token' => 'cws_'.str_repeat('a', 48), 'expires_at' => '2030-01-01T00:00:00+00:00', 'user' => ['name' => 'Ann', 'email' => 'ann@example.com']]),
        ]);

        $this->artisan('content:login', ['--no-interaction' => true])
            ->expectsOutputToContain('https://remote.test/cp/core-web/sync/authorize?code=BCDF-GHJK')
            ->expectsOutputToContain('BCDF-GHJK')
            ->expectsOutputToContain('Signed in to https://remote.test as Ann')
            ->assertSuccessful();

        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds(), Sleep::for(10)->seconds()]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/device') && filled($request['machine']));
        Process::assertNothingRan();

        $site = RemoteSite::fromStorage();
        $this->assertSame('https://remote.test', $site->url());
        $this->assertSame(['name' => 'Ann', 'email' => 'ann@example.com'], $site->user());
        $this->assertSame('0600', substr(sprintf('%o', fileperms(RemoteSite::loginFile())), -4));
        $this->assertSame("*\n", file_get_contents(dirname(RemoteSite::loginFile()).'/.gitignore'));
    }

    public function test_a_denied_login_stores_nothing(): void
    {
        Http::fake([
            'remote.test/!/core-web/sync/device' => Http::response($this->device()),
            'remote.test/!/core-web/sync/token' => Http::response(['error' => 'access_denied'], 400),
        ]);

        $this->artisan('content:login', ['--no-interaction' => true])->expectsOutputToContain('denied')->assertFailed();

        $this->assertNull(RemoteSite::fromStorage());
    }

    public function test_it_gives_up_when_the_code_expires(): void
    {
        Http::fake([
            'remote.test/!/core-web/sync/device' => Http::response([...$this->device(), 'expires_in' => 12]),
            'remote.test/!/core-web/sync/token' => Http::response(['error' => 'authorization_pending'], 400),
        ]);

        $this->artisan('content:login', ['--no-interaction' => true])->expectsOutputToContain('expired')->assertFailed();

        Http::assertSentCount(4);
    }

    public function test_an_interactive_login_opens_the_browser(): void
    {
        Http::fake([
            'remote.test/!/core-web/sync/device' => Http::response($this->device()),
            'remote.test/!/core-web/sync/token' => Http::response(['token' => 'cws_'.str_repeat('a', 48), 'expires_at' => null, 'user' => ['name' => 'Ann', 'email' => 'ann@example.com']]),
        ]);

        $this->artisan('content:login')->assertSuccessful();

        if (in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
            Process::assertRan(fn ($process) => in_array('https://remote.test/cp/core-web/sync/authorize?code=BCDF-GHJK', (array) $process->command, true));
        }
    }

    public function test_it_needs_a_remote_url_over_https(): void
    {
        config(['core-web.content_sync.remote_url' => '']);
        $this->artisan('content:login', ['--no-interaction' => true])->expectsOutputToContain('remote_url')->assertFailed();

        config(['core-web.content_sync.remote_url' => 'http://remote.example.com']);
        $this->artisan('content:login', ['--no-interaction' => true])->expectsOutputToContain('https://')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_a_local_remote_may_be_plain_http(): void
    {
        RemoteSite::assertSecure('http://site.test');
        RemoteSite::assertSecure('http://localhost:8000');

        $this->expectException(RemoteSiteError::class);
        RemoteSite::assertSecure('http://site.example.com');
    }

    public function test_logging_out_revokes_the_token_on_the_site(): void
    {
        config(['core-web.content_sync.remote_url' => 'https://remote.test']);
        RemoteSite::store('https://remote.test', 'cws_'.str_repeat('a', 48), ['name' => 'Ann', 'email' => 'ann@example.com'], null);
        Http::fake(['remote.test/!/core-web/sync/logout' => Http::response(['status' => 'revoked'])]);

        $this->artisan('content:logout')->expectsOutputToContain('Signed out of https://remote.test')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer cws_'.str_repeat('a', 48)));
        $this->assertFileDoesNotExist(RemoteSite::loginFile());
    }

    public function test_the_stored_expiry_follows_the_site_s_sliding_one(): void
    {
        config(['core-web.content_sync.remote_url' => 'https://remote.test']);
        RemoteSite::store('https://remote.test', 'cws_'.str_repeat('a', 48), ['name' => 'Ann', 'email' => 'ann@example.com'], now()->addDay()->toIso8601String());
        Http::fake(['remote.test/*' => Http::response(['data' => [], 'next_cursor' => null], 200, ['X-Core-Web-Token-Expires-At' => '2031-05-01T00:00:00+00:00'])]);

        RemoteSite::fromStorage()->get('export', ['type' => 'entries']);

        $this->assertSame('2031-05-01', RemoteSite::fromStorage()->expiresAt()->toDateString());
    }

    public function test_logging_out_revokes_a_login_made_for_another_url(): void
    {
        RemoteSite::store('https://old.test', 'cws_'.str_repeat('a', 48), ['name' => 'Ann', 'email' => 'ann@example.com'], now()->subDay()->toIso8601String());
        Http::fake(['old.test/!/core-web/sync/logout' => Http::response(['status' => 'revoked'])]);

        $this->artisan('content:logout')->expectsOutputToContain('Signed out of https://old.test')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://old.test/!/core-web/sync/logout');
        $this->assertFileDoesNotExist(RemoteSite::loginFile());
    }

    public function test_logging_out_says_when_the_site_was_not_told(): void
    {
        RemoteSite::store('https://remote.test', 'cws_'.str_repeat('a', 48), ['name' => 'Ann', 'email' => 'ann@example.com'], null);
        Http::fake(['*' => Http::response('down', 503)]);

        $this->artisan('content:logout')->expectsOutputToContain('could not be told')->assertSuccessful();
        $this->assertFileDoesNotExist(RemoteSite::loginFile());

        file_put_contents(RemoteSite::loginFile(), '{not json');
        $this->artisan('content:logout')->expectsOutputToContain('could not be read')->assertSuccessful();
        $this->assertFileDoesNotExist(RemoteSite::loginFile());
    }

    public function test_a_new_login_revokes_the_one_it_replaces(): void
    {
        RemoteSite::store('https://remote.test', 'cws_'.str_repeat('o', 48), ['name' => 'Ann', 'email' => 'ann@example.com'], null);
        Http::fake([
            'remote.test/!/core-web/sync/device' => Http::response($this->device()),
            'remote.test/!/core-web/sync/token' => Http::response(['token' => 'cws_'.str_repeat('n', 48), 'expires_at' => null, 'user' => ['name' => 'Ann', 'email' => 'ann@example.com']]),
            'remote.test/!/core-web/sync/logout' => Http::response(['status' => 'revoked']),
        ]);

        $this->artisan('content:login', ['--no-interaction' => true])->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/logout') && $request->hasHeader('Authorization', 'Bearer cws_'.str_repeat('o', 48)));
        $this->assertSame('cws_'.str_repeat('n', 48), RemoteSite::fromStorage()->token());
    }

    public function test_a_redirecting_or_rate_limiting_site_is_named(): void
    {
        Http::fake(['remote.test/*' => Http::sequence()
            ->push('', 302, ['Location' => 'https://www.remote.test/!/core-web/sync/device'])
            ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30']),
        ]);

        $this->artisan('content:login', ['--no-interaction' => true])->expectsOutputToContain('redirects to [https://www.remote.test]; set that URL')->assertFailed();
        $this->artisan('content:login', ['--no-interaction' => true])->expectsOutputToContain('rate limiting this machine (HTTP 429); try again in 30 seconds')->assertFailed();
    }
}
