<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
 * `core:pull` without a remote client secret reads the snapshots through the
 * live site's sync API, signed in with `content:login`; this app plays the
 * live site too, so the cache is shared and what is pinned is checked by its
 * marker.
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
            'core-web.remote.base_url' => 'https://other-core.test',
            'core-web.remote.client_id' => 'pull',
            'core-web.remote.client_secret' => '',
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
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'other-core.test'));
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

    public function test_a_copy_that_changes_between_pages_pins_nothing(): void
    {
        $page = fn (string $version, ?int $next) => Http::response(['type' => 'things', 'version' => $version, 'stale' => false, 'total' => 2, 'documents' => [['id' => 'x', 'document' => ['id' => 'x']]], 'next_cursor' => $next]);
        Http::swap(new Factory);
        Http::fake(['remote.test/*' => Http::sequence()->pushResponse($page('one', 1))->pushResponse($page('two', null))]);

        $this->artisan('core:pull')->expectsOutputToContain('changed while pulling')->assertFailed();

        $this->assertNull($this->store()->pinned(app(SnapshotRegistry::class)->get('things')));
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

    public function test_a_remote_client_secret_pulls_from_core_directly_instead(): void
    {
        config(['core-web.remote.client_secret' => 'pull-secret']);
        Http::swap(new Factory);
        Http::fake([
            'other-core.test/oauth/token' => Http::response(['access_token' => 'pull-token', 'expires_in' => 3600]),
            'other-core.test/api/v1/objects/things*' => Http::response(['data' => [['id' => 'direct', 'type' => 'things']], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]),
        ]);

        $this->artisan('core:pull')->expectsOutputToContain('things: 1 documents pinned from other-core.test')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'remote.test'));
    }

    public function test_without_a_login_or_a_secret_it_points_at_content_login(): void
    {
        RemoteSite::forget();

        $this->artisan('core:pull')->expectsOutputToContain('not signed in to https://remote.test: run php artisan content:login')->assertFailed();

        config(['core-web.content_sync.remote_url' => '']);

        $this->artisan('core:pull')->expectsOutputToContain('php artisan content:login, or set CORE_REMOTE_CLIENT_SECRET')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_a_refused_login_is_forgotten(): void
    {
        app(SyncTokens::class)->revoke(app(SyncTokens::class)->all()[0]);

        $this->artisan('core:pull')->expectsOutputToContain('run php artisan content:login')->assertFailed();

        $this->assertNull(RemoteSite::fromStorage());
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

    public function test_the_export_carries_documents_and_nothing_of_the_site_s_client(): void
    {
        $body = $this->withToken($this->token)->getJson('/!/core-web/sync/snapshots/things')->assertOk()->json();

        $this->assertSame(['type', 'version', 'stale', 'total', 'documents', 'next_cursor'], array_keys($body));
        $this->assertSame([['id' => 'prod-1', 'document' => ['id' => 'prod-1', 'type' => 'things']], ['id' => 'prod-2', 'document' => ['id' => 'prod-2', 'type' => 'things']]], $body['documents']);
        $this->assertStringNotContainsString('site-secret', json_encode($body));
        $this->assertStringNotContainsString('site-access-token', json_encode($body));
    }
}
