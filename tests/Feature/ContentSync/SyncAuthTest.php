<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\ContentSync\AuthenticateSyncToken;
use Inly\CoreWeb\ContentSync\SyncTokens;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\CP\Nav as CpNav;
use Statamic\Facades\Permission;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class SyncAuthTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['content_sync'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['core-web.content_sync.tokens.disk' => 'local']);
        $this->roles(['editor' => ['access cp', 'pull content', 'view pages entries']]);
    }

    private function startLogin(string $machine = 'ann@laptop'): array
    {
        return $this->postJson('/!/core-web/sync/device', ['machine' => $machine])->assertOk()->json();
    }

    private function poll(string $deviceCode)
    {
        return $this->postJson('/!/core-web/sync/token', ['device_code' => $deviceCode]);
    }

    public function test_a_machine_is_signed_in_once_a_cp_user_approves_it(): void
    {
        $device = $this->startLogin();

        $this->assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/', $device['user_code']);
        $this->assertSame(cp_route('core-web.sync.authorize'), $device['verification_uri']);
        $this->assertSame(cp_route('core-web.sync.authorize').'?code='.$device['user_code'], $device['verification_uri_complete']);
        $this->assertSame(600, $device['expires_in']);
        $this->assertSame(5, $device['interval']);

        $this->poll($device['device_code'])->assertStatus(400)->assertExactJson(['error' => 'authorization_pending']);

        $ann = $this->cmsUser('ann@example.com', roles: ['editor']);
        $this->actingAs($ann)->get($device['verification_uri_complete'])->assertOk()
            ->assertSee('calls itself “ann@laptop” to pull', false)->assertSee($device['user_code'])->assertSee('Requested from 127.0.0.1')
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $this->actingAs($ann)->post(cp_route('core-web.sync.approve'), ['code' => strtolower(str_replace('-', '', $device['user_code']))])->assertOk()->assertSee('Approved');

        $this->travel(6)->seconds();
        $token = $this->poll($device['device_code'])->assertOk()->assertJsonPath('user.email', 'ann@example.com')->json();

        $this->assertStringStartsWith('cws_', $token['token']);
        $this->assertSame(now()->addDays(30)->toIso8601String(), $token['expires_at']);
        $this->travel(6)->seconds();
        $this->poll($device['device_code'])->assertStatus(400)->assertExactJson(['error' => 'expired_token']);

        $this->withToken($token['token'])->getJson('/!/core-web/sync/export?type=entries')->assertOk()->assertJsonPath('data', []);
        $this->assertSame(['ann@laptop'], array_map(fn ($record) => $record->machine, app(SyncTokens::class)->forUser($ann->id())));
        $this->assertStringNotContainsString($token['token'], json_encode(Storage::disk('local')->allFiles()).implode('', array_map(fn ($file) => Storage::disk('local')->get($file), Storage::disk('local')->allFiles())));
    }

    public function test_a_denied_login_gets_no_token(): void
    {
        $device = $this->startLogin();

        $this->actingAs($this->cmsUser('ann@example.com', roles: ['editor']))->post(cp_route('core-web.sync.deny'), ['code' => $device['user_code']])->assertOk()->assertSee('Denied');

        $this->poll($device['device_code'])->assertStatus(400)->assertExactJson(['error' => 'access_denied']);
        $this->assertSame([], app(SyncTokens::class)->all());
    }

    public function test_a_login_expires_unapproved(): void
    {
        $device = $this->startLogin();

        $this->travel(601)->seconds();

        $this->poll($device['device_code'])->assertStatus(400)->assertExactJson(['error' => 'expired_token']);
        $this->actingAs($this->cmsUser('ann@example.com', roles: ['editor']))->get($device['verification_uri_complete'])->assertOk()->assertSee('unknown or expired');
        $this->poll('not-a-device-code')->assertStatus(400)->assertExactJson(['error' => 'expired_token']);
    }

    public function test_polling_too_fast_slows_the_machine_down(): void
    {
        $device = $this->startLogin();

        $this->poll($device['device_code'])->assertJsonPath('error', 'authorization_pending');
        $this->travel(2)->seconds();
        $this->poll($device['device_code'])->assertJsonPath('error', 'slow_down');
        // Each slow_down adds 5 seconds: 10 after the first, 15 after this one.
        $this->travel(6)->seconds();
        $this->poll($device['device_code'])->assertJsonPath('error', 'slow_down');
        $this->travel(16)->seconds();
        $this->poll($device['device_code'])->assertJsonPath('error', 'authorization_pending');
    }

    public function test_a_token_s_lifetime_slides_with_use(): void
    {
        $token = $this->tokenFor($this->cmsUser('ann@example.com', roles: ['editor']));

        $this->travel(20)->days();
        $response = $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertOk();
        $this->assertSame(now()->addDays(30)->toIso8601String(), $response->headers->get(AuthenticateSyncToken::EXPIRES_HEADER));

        $this->travel(25)->days();
        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertOk();

        $this->travel(31)->days();
        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized()->assertJsonPath('error', AuthenticateSyncToken::REFUSED);
        $this->assertSame([], app(SyncTokens::class)->all());
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $this->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized();
        $this->withToken('cws_'.str_repeat('a', 48))->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized();
        $this->withToken('../../etc/passwd')->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized();
    }

    public function test_logging_out_revokes_the_token(): void
    {
        $token = $this->tokenFor($this->cmsUser('ann@example.com', roles: ['editor']));

        $this->withToken($token)->postJson('/!/core-web/sync/logout')->assertOk();

        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized();
    }

    public function test_a_user_revokes_their_own_machines_in_the_cp_and_a_super_user_any(): void
    {
        $ann = $this->cmsUser('ann@example.com', roles: ['editor']);
        $eva = $this->cmsUser('eva@example.com', roles: ['editor']);
        $boss = $this->cmsUser('boss@example.com', super: true);
        $annToken = $this->tokenFor($ann, 'ann@laptop');
        $evaToken = $this->tokenFor($eva, 'eva@desktop');
        [$annRecord, $evaRecord] = [app(SyncTokens::class)->forUser($ann->id())[0], app(SyncTokens::class)->forUser($eva->id())[0]];

        $this->actingAs($ann)->get(cp_route('core-web.sync.tokens'))->assertOk()->assertSee('ann@laptop')->assertDontSee('eva@desktop');
        $this->actingAs($ann)->post(cp_route('core-web.sync.revoke', $evaRecord->id))->assertNotFound();
        $this->withToken($evaToken)->getJson('/!/core-web/sync/export?type=entries')->assertOk();

        $this->actingAs($ann)->post(cp_route('core-web.sync.revoke', $annRecord->id))->assertRedirect(cp_route('core-web.sync.tokens'));
        $this->withToken($annToken)->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized();

        $this->actingAs($boss)->get(cp_route('core-web.sync.tokens'))->assertOk()->assertSee('eva@desktop')->assertSee('Eva');
        $this->actingAs($boss)->post(cp_route('core-web.sync.revoke', $evaRecord->id))->assertRedirect();
        $this->withToken($evaToken)->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized();
    }

    public function test_a_user_who_lost_cp_access_is_refused(): void
    {
        $ann = $this->cmsUser('ann@example.com', roles: ['editor']);
        $token = $this->tokenFor($ann);

        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertOk();

        $ann->explicitRoles([])->save();
        app(PermissionCache::class)->clear();

        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized();
    }

    public function test_a_deleted_user_s_token_is_refused(): void
    {
        $ann = $this->cmsUser('ann@example.com', roles: ['editor']);
        $token = $this->tokenFor($ann);

        $ann->delete();

        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized();
    }

    public function test_sync_runs_over_https_only(): void
    {
        $this->app['env'] = 'production';
        $token = $this->tokenFor($this->cmsUser('ann@example.com', roles: ['editor']));

        $this->withToken($token)->getJson('http://example.com/!/core-web/sync/export?type=entries')->assertForbidden()->assertJsonPath('error', 'https_required');
        $this->postJson('http://example.com/!/core-web/sync/device', ['machine' => 'x'])->assertForbidden();

        $this->withToken($token)->getJson('https://example.com/!/core-web/sync/export?type=entries')->assertOk();
        $this->withToken($token)->getJson('http://example.test/!/core-web/sync/export?type=entries')->assertForbidden();
    }

    public function test_a_local_site_is_nobody_s_remote_unless_it_says_so(): void
    {
        $this->app['env'] = 'local';
        $token = $this->tokenFor($this->cmsUser('ann@example.com', roles: ['editor']));

        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertForbidden()->assertJsonPath('error', 'local_remote');
        $this->postJson('/!/core-web/sync/device', ['machine' => 'x'])->assertForbidden()->assertJsonPath('error', 'local_remote');

        config(['core-web.content_sync.allow_local_remote' => true]);

        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertOk();
    }

    public function test_the_approval_page_asks_for_a_code_without_one(): void
    {
        $this->actingAs($this->cmsUser('ann@example.com', roles: ['editor']))->get(cp_route('core-web.sync.authorize'))->assertOk()->assertSee('The code your terminal shows');
        $this->actingAs($this->cmsUser('eva@example.com', roles: ['editor']))->post(cp_route('core-web.sync.approve'), ['code' => 'BCDF-GHJK'])->assertRedirect(cp_route('core-web.sync.authorize'));
    }

    public function test_the_approval_page_is_behind_the_cp_sign_in(): void
    {
        $device = $this->startLogin();

        $this->get($device['verification_uri_complete'])->assertRedirect();
        $this->poll($device['device_code'])->assertJsonPath('error', 'authorization_pending');
    }

    public function test_the_device_endpoint_wants_a_machine_name(): void
    {
        $this->postJson('/!/core-web/sync/device', ['machine' => " \n "])->assertStatus(422);
    }

    public function test_cp_users_get_a_tools_entry(): void
    {
        $this->withRealNav();
        $this->actingAs($this->cmsUser('ann@example.com', roles: ['editor']));

        $items = CpNav::build()->flatMap(fn (array $section) => $section['items'])->map->display()->all();

        $this->assertContains('Content sync', $items);
    }

    public function test_approving_and_using_a_token_needs_the_pull_content_permission(): void
    {
        $this->roles(['editor' => ['access cp', 'pull content', 'view pages entries'], 'viewer' => ['access cp', 'view pages entries']]);
        $viewer = $this->cmsUser('vic@example.com', roles: ['viewer']);
        $device = $this->startLogin();

        $this->actingAs($viewer)->get($device['verification_uri_complete'])->assertForbidden();
        $this->actingAs($viewer)->post(cp_route('core-web.sync.approve'), ['code' => $device['user_code']])->assertForbidden();
        $this->poll($device['device_code'])->assertJsonPath('error', 'authorization_pending');

        $this->withToken($this->tokenFor($viewer))->getJson('/!/core-web/sync/export?type=entries')->assertUnauthorized();
        $this->withToken($this->tokenFor($this->cmsUser('boss@example.com', super: true)))->getJson('/!/core-web/sync/export?type=entries')->assertOk();
        $this->assertContains(AuthenticateSyncToken::PERMISSION, Permission::all()->map->value()->all());
    }

    public function test_starting_a_login_has_a_limit_of_its_own(): void
    {
        $token = $this->tokenFor($this->cmsUser('ann@example.com', roles: ['editor']));

        for ($i = 0; $i < 10; $i++) {
            $this->startLogin();
        }

        $this->postJson('/!/core-web/sync/device', ['machine' => 'ann@laptop'])->assertStatus(429);
        $this->withToken($token)->getJson('/!/core-web/sync/export?type=entries')->assertOk();
    }

    public function test_tokens_default_to_a_private_local_folder(): void
    {
        config(['core-web.content_sync.tokens.disk' => null]);
        $tokens = SyncTokens::fromConfig();
        [, $record] = $tokens->issue($this->cmsUser('ann@example.com', roles: ['editor']), 'ann@laptop');

        try {
            $this->assertFileExists(storage_path('app/private/core-web/sync-tokens/'.$record->hash.'.json'));
        } finally {
            $tokens->revoke($record);
        }
    }
}
