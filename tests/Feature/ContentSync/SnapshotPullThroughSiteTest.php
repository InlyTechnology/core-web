<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\ContentSync\GuardSyncRequests;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\SyncLimits;
use Inly\CoreWeb\ContentSync\SyncTokens;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * `core:pull` reads the snapshots through the live site's sync API, signed
 * in with `content:login`; this app plays the live site too, so the cache is
 * shared and what is pinned is checked by its marker.
 */
class SnapshotPullThroughSiteTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['snapshots', 'content_sync'];

    /** @var list<string>|null what the site's own Core holds; null while it is down */
    private ?array $core = ['prod-1', 'prod-2'];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('local');
        config([
            'core-web.content_sync.tokens.disk' => 'local',
            'core-web.content_sync.remote_url' => 'https://remote.test',
            'core-web.client.base_url' => 'https://core.test',
            'core-web.client.client_id' => 'site',
            'core-web.client.client_secret' => 'site-secret',
        ]);
        $this->roles(['editor' => ['access cp', 'pull content'], 'reader' => ['access cp']]);

        app(SnapshotRegistry::class)->register($this->snapshot());
        $this->token = $this->tokenFor($this->cmsUser('boss@example.com', super: true));
        RemoteSite::store('https://remote.test', $this->token, ['name' => 'Boss', 'email' => 'boss@example.com'], now()->addDays(30)->toIso8601String());
        $this->routeRemoteToThisApp();
    }

    protected function tearDown(): void
    {
        RemoteSite::forget();

        parent::tearDown();
    }

    private function snapshot(string $type = 'things'): Snapshot
    {
        return Snapshot::make($type)
            ->ttl(fresh: 900, stale: 3600, retry: 60)
            ->pull(fn (): array => collect(iterator_to_array(CoreClient::fromConfig()->objects('things'), false))
                ->mapWithKeys(fn (array $document) => [$document['data']['id'] => $document['data']])
                ->all());
    }

    private function routeRemoteToThisApp(): void
    {
        Http::swap(new Factory);
        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);

            if ($host === 'core.test') {
                return $this->coreResponse($request);
            }

            if ($host !== 'remote.test') {
                return Http::response('unexpected host', 500);
            }

            if ($answer = $this->loginAnswer($request)) {
                return $answer;
            }

            $server = ['HTTP_ACCEPT' => 'application/json'];

            if ($request->hasHeader('Authorization')) {
                $server['HTTP_AUTHORIZATION'] = $request->header('Authorization')[0];
            }

            $response = $this->call($request->method(), $request->url(), [], [], [], $server);

            return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
        });
    }

    private function coreResponse(Request $request)
    {
        if ($this->core === null) {
            return Http::response('down', 503);
        }

        if (str_ends_with($request->url(), '/oauth/token')) {
            return Http::response(['access_token' => 'site-access-token', 'expires_in' => 3600]);
        }

        return Http::response([
            'data' => array_map(fn (string $id) => ['id' => $id, 'type' => 'things'], $this->core),
            'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($this->core)],
        ]);
    }

    private function store(): SnapshotStore
    {
        return new SnapshotStore(Cache::store(), app('log'));
    }

    /** @return list<string> */
    private function documents(string $type = 'things'): array
    {
        return array_map('strval', array_keys($this->store()->documents(app(SnapshotRegistry::class)->get($type))));
    }

    public function test_it_pulls_through_the_site_and_pins_the_copy(): void
    {
        $this->artisan('core:pull')->expectsOutputToContain('things: 2 documents pinned from remote.test')->assertSuccessful();

        $this->assertSame(['prod-1', 'prod-2'], $this->documents());
        $this->assertSame('remote.test', $this->store()->pinned(app(SnapshotRegistry::class)->get('things'))['source']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://remote.test/!/core-web/sync/snapshots/things?cursor=0' && $request->header('Authorization') === ['Bearer '.$this->token]);
    }

    public function test_pulling_again_replaces_the_pin(): void
    {
        $this->artisan('core:pull')->assertSuccessful();
        $first = $this->store()->pinned(app(SnapshotRegistry::class)->get('things'));

        // The live site's copy moved on; this app shares its cache, so its answer is given here.
        Http::swap(new Factory);
        Http::fake(['remote.test/*' => Http::response([
            'version' => 'next',
            'documents' => [['id' => 'prod-3', 'document' => ['id' => 'prod-3']]],
            'next_cursor' => null,
            'stale' => false,
        ])]);
        $this->travel(1)->minute();

        $this->artisan('core:pull')->expectsOutputToContain('things: 1 documents pinned from remote.test')->assertSuccessful();

        $this->assertSame(['prod-3'], $this->documents());
        $this->assertNotSame($first['at'], $this->store()->pinned(app(SnapshotRegistry::class)->get('things'))['at']);
    }

    public function test_the_site_serves_the_copy_it_holds_without_reading_its_core(): void
    {
        $this->assertSame(2, $this->store()->refresh(app(SnapshotRegistry::class)->get('things')));
        $this->core = null;
        $this->routeRemoteToThisApp();

        $this->artisan('core:pull')->expectsOutputToContain('things: 2 documents pinned from remote.test')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'core.test/'));
    }

    public function test_a_stale_copy_on_the_site_is_pinned_and_said(): void
    {
        $this->store()->refresh(app(SnapshotRegistry::class)->get('things'));
        Cache::forget('core:things:v1:fresh');

        $this->artisan('core:pull')->expectsOutputToContain('the site\'s own copy is stale')->assertSuccessful();
    }

    public function test_a_long_snapshot_comes_in_pages(): void
    {
        config(['core-web.content_sync.page_size' => 1]);
        $this->core = ['a', 'b', 'c'];

        $this->artisan('core:pull')->expectsOutputToContain('things: 3 documents pinned')->assertSuccessful();

        $this->assertSame(['a', 'b', 'c'], $this->documents());
        Http::assertSentCount(5);
    }

    private function page(string $version, ?int $next, string $id = 'x')
    {
        return Http::response(['type' => 'things', 'version' => $version, 'stale' => false, 'total' => 2, 'documents' => [['id' => $id, 'document' => ['id' => $id]]], 'next_cursor' => $next]);
    }

    public function test_a_copy_that_changes_between_pages_starts_the_pull_over(): void
    {
        Http::swap(new Factory);
        Http::fake(['remote.test/*' => Http::sequence()
            ->pushResponse($this->page('one', 1, 'a'))
            ->pushResponse($this->page('two', null, 'b'))
            ->pushResponse($this->page('two', 1, 'c'))
            ->pushResponse($this->page('two', null, 'd'))]);

        $this->artisan('core:pull')->expectsOutputToContain('things: 2 documents pinned')->assertSuccessful();

        $this->assertSame(['c', 'd'], $this->documents());
    }

    public function test_a_copy_that_keeps_changing_pins_nothing(): void
    {
        $sequence = Http::sequence();

        foreach (range(1, 3) as $attempt) {
            $sequence->pushResponse($this->page("a{$attempt}", 1))->pushResponse($this->page("b{$attempt}", null));
        }

        Http::swap(new Factory);
        Http::fake(['remote.test/*' => $sequence]);

        $this->artisan('core:pull')->expectsOutputToContain('kept changing while pulling')->assertFailed();

        $this->assertNull($this->store()->pinned(app(SnapshotRegistry::class)->get('things')));
        Http::assertSentCount(6);
    }

    public function test_a_rate_limit_or_a_copy_still_filling_is_waited_out_as_the_site_asks(): void
    {
        Sleep::fake();
        Http::swap(new Factory);
        Http::fake(['remote.test/*' => Http::sequence()
            ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '7'])
            ->push(['error' => 'snapshot_unavailable'], 503, ['Retry-After' => '600'])
            ->pushResponse($this->page('one', null))]);

        $this->artisan('core:pull')->expectsOutputToContain('things: 1 documents pinned')->assertSuccessful();

        Sleep::assertSequence([Sleep::for(7)->seconds(), Sleep::for(60)->seconds()]);
    }

    public function test_waiting_gives_up_after_a_few_tries(): void
    {
        Sleep::fake();
        Http::swap(new Factory);
        Http::fake(['remote.test/*' => Http::response(['error' => 'snapshot_unavailable', 'message' => 'No copy yet.'], 503, ['Retry-After' => '5'])]);

        $this->artisan('core:pull')->expectsOutputToContain('things: nothing pinned from remote.test (No copy yet.)')->assertFailed();

        Sleep::assertSleptTimes(3);
    }

    public function test_a_timeout_says_the_site_may_still_be_filling_its_copy(): void
    {
        Http::swap(new Factory);
        Http::fake(['remote.test/*' => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds')]);

        $this->artisan('core:pull')->expectsOutputToContain('may still be filling its copy from its Core: try again in a minute')->assertFailed();
    }

    public function test_a_site_without_the_endpoint_is_named_as_out_of_date(): void
    {
        Http::swap(new Factory);
        Http::fake(['remote.test/*' => Http::response('<h1>Not found</h1>', 404)]);

        $this->artisan('core:pull')->expectsOutputToContain('no snapshot endpoint (HTTP 404); is its core-web up to date?')->assertFailed();
    }

    public function test_an_empty_copy_with_nothing_here_writes_nothing(): void
    {
        Http::swap(new Factory);
        Http::fake(['remote.test/*' => Http::response(['type' => 'things', 'version' => 'v', 'stale' => false, 'total' => 0, 'documents' => [], 'next_cursor' => null])]);

        $this->artisan('core:pull')->expectsOutputToContain('things: nothing pinned')->assertFailed();

        $this->assertNull(Cache::get('core:things:v1:fresh'));
        $this->assertNull(Cache::get('core:things:v1:last_good'));
    }

    public function test_an_empty_copy_pins_nothing_and_keeps_an_earlier_pin(): void
    {
        $this->artisan('core:pull')->assertSuccessful();
        Http::swap(new Factory);
        Http::fake(['remote.test/*' => Http::response(['type' => 'things', 'version' => 'v', 'stale' => false, 'total' => 0, 'documents' => [], 'next_cursor' => null])]);

        $this->artisan('core:pull')->expectsOutputToContain('things: nothing pinned from remote.test')->assertFailed();

        $this->assertSame(['prod-1', 'prod-2'], $this->documents());
        $this->assertNotNull($this->store()->pinned(app(SnapshotRegistry::class)->get('things')));
    }

    public function test_core_warm_leaves_a_copy_pinned_through_the_site_alone(): void
    {
        $this->artisan('core:pull')->assertSuccessful();
        $this->core = ['local-1'];

        $this->artisan('core:warm', ['type' => 'things'])->expectsOutputToContain('things: pinned by core:pull from remote.test')->assertSuccessful();

        $this->assertSame(['prod-1', 'prod-2'], $this->documents());
    }

    public function test_without_a_login_a_run_without_a_terminal_points_at_content_login(): void
    {
        RemoteSite::forget();

        $this->artisan('core:pull', ['--no-interaction' => true])->expectsOutputToContain('not signed in to https://remote.test: run php artisan content:login.')->assertFailed();

        config(['core-web.content_sync.remote_url' => '']);

        $this->artisan('core:pull')->expectsOutputToContain('set core-web.content_sync.remote_url to the live site, then run php artisan content:login')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_a_refused_login_is_forgotten(): void
    {
        app(SyncTokens::class)->revoke(app(SyncTokens::class)->all()[0]);

        $this->artisan('core:pull', ['--no-interaction' => true])
            ->expectsOutputToContain('refused this machine\'s login: run php artisan content:login')
            ->expectsOutputToContain('Not pulled: things.')
            ->assertFailed();

        $this->assertNull(RemoteSite::fromStorage());
    }

    public function test_in_a_terminal_it_signs_in_first_and_pulls(): void
    {
        RemoteSite::forget();
        $this->approveLoginsWith($this->token);

        $this->artisan('core:pull')
            ->expectsOutputToContain('not signed in to https://remote.test yet')
            ->expectsOutputToContain('Signed in to https://remote.test as Boss')
            ->expectsOutputToContain('things: 2 documents pinned from remote.test')
            ->assertSuccessful();

        $this->assertSame(['prod-1', 'prod-2'], $this->documents());
        $this->assertSame($this->token, RemoteSite::fromStorage()->token());
    }

    public function test_in_a_terminal_a_refused_login_signs_in_again_and_pulls(): void
    {
        app(SyncTokens::class)->revoke(app(SyncTokens::class)->all()[0]);
        $fresh = $this->tokenFor($this->cmsUser('boss@example.com', super: true));
        $this->approveLoginsWith($fresh);

        $this->artisan('core:pull')
            ->expectsOutputToContain('refused this machine\'s login; signing in again')
            ->expectsOutputToContain('things: 2 documents pinned from remote.test')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sync/snapshots/things') && $request->header('Authorization') === ['Bearer '.$fresh]);
    }

    public function test_in_a_terminal_a_failed_sign_in_pins_nothing(): void
    {
        RemoteSite::forget();
        Sleep::fake();
        Process::fake();
        $this->fakeLoginEndpoints(Http::response(['error' => 'access_denied'], 400));

        $this->artisan('core:pull')->expectsOutputToContain('denied')->assertFailed();

        $this->assertNull($this->store()->pinned(app(SnapshotRegistry::class)->get('things')));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/sync/snapshots/'));
    }

    public function test_a_site_that_refuses_even_a_fresh_login_stops_after_one_sign_in(): void
    {
        app(SnapshotRegistry::class)->register($this->snapshot('others'));
        app(SyncTokens::class)->revoke(app(SyncTokens::class)->all()[0]);
        $this->approveLoginsWith('a-token-this-site-never-issued');

        $this->artisan('core:pull')
            ->expectsOutputToContain('refused even the login just made')
            ->expectsOutputToContain('Not pulled: things, others.')
            ->assertFailed();

        $this->assertSame(1, $this->signIns());
        $this->assertNull($this->store()->pinned(app(SnapshotRegistry::class)->get('things')));
    }

    public function test_a_first_sign_in_the_site_refuses_is_not_repeated(): void
    {
        RemoteSite::forget();
        $this->approveLoginsWith('a-token-this-site-never-issued');

        $this->artisan('core:pull')->expectsOutputToContain('refused even the login just made')->assertFailed();

        $this->assertSame(1, $this->signIns());
    }

    public function test_the_endpoint_runs_behind_the_sync_guards(): void
    {
        $middleware = Route::getRoutes()->getByName('core-web.sync.snapshot')->gatherMiddleware();

        $this->assertContains(GuardSyncRequests::class, $middleware);
        $this->assertContains('throttle:'.SyncLimits::REQUESTS, $middleware);
        $this->assertContains('core-web.sync', $middleware);
    }

    public function test_the_endpoint_needs_a_token_that_may_pull(): void
    {
        $this->getJson('/!/core-web/sync/snapshots/things')->assertStatus(401)->assertJsonPath('error', 'sync_token_refused');

        $reader = $this->tokenFor($this->cmsUser('reader@example.com', roles: ['reader']));
        $this->withToken($reader)->getJson('/!/core-web/sync/snapshots/things')->assertStatus(401);

        $editor = $this->tokenFor($this->cmsUser('ann@example.com', roles: ['editor']));
        $this->withToken($editor)->getJson('/!/core-web/sync/snapshots/things')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_a_site_running_locally_refuses_the_endpoint(): void
    {
        $this->app['env'] = 'local';

        $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->assertStatus(403)->assertJsonPath('error', 'local_remote');
    }

    public function test_the_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things');
        }

        $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->assertStatus(429);
    }

    public function test_the_endpoint_serves_only_the_snapshots_the_site_registers(): void
    {
        $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/staff')->assertNotFound()->assertJsonPath('error', 'unknown_snapshot');
        $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things?cursor=-1')->assertStatus(422);

        config(['core-web.features.snapshots' => false]);
        $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_endpoint_says_when_it_has_nothing_to_serve_and_leaks_no_secret(): void
    {
        $this->core = null;

        $response = $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->assertStatus(503)->assertJsonPath('error', 'snapshot_unavailable');

        $this->assertStringNotContainsString('site-secret', $response->getContent());
    }

    public function test_while_its_core_is_down_the_site_does_not_ask_it_again_on_every_request(): void
    {
        $this->core = null;
        $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->assertStatus(503);
        $sent = count(Http::recorded(fn (Request $request) => str_contains($request->url(), 'core.test/')));

        $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->assertStatus(503)->assertHeader('Retry-After', '60');

        $this->assertGreaterThan(0, $sent);
        $this->assertCount($sent, Http::recorded(fn (Request $request) => str_contains($request->url(), 'core.test/')));
    }

    public function test_the_version_follows_the_stored_copy(): void
    {
        $first = $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->json('version');
        $this->assertSame($first, $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->json('version'));

        $this->core = ['prod-3'];
        $this->store()->refresh(app(SnapshotRegistry::class)->get('things'));

        $this->assertNotSame($first, $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->json('version'));
    }

    public function test_the_export_carries_documents_and_nothing_of_the_site_s_client(): void
    {
        $body = $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->assertOk()->json();

        $this->assertSame(['type', 'version', 'stale', 'total', 'documents', 'next_cursor'], array_keys($body));
        $this->assertSame([['id' => 'prod-1', 'document' => ['id' => 'prod-1', 'type' => 'things']], ['id' => 'prod-2', 'document' => ['id' => 'prod-2', 'type' => 'things']]], $body['documents']);
        $this->assertStringNotContainsString('site-secret', json_encode($body));
        $this->assertStringNotContainsString('site-access-token', json_encode($body));
    }
}
