<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\CmsSignIn\CmsUsers;
use Inly\CoreWeb\CmsSignIn\ForgetCmsUserJob;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Ping\PingSignature;
use Inly\CoreWeb\Testing\CoreEnvelopeSealer;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\CmsSignInTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\User;

/**
 * Core seals a `cms_revoke` notice with its own key when a person is deleted
 * or loses CMS access; the CP account Core provisioned for them goes with it,
 * and nothing made by hand does.
 */
class CmsUserOffboardingTest extends CmsSignInTestCase
{
    use FakesCore;

    private const REVOKE = '/api/core/cms-users/revoke';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCore();
    }

    /** @param  array<string, mixed>|string  $body */
    private function revoke(array|string $body = [], string $purpose = 'cms_revoke', array $claims = [])
    {
        $body = is_array($body) ? ['site' => 'production', 'user_id' => 7, 'email' => 'anna@example.com', ...$body] : $body;

        return $this->callSealed('POST', self::REVOKE, $purpose, $body, $claims);
    }

    public function test_the_route_is_named(): void
    {
        $this->assertSame('http://localhost'.self::REVOKE, route('core-web.cms-users.revoke'));
    }

    public function test_the_notice_dispatches_the_job_with_the_id_and_email(): void
    {
        Queue::fake();

        $this->revoke()->assertStatus(202)->assertJson(['status' => 'accepted']);

        Queue::assertPushed(ForgetCmsUserJob::class, fn (ForgetCmsUserJob $job): bool => $job->id === '7'
            && $job->email === 'anna@example.com');
    }

    public function test_the_notice_deletes_only_the_provisioned_account(): void
    {
        $this->provisioned('Anna@Example.com');
        $this->provisioned('other@example.com', '8');

        $this->revoke()->assertStatus(202);

        $this->assertNull(User::findByEmail('Anna@Example.com'));
        $this->assertNotNull(User::findByEmail('other@example.com'));
    }

    public function test_the_notice_finds_an_account_whose_email_was_changed_in_the_cp(): void
    {
        $this->provisioned('renamed@example.com');

        $this->revoke()->assertStatus(202);

        $this->assertNull(User::findByEmail('renamed@example.com'));
    }

    public function test_a_hand_made_account_with_the_same_email_survives(): void
    {
        User::make()->email('anna@example.com')->makeSuper()->save();

        $this->revoke()->assertStatus(202);

        $this->assertTrue(User::findByEmail('anna@example.com')->isSuper());
    }

    public function test_a_notice_without_an_email_deletes_nobody(): void
    {
        $this->provisioned('anna@example.com');

        $this->revoke(['email' => null])->assertStatus(422);

        $this->assertNotNull(User::findByEmail('anna@example.com'));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function refusedNotices(): array
    {
        return [
            'a content purpose' => [CoreEnvelope::READ, []],
            'a content ping' => [CoreEnvelope::OBJECTS, []],
            'a sign-in hand-off' => ['cms_sso', []],
            'another client' => ['cms_revoke', ['aud' => 'another-client']],
            'the cms site key as audience' => ['cms_revoke', ['aud' => 'production']],
            'another path' => ['cms_revoke', ['path' => '/api/core/v1/content']],
            'another body' => ['cms_revoke', ['body_sha256' => hash('sha256', '{"site":"production","user_id":8,"email":"other@example.com"}')]],
            'expired' => ['cms_revoke', ['expires_at' => time() - 1]],
        ];
    }

    /** @param  array<string, mixed>  $claims */
    #[DataProvider('refusedNotices')]
    public function test_a_notice_that_is_not_a_revocation_for_this_site_deletes_nobody(string $purpose, array $claims): void
    {
        $this->provisioned('anna@example.com');
        Queue::fake();

        $this->revoke([], $purpose, $claims)->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertNotNull(User::findByEmail('anna@example.com'));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function noticeBodies(): array
    {
        return [
            'for another cms site' => ['{"site":"staging","user_id":7,"email":"anna@example.com"}', 403],
            'naming no cms site' => ['{"user_id":7,"email":"anna@example.com"}', 403],
            'a json list' => ['[{"site":"production","user_id":7,"email":"anna@example.com"}]', 422],
            'no json object' => ['user_id=7&email=anna%40example.com', 422],
        ];
    }

    #[DataProvider('noticeBodies')]
    public function test_a_notice_whose_body_is_not_for_this_site_deletes_nobody(string $body, int $status): void
    {
        Queue::fake();

        $this->revoke($body)->assertStatus($status);

        Queue::assertNothingPushed();
    }

    public function test_a_staging_site_takes_notices_addressed_to_it(): void
    {
        config(['core-web.cms_sign_in.site' => 'staging']);
        $this->provisioned('anna@example.com');

        $this->revoke()->assertForbidden();
        $this->revoke(['site' => 'staging'])->assertStatus(202);

        $this->assertNull(User::findByEmail('anna@example.com'));
    }

    public function test_a_notice_is_spent_on_first_use(): void
    {
        Queue::fake();
        [$server, $body] = $this->sealedServer('POST', self::REVOKE, 'cms_revoke', ['site' => 'production', 'user_id' => '7', 'email' => 'anna@example.com']);

        $this->call('POST', self::REVOKE, [], [], [], $server, $body)->assertStatus(202);
        $this->call('POST', self::REVOKE, [], [], [], $server, $body)->assertForbidden();

        Queue::assertPushed(ForgetCmsUserJob::class, 1);
    }

    public function test_a_notice_sealed_with_another_key_deletes_nobody(): void
    {
        Queue::fake();
        $body = '{"site":"production","user_id":"7","email":"anna@example.com"}';
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        $headers = (new CoreEnvelopeSealer(str_repeat('k', SODIUM_CRYPTO_SIGN_SEEDBYTES)))
            ->requestHeaders('POST', self::REVOKE, 'cms_revoke', $body);

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->call('POST', self::REVOKE, [], [], [], $server, $body)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_ping_signed_with_the_content_sync_secret_cannot_delete_an_account(): void
    {
        $this->withPingSecret();
        $this->provisioned('anna@example.com');

        $this->postJson(self::REVOKE, app(PingSignature::class)->sign([
            'purpose' => 'cms_revoke', 'site' => 'production', 'user_id' => '7', 'email' => 'anna@example.com',
        ]))->assertForbidden();

        $this->assertNotNull(User::findByEmail('anna@example.com'));
    }

    public function test_the_job_deletes_only_the_account_bound_to_the_core_user(): void
    {
        $this->provisioned('anna@example.com', '7');
        $this->provisioned('renamed@example.com', '9');
        User::make()->email('hand@example.com')->makeSuper()->save();

        // Core lets people change their email unverified, so a notice naming Anna's address for user 9 takes only user 9's account.
        (new ForgetCmsUserJob('9', 'anna@example.com'))->handle(app(CmsUsers::class));
        (new ForgetCmsUserJob('10', 'hand@example.com'))->handle(app(CmsUsers::class));

        $this->assertNotNull(User::findByEmail('anna@example.com'));
        $this->assertNull(User::findByEmail('renamed@example.com'));
        $this->assertNotNull(User::findByEmail('hand@example.com'));
    }
}
