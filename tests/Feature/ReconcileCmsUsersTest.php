<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inly\CoreWeb\CmsSignIn\CmsHandoff;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\CmsSignInTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\User;

/**
 * `core:reconcile-cms-users` asks Core about the accounts it provisioned and
 * drops what Core no longer backs: never a hand-made account, and nothing at
 * all when Core's answer is incomplete.
 */
class ReconcileCmsUsersTest extends CmsSignInTestCase
{
    use FakesCore;

    private const RECONCILE = 'https://core.test/api/app/cms-users/client/reconcile';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function account(string $email, bool $super = false): void
    {
        $this->provisioned($email, 'x', ['editor', 'author'], $super);
    }

    /** @param  array<string, mixed>  $users */
    private function coreSays(array $users): void
    {
        $this->fakeCore(['core.test/api/app/cms-users/client/reconcile' => Http::response(['users' => $users])]);
    }

    public function test_it_deletes_the_absent_strips_the_revoked_and_leaves_the_rest(): void
    {
        $this->account('gone@example.com');
        $this->account('revoked@example.com', super: true);
        $this->account('Kept@Example.com');
        User::make()->email('agency@example.org')->makeSuper()->save();

        $this->coreSays([
            'gone@example.com' => ['exists' => false, 'cms_access' => false, 'is_admin' => false],
            'revoked@example.com' => ['exists' => true, 'cms_access' => false, 'is_admin' => false],
            'kept@example.com' => ['exists' => true, 'cms_access' => true, 'is_admin' => false],
        ]);

        $this->artisan('core:reconcile-cms-users')
            ->expectsOutputToContain('1 deleted, 1 revoked, 0 demoted')
            ->assertSuccessful();

        $this->assertNull(User::findByEmail('gone@example.com'));

        $revoked = User::findByEmail('revoked@example.com');
        $this->assertFalse($revoked->isSuper());
        $this->assertSame(['author'], $revoked->roles()->keys()->all());

        $this->assertEqualsCanonicalizing(['editor', 'author'], User::findByEmail('Kept@Example.com')->roles()->keys()->all());
        $this->assertTrue(User::findByEmail('agency@example.org')->isSuper());

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::RECONCILE
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer token')
            && $request['site'] === 'production'
            && $request['emails'] === ['gone@example.com', 'revoked@example.com', 'kept@example.com']);
        Http::assertNotSent(fn (HttpRequest $request): bool => isset($request['signature']));
    }

    public function test_a_staging_site_names_itself(): void
    {
        config(['core-web.cms_sign_in.site' => 'staging']);
        $this->account('kept@example.com');
        $this->coreSays(['kept@example.com' => ['exists' => true, 'cms_access' => true, 'is_admin' => false]]);

        $this->artisan('core:reconcile-cms-users')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::RECONCILE && $request['site'] === 'staging');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->account('gone@example.com');
        $this->account('revoked@example.com', super: true);
        $this->coreSays([
            'gone@example.com' => ['exists' => false, 'cms_access' => false, 'is_admin' => false],
            'revoked@example.com' => ['exists' => true, 'cms_access' => false, 'is_admin' => false],
        ]);

        $this->artisan('core:reconcile-cms-users --dry-run')
            ->expectsOutputToContain('DELETE  gone@example.com')
            ->expectsOutputToContain('REVOKE  revoked@example.com')
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        $this->assertNotNull(User::findByEmail('gone@example.com'));
        $this->assertTrue(User::findByEmail('revoked@example.com')->isSuper());
    }

    public function test_an_account_already_stripped_is_not_reported_again(): void
    {
        $this->provisioned('revoked@example.com', 'x', ['author']);
        $this->coreSays(['revoked@example.com' => ['exists' => true, 'cms_access' => false, 'is_admin' => false]]);

        $this->artisan('core:reconcile-cms-users')
            ->doesntExpectOutputToContain('REVOKE')
            ->expectsOutputToContain('0 deleted, 0 revoked, 0 demoted')
            ->assertSuccessful();

        $this->assertSame(['author'], User::findByEmail('revoked@example.com')->roles()->keys()->all());
    }

    public function test_an_admin_who_keeps_cms_access_but_is_no_longer_admin_loses_super(): void
    {
        $this->account('demoted@example.com', super: true);
        $this->account('admin@example.com', super: true);

        $this->coreSays([
            'demoted@example.com' => ['exists' => true, 'cms_access' => true, 'is_admin' => false],
            'admin@example.com' => ['exists' => true, 'cms_access' => true, 'is_admin' => true],
        ]);

        $this->artisan('core:reconcile-cms-users')
            ->expectsOutputToContain('DEMOTE  demoted@example.com')
            ->expectsOutputToContain('0 deleted, 0 revoked, 1 demoted')
            ->assertSuccessful();

        $demoted = User::findByEmail('demoted@example.com');
        $this->assertFalse($demoted->isSuper());
        $this->assertEqualsCanonicalizing(['editor', 'author'], $demoted->roles()->keys()->all());
        $this->assertTrue(User::findByEmail('admin@example.com')->isSuper());
    }

    public function test_dry_run_reports_a_demotion_without_making_it(): void
    {
        $this->account('demoted@example.com', super: true);
        $this->coreSays(['demoted@example.com' => ['exists' => true, 'cms_access' => true, 'is_admin' => false]]);

        $this->artisan('core:reconcile-cms-users --dry-run')
            ->expectsOutputToContain('DEMOTE  demoted@example.com')
            ->assertSuccessful();

        $this->assertTrue(User::findByEmail('demoted@example.com')->isSuper());
    }

    public function test_an_incomplete_answer_changes_nothing(): void
    {
        $this->account('gone@example.com');
        $this->account('unanswered@example.com');
        $this->coreSays(['gone@example.com' => ['exists' => false, 'cms_access' => false, 'is_admin' => false]]);

        $this->artisan('core:reconcile-cms-users')->assertFailed();

        $this->assertNotNull(User::findByEmail('gone@example.com'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedVerdicts(): array
    {
        return [
            'no is_admin' => [['exists' => false, 'cms_access' => false]],
            'no cms_access' => [['exists' => false, 'is_admin' => false]],
            'no exists' => [['cms_access' => false, 'is_admin' => false]],
            'is_admin not a boolean' => [['exists' => false, 'cms_access' => false, 'is_admin' => 0]],
            'exists as a string' => [['exists' => 'false', 'cms_access' => false, 'is_admin' => false]],
        ];
    }

    /** @param  array<string, mixed>  $verdict */
    #[DataProvider('malformedVerdicts')]
    public function test_a_verdict_short_of_three_booleans_changes_nothing(array $verdict): void
    {
        $this->account('gone@example.com', super: true);
        $this->account('kept@example.com', super: true);
        $this->coreSays([
            'gone@example.com' => $verdict,
            'kept@example.com' => ['exists' => true, 'cms_access' => true, 'is_admin' => false],
        ]);

        $this->artisan('core:reconcile-cms-users')->assertFailed();

        $this->assertTrue(User::findByEmail('gone@example.com')->isSuper());
        $this->assertTrue(User::findByEmail('kept@example.com')->isSuper());
    }

    /** @return array<string, array{int}> */
    public static function refusals(): array
    {
        return ['rate limited' => [429], 'forbidden' => [403], 'failing' => [500]];
    }

    #[DataProvider('refusals')]
    public function test_a_refused_ask_fails_and_changes_nothing(int $status): void
    {
        $this->account('gone@example.com');
        $this->fakeCore(['core.test/api/app/cms-users/client/reconcile' => Http::response(['error' => 'refused'], $status)]);

        $this->artisan('core:reconcile-cms-users')->assertFailed();

        $this->assertNotNull(User::findByEmail('gone@example.com'));
    }

    public function test_core_out_of_reach_fails_and_changes_nothing(): void
    {
        $this->account('gone@example.com');
        $this->fakeCore(['core.test/api/app/cms-users/client/reconcile' => fn () => throw new ConnectionException('Connection refused')]);

        $this->artisan('core:reconcile-cms-users')->assertFailed();

        $this->assertNotNull(User::findByEmail('gone@example.com'));
    }

    public function test_a_core_without_the_reconcile_route_warns_and_changes_nothing(): void
    {
        $this->account('gone@example.com');
        $this->fakeCore(['core.test/api/app/cms-users/client/reconcile' => Http::response([], 404)]);

        $this->artisan('core:reconcile-cms-users')
            ->expectsOutputToContain('Nothing reconciled')
            ->assertSuccessful();

        $this->assertNotNull(User::findByEmail('gone@example.com'));
    }

    public function test_without_provisioned_accounts_core_is_not_asked(): void
    {
        $this->fakeCore();
        User::make()->email('agency@example.org')->makeSuper()->save();

        $this->artisan('core:reconcile-cms-users')->assertSuccessful();

        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'reconcile'));
    }

    public function test_an_account_with_no_usable_email_aborts_before_asking(): void
    {
        $this->fakeCore();
        $this->account('gone@example.com');
        User::make()->email('not-an-address')->set('core_user_id', 'x')->save();

        $this->artisan('core:reconcile-cms-users')
            ->expectsOutputToContain('not-an-address')
            ->assertFailed();

        $this->assertNotNull(User::findByEmail('gone@example.com'));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'reconcile'));
    }

    public function test_more_than_one_batch_waits_out_cores_rate_limit(): void
    {
        foreach (range(1, CmsHandoff::RECONCILE_BATCH + 1) as $i) {
            $this->account("person{$i}@example.com");
        }

        $this->fakeCore(['core.test/api/app/cms-users/client/reconcile' => fn (HttpRequest $request) => Http::response([
            'users' => collect($request['emails'])->mapWithKeys(fn ($e) => [$e => ['exists' => true, 'cms_access' => true, 'is_admin' => false]])->all(),
        ])]);

        $this->artisan('core:reconcile-cms-users')->assertSuccessful();

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(1);
    }

    public function test_without_a_client_nothing_is_reconciled(): void
    {
        $this->account('gone@example.com');

        $this->artisan('core:reconcile-cms-users')
            ->expectsOutputToContain('nothing reconciled')
            ->assertSuccessful();

        $this->assertNotNull(User::findByEmail('gone@example.com'));
        Http::assertNothingSent();
    }
}
