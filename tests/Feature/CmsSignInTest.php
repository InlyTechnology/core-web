<?php

namespace Inly\CoreWeb\Tests\Feature;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\CmsSignIn\CmsHandoff;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Testing\CoreEnvelopeSealer;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\CmsSignInTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\User;

/**
 * Single sign-on into the CP from Core: the sealed hand-off is opened here,
 * confirmed with Core, and Core's answer decides the grant. Core is faked.
 */
class CmsSignInTest extends CmsSignInTestCase
{
    use FakesCore;

    private const EMAIL = 'anna@example.com';

    private const VERIFY = 'https://core.test/api/app/cms-sso/client/verify';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_an_editor_is_provisioned_with_the_mapped_role_and_signed_in(): void
    {
        $this->coreAnswers();

        $this->get($this->handoff())->assertRedirect(cp_route('index'));

        $user = User::findByEmail(self::EMAIL);
        $this->assertSame(['editor'], $user->roles()->keys()->all());
        $this->assertFalse($user->isSuper());
        $this->assertSame('Anna Andersson', $user->name());
        $this->assertSame('7', (string) $user->get('core_user_id'));
        $this->assertAuthenticatedAs($user, 'web');

        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), self::VERIFY.'?')
            && $request->hasHeader('Authorization', 'Bearer token')
            && $request['site'] === 'production'
            && $request['key_id'] === CoreEnvelopeSealer::test()->kid());
    }

    public function test_an_admin_becomes_super(): void
    {
        $this->coreAnswers(['is_admin' => true, 'cms_roles' => []]);

        $this->get($this->handoff(['is_admin' => true]))->assertRedirect(cp_route('index'));

        $user = User::findByEmail(self::EMAIL);
        $this->assertTrue($user->isSuper());
        $this->assertSame([], $user->roles()->keys()->all());
    }

    public function test_core_is_authoritative_over_the_payload(): void
    {
        $this->coreAnswers();

        $this->get($this->handoff(['is_admin' => true]))->assertRedirect();

        $this->assertFalse(User::findByEmail(self::EMAIL)->isSuper());
    }

    public function test_a_demoted_admin_loses_super_but_keeps_hand_assigned_roles(): void
    {
        $this->provisioned(self::EMAIL, roles: ['author'], super: true);
        $this->coreAnswers();

        $this->get($this->handoff())->assertRedirect();

        $user = User::findByEmail(self::EMAIL);
        $this->assertFalse($user->isSuper());
        $this->assertEqualsCanonicalizing(['author', 'editor'], $user->roles()->keys()->all());
    }

    /** @return array<string, array{Closure(): void, bool}> */
    public static function accountsSsoDidNotCreateForThisPerson(): array
    {
        $accounts = [
            'hand-made' => fn () => User::make()->email(self::EMAIL)->password('kept-password')->save(),
            'hand-made super' => fn () => User::make()->email(self::EMAIL)->makeSuper()->save(),
            'hand-made with its own roles' => fn () => User::make()->email(self::EMAIL)->explicitRoles(['author'])->save(),
            'hand-made in a group' => fn () => User::make()->email(self::EMAIL)->set('groups', ['admin'])->save(),
            'bound to another core user' => fn () => User::make()->email(self::EMAIL)->explicitRoles(['author', 'editor'])->set('core_user_id', '12')->save(),
        ];

        $cases = [];

        foreach ($accounts as $name => $account) {
            $cases["{$name}, editor"] = [$account, false];
            $cases["{$name}, admin"] = [$account, true];
        }

        return $cases;
    }

    /** Core lets a person change their own email unverified, so an address alone never proves whose account it is. */
    #[DataProvider('accountsSsoDidNotCreateForThisPerson')]
    public function test_an_account_sso_did_not_create_for_this_person_is_refused(Closure $existing, bool $isAdmin): void
    {
        $existing();
        $before = User::findByEmail(self::EMAIL)->fileData();
        $this->coreAnswers($isAdmin ? ['is_admin' => true, 'cms_roles' => []] : []);

        $this->get($this->handoff(['is_admin' => $isAdmin]))->assertForbidden();

        $this->assertSame($before, User::findByEmail(self::EMAIL)->fileData());
        $this->assertGuest('web');
    }

    public function test_an_sso_account_holds_no_password(): void
    {
        $this->provisioned(self::EMAIL, password: 'left-over');
        $this->coreAnswers();

        $this->get($this->handoff())->assertRedirect(cp_route('index'));

        $this->assertNull(User::findByEmail(self::EMAIL)->password());
    }

    /** @return array<string, array{string}> */
    public static function emailsNamingAPathOutsideTheUsersDirectory(): array
    {
        return [
            'quoted traversal' => ['"../../../x"@example.com'],
            'slash' => ['nested/x@example.com'],
            'no domain' => ['anna'],
        ];
    }

    #[DataProvider('emailsNamingAPathOutsideTheUsersDirectory')]
    public function test_an_email_that_cannot_name_an_account_file_is_refused(string $email): void
    {
        $this->coreAnswers(['email' => $email]);

        $this->get($this->handoff())->assertForbidden();

        $this->assertSame([], User::all()->all());
        $this->assertGuest('web');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function refusedHandoffs(): array
    {
        return [
            'the cms site key as audience' => [['aud' => 'production']],
            'another client' => [['aud' => 'another-client']],
            'no audience' => [['aud' => null]],
            'another purpose' => [['purpose' => 'cms_revoke']],
            'a content purpose' => [['purpose' => 'website.read']],
            'no cms site' => [['site' => null]],
            'another cms site' => [['site' => 'staging']],
            'a cms site that is not a key' => [['site' => ['production']]],
            'expired' => [['expires_at' => time() - 1]],
            'bound to a request' => [['method' => 'GET', 'path' => '/admin-sso', 'query' => []]],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('refusedHandoffs')]
    public function test_a_hand_off_not_for_this_site_is_refused_without_confirming(array $overrides): void
    {
        $this->coreAnswers();

        $this->get($this->handoff($overrides))->assertForbidden();

        $this->assertGuest('web');
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/api/app/cms-sso/'));
    }

    public function test_a_hand_off_sealed_with_another_key_is_refused(): void
    {
        $this->coreAnswers();

        $this->get($this->handoff(sealer: new CoreEnvelopeSealer(str_repeat('k', SODIUM_CRYPTO_SIGN_SEEDBYTES))))->assertForbidden();

        $this->assertGuest('web');
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/api/app/cms-sso/'));
    }

    public function test_a_tampered_signature_is_refused_without_confirming(): void
    {
        $this->coreAnswers();
        $url = (string) preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 128), $this->handoff());

        $this->get($url)->assertForbidden();

        $this->assertGuest('web');
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/api/app/cms-sso/'));
    }

    /** @return array<string, array{string}> */
    public static function missingParts(): array
    {
        return ['payload' => ['payload'], 'signature' => ['signature'], 'key id' => ['key_id']];
    }

    #[DataProvider('missingParts')]
    public function test_a_hand_off_missing_a_part_signs_nobody_in(string $part): void
    {
        $this->coreAnswers();
        parse_str((string) parse_url($this->handoff(), PHP_URL_QUERY), $query);
        unset($query[$part]);

        $this->get('/admin-sso?'.http_build_query($query))->assertRedirect();

        $this->assertGuest('web');
        Http::assertNothingSent();
    }

    public function test_a_replayed_hand_off_is_refused(): void
    {
        $this->coreAnswers();
        $url = $this->handoff();

        $this->get($url)->assertRedirect(cp_route('index'));
        $this->get($url)->assertForbidden();

        Http::assertSentCount(3);
        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), self::VERIFY));
    }

    public function test_core_refusing_the_hand_off_refuses_and_strips_the_granted_role(): void
    {
        $this->provisioned(self::EMAIL, roles: ['editor', 'author']);
        $this->coreAnswers(status: 403);

        $this->get($this->handoff())->assertForbidden();

        $this->assertGuest('web');
        $this->assertSame(['author'], User::findByEmail(self::EMAIL)->roles()->keys()->all());
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>}> */
    public static function confirmFailures(): array
    {
        return [
            'a 403 from the token layer' => [403, ['error' => 'refused']],
            'a 403 with no body' => [403, []],
            'the route not built yet' => [404, []],
            'core failing' => [500, []],
            'a bad gateway' => [502, []],
        ];
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('confirmFailures')]
    public function test_a_confirm_that_is_not_a_refusal_strips_nobody(int $status, array $body): void
    {
        $this->provisioned(self::EMAIL);
        $this->fakeCore(['core.test/api/app/cms-sso/client/verify*' => Http::response($body, $status)]);

        $this->get($this->handoff())->assertStatus(503);

        $this->assertGuest('web');
        $this->assertSame(['editor'], User::findByEmail(self::EMAIL)->roles()->keys()->all());
    }

    public function test_core_out_of_reach_refuses_without_revoking(): void
    {
        $this->provisioned(self::EMAIL);
        $this->fakeCore(['core.test/api/app/cms-sso/client/verify*' => fn () => throw new ConnectionException('Connection refused')]);

        $this->get($this->handoff())->assertStatus(503);

        $this->assertSame(['editor'], User::findByEmail(self::EMAIL)->roles()->keys()->all());
    }

    public function test_a_confirm_about_another_person_than_the_hand_off_names_is_refused(): void
    {
        $this->coreAnswers(['user_id' => 8, 'name' => 'Bo Berg', 'email' => 'bo@example.com', 'is_admin' => true, 'cms_roles' => []]);

        $this->get($this->handoff())->assertForbidden();

        $this->assertGuest('web');
        $this->assertNull(User::findByEmail('bo@example.com'));
    }

    public function test_a_confirm_for_another_purpose_is_refused(): void
    {
        $this->coreAnswers(['purpose' => 'cms_revoke']);

        $this->get($this->handoff())->assertForbidden();

        $this->assertNull(User::findByEmail(self::EMAIL));
    }

    public function test_a_staging_site_sends_its_own_key_and_opens_only_staging_hand_offs(): void
    {
        config(['core-web.cms_sign_in.site' => 'staging']);
        $this->coreAnswers();

        $this->get($this->handoff())->assertForbidden();
        $this->get($this->handoff(['site' => 'staging'], ['next' => $this->cp('collections')]))->assertRedirect($this->cp('collections'));

        $this->assertAuthenticated('web');
        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), self::VERIFY) && $request['site'] === 'staging');
        Http::assertNotSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), self::VERIFY) && $request['site'] !== 'staging');
    }

    public function test_a_grant_with_nothing_mapped_is_refused_and_strips_access(): void
    {
        $this->provisioned(self::EMAIL, roles: [], super: true);
        $this->coreAnswers(['cms_roles' => ['cms_unknown']]);

        $this->get($this->handoff())->assertForbidden();

        $user = User::findByEmail(self::EMAIL);
        $this->assertFalse($user->isSuper());
        $this->assertSame([], $user->roles()->keys()->all());
    }

    public function test_an_unmapped_grant_does_not_provision_anyone(): void
    {
        $this->coreAnswers(['cms_roles' => ['cms_unknown']]);

        $this->get($this->handoff())->assertForbidden();

        $this->assertNull(User::findByEmail(self::EMAIL));
    }

    public function test_a_grant_mapped_to_a_missing_role_is_refused(): void
    {
        config(['core-web.cms_sign_in.roles' => ['cms_editor' => 'renamed']]);
        $this->coreAnswers();

        $this->get($this->handoff())->assertForbidden();

        $this->assertNull(User::findByEmail(self::EMAIL));
    }

    public function test_a_refusal_never_takes_super_from_a_hand_made_account(): void
    {
        User::make()->email(self::EMAIL)->makeSuper()->save();
        $this->coreAnswers(status: 403);

        $this->get($this->handoff())->assertForbidden();

        $this->assertTrue(User::findByEmail(self::EMAIL)->isSuper());
    }

    public function test_a_safe_next_path_is_followed(): void
    {
        $this->coreAnswers();

        $this->get($this->handoff(query: ['next' => $this->cp('collections/articles')]))
            ->assertRedirect($this->cp('collections/articles'));
    }

    /** @return array<string, array{string}> */
    public static function unsafeNextPaths(): array
    {
        return [
            'absolute url' => ['https://evil.test/cp'],
            'protocol-relative' => ['//evil.test/cp'],
            'backslash' => ['/\\evil.test'],
            'relative without slash' => ['cp'],
            'control character' => ["/cp\r\nLocation: https://evil.test"],
        ];
    }

    #[DataProvider('unsafeNextPaths')]
    public function test_an_unsafe_next_is_dropped(string $next): void
    {
        $this->coreAnswers();

        $this->get($this->handoff(query: ['next' => $next]))->assertRedirect(cp_route('index'));
    }

    public function test_the_hand_off_route_is_rate_limited(): void
    {
        $this->coreAnswers();

        for ($i = 0; $i < 10; $i++) {
            $this->get('/admin-sso?payload=x&signature=y&key_id=z')->assertForbidden();
        }

        $this->get('/admin-sso?payload=x&signature=y&key_id=z')->assertTooManyRequests();
    }

    /**
     * A fake Core: its published key, this site's client token, and the confirm call's answer.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function coreAnswers(array $overrides = [], int $status = 200): void
    {
        $this->fakeCore([
            'core.test/api/app/cms-sso/client/verify*' => $status === 200
                ? Http::response([
                    'user_id' => 7,
                    'name' => 'Anna Andersson',
                    'email' => self::EMAIL,
                    'is_admin' => false,
                    'cms_roles' => ['cms_editor'],
                    'purpose' => 'cms_sso',
                    ...$overrides,
                ])
                : Http::response(['error' => $status === 403 ? CmsHandoff::NOT_ADMITTED : 'refused'], $status),
        ]);
    }

    /**
     * A hand-off as Core seals it with its own key for this site's client.
     *
     * @param  array<string, mixed>  $overrides  a null one is left out
     * @param  array<string, string>  $query
     */
    private function handoff(array $overrides = [], array $query = [], ?CoreEnvelopeSealer $sealer = null): string
    {
        return $this->sealedUrl([
            'aud' => (string) config('core-web.client.client_id'),
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
            'nonce' => bin2hex(random_bytes(16)),
            'purpose' => 'cms_sso',
            'site' => 'production',
            'user_id' => 7,
            'name' => 'Anna Andersson',
            'email' => self::EMAIL,
            'is_admin' => false,
            ...$overrides,
        ], $query, $sealer);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, string>  $query
     */
    private function sealedUrl(array $claims, array $query, ?CoreEnvelopeSealer $sealer): string
    {
        $headers = ($sealer ?? CoreEnvelopeSealer::test())->seal(array_filter($claims, fn (mixed $value): bool => $value !== null));

        return '/admin-sso?'.http_build_query([
            'payload' => $headers[CoreEnvelope::PAYLOAD_HEADER],
            'signature' => $headers[CoreEnvelope::SIGNATURE_HEADER],
            'key_id' => $headers[CoreEnvelope::KEY_ID_HEADER],
            ...$query,
        ]);
    }
}
