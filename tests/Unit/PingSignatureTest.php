<?php

namespace Inly\CoreWeb\Tests\Unit;

use Illuminate\Support\Carbon;
use Inly\CoreWeb\Ping\PingSignature;
use Inly\CoreWeb\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The envelope Core signs its pings with. The vector below is shared with the
 * sender in Inly Core: both sides must produce and
 * accept exactly these bytes.
 */
class PingSignatureTest extends TestCase
{
    public const SECRET = 'core-web-test-vector-secret';

    public const DATA = [
        'type' => 'employee',
        'id' => '42',
        'public_id' => '0b6f3c9e-6d1a-4c5e-9f2b-3a7d8e1c4b50',
        'action' => 'saved',
        'expires_at' => 1893456000,
        'nonce' => '00112233445566778899aabbccddeeff',
    ];

    public const PAYLOAD = 'eyJ0eXBlIjoiZW1wbG95ZWUiLCJpZCI6IjQyIiwicHVibGljX2lkIjoiMGI2ZjNjOWUtNmQxYS00YzVlLTlmMmItM2E3ZDhlMWM0YjUwIiwiYWN0aW9uIjoic2F2ZWQiLCJleHBpcmVzX2F0IjoxODkzNDU2MDAwLCJub25jZSI6IjAwMTEyMjMzNDQ1NTY2Nzc4ODk5YWFiYmNjZGRlZWZmIn0=';

    public const SIGNATURE = '81df17fceeac581bb3163d4d0c7b80a7f547533b823db233859e74caaf0674b6';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2029-12-31 23:00:00');
    }

    public function test_signing_the_vector_gives_its_payload_and_signature(): void
    {
        $this->assertSame(
            ['payload' => self::PAYLOAD, 'signature' => self::SIGNATURE],
            (new PingSignature(self::SECRET))->sign(self::DATA),
        );
    }

    public function test_the_vector_verifies_to_its_data(): void
    {
        $this->assertSame(self::DATA, (new PingSignature(self::SECRET))->verify(self::PAYLOAD, self::SIGNATURE));
    }

    public function test_the_secret_comes_from_config(): void
    {
        config(['core-web.ping.secret' => self::SECRET]);

        $this->assertSame(self::DATA, app(PingSignature::class)->verify(self::PAYLOAD, self::SIGNATURE));
    }

    public function test_a_wrong_signature_is_forbidden(): void
    {
        $this->assertForbidden(fn () => (new PingSignature(self::SECRET))->verify(self::PAYLOAD, str_repeat('0', 64)));
        $this->assertForbidden(fn () => (new PingSignature('another-secret'))->verify(self::PAYLOAD, self::SIGNATURE));
    }

    public function test_an_empty_secret_rejects_everything(): void
    {
        config(['core-web.ping.secret' => '', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $empty = new PingSignature('');

        $this->assertForbidden(fn () => app(PingSignature::class)->verify(self::PAYLOAD, self::SIGNATURE));
        $this->assertForbidden(fn () => $empty->verify(self::PAYLOAD, $empty->signature(self::PAYLOAD)));
    }

    public function test_an_expired_envelope_is_forbidden(): void
    {
        Carbon::setTestNow('2030-01-01 00:00:01');

        $this->assertForbidden(fn () => (new PingSignature(self::SECRET))->verify(self::PAYLOAD, self::SIGNATURE));
    }

    public function test_a_replayed_envelope_is_forbidden(): void
    {
        $signature = new PingSignature(self::SECRET);
        $signature->verify(self::PAYLOAD, self::SIGNATURE);

        $this->assertForbidden(fn () => $signature->verify(self::PAYLOAD, self::SIGNATURE));
    }

    public function test_an_envelope_with_a_malformed_nonce_or_a_far_expiry_is_forbidden(): void
    {
        $signature = new PingSignature(self::SECRET);
        $signed = fn (array $data): array => array_values($signature->sign($data));

        $this->assertForbidden(fn () => $signature->verify(...$signed([...self::DATA, 'nonce' => 'reused'])));
        $this->assertForbidden(fn () => $signature->verify(...$signed([...self::DATA, 'expires_at' => now()->addSeconds(PingSignature::MAX_TTL_SECONDS + PingSignature::SKEW_SECONDS + 1)->getTimestamp()])));
    }

    public function test_a_payload_that_is_not_json_is_forbidden(): void
    {
        $signature = new PingSignature(self::SECRET);
        $payload = base64_encode('not json');

        $this->assertForbidden(fn () => $signature->verify($payload, $signature->signature($payload)));
    }

    private function assertForbidden(\Closure $verify): void
    {
        try {
            $verify();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());

            return;
        }

        $this->fail('Expected a 403.');
    }
}
