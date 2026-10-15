<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Content\EnvelopeRefused;
use Inly\CoreWeb\Content\VerifyCoreEnvelope;
use Inly\CoreWeb\Content\WrongEnvironment;
use Inly\CoreWeb\Testing\CoreEnvelopeSealer;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;

/** The `core-web.envelope` middleware and the opener behind it: what a site's content endpoint lets through, and how it refuses. */
class ContentEnvelopeTest extends TestCase
{
    use FakesCore;

    protected array $features = ['content'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Statamic's catch-all would answer the routes this test adds after it.
        $app['config']->set('statamic.routes.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCore();

        $echo = fn (Request $request) => response()->json(['claims' => VerifyCoreEnvelope::claims($request), 'input' => $request->all()]);
        Route::get('api/core/v1/content', $echo)->middleware('core-web.envelope:website.read');
        Route::get('api/core/v1/content/{id}', $echo)->middleware('core-web.envelope:website.read');
        Route::post('api/core/v1/drafts', $echo)->middleware('core-web.envelope:website.draft');
        Route::post('api/core/v1/drafts/{id}/publish', $echo)->middleware('core-web.envelope:website.publish');
    }

    public function test_a_sealed_read_reaches_the_endpoint_with_its_claims(): void
    {
        $response = $this->callSealed('GET', '/api/core/v1/content?limit=100&cursor=abc', 'website.read')
            ->assertOk()
            ->assertJsonPath('claims.aud', 'web')
            ->assertJsonPath('claims.purpose', 'website.read')
            ->assertJsonPath('claims.query', ['cursor' => 'abc', 'limit' => '100']);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_unsealed_call_is_refused_without_a_reason(): void
    {
        $response = $this->getJson('/api/core/v1/content')->assertForbidden()->assertExactJson(['error' => 'envelope_refused']);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_refusal_after_the_signature_verified_names_its_reason(): void
    {
        $this->callSealed('GET', '/api/core/v1/content', 'website.draft')
            ->assertForbidden()
            ->assertExactJson(['error' => 'envelope_refused', 'reason' => 'purpose']);

        $this->callSealed('GET', '/api/core/v1/content', 'website.read', claims: ['purpose' => null])
            ->assertForbidden()
            ->assertJsonPath('reason', 'purpose');

        $this->callSealed('GET', '/api/core/v1/content', 'website.read', claims: ['aud' => 'another-site'])
            ->assertForbidden()
            ->assertJsonPath('reason', 'audience');
    }

    public function test_a_publish_opens_only_a_publish_envelope(): void
    {
        $body = '{"actor":{"core_user_id":"1"}}';
        $headers = $this->coreEnvelopeHeaders('POST', '/api/core/v1/drafts/abc/publish', 'website.draft', $body);

        $this->call('POST', '/api/core/v1/drafts/abc/publish', [], [], [], self::server($headers), $body)
            ->assertForbidden()
            ->assertJsonPath('reason', 'purpose');

        $this->callSealed('POST', '/api/core/v1/drafts/abc/publish', 'website.publish', ['actor' => ['core_user_id' => '1']])->assertOk();
    }

    public function test_the_signature_must_be_core_s_published_key(): void
    {
        $other = new CoreEnvelopeSealer(str_repeat("\x01", 32));

        $this->call('GET', '/api/core/v1/content', [], [], [], self::server($other->requestHeaders('GET', '/api/core/v1/content', 'website.read')))
            ->assertForbidden()
            ->assertExactJson(['error' => 'envelope_refused']);

        $headers = $this->coreEnvelopeHeaders('GET', '/api/core/v1/content', 'website.read');
        unset($headers[CoreEnvelope::KEY_ID_HEADER]);

        $this->call('GET', '/api/core/v1/content', [], [], [], self::server($headers))->assertForbidden()->assertExactJson(['error' => 'envelope_refused']);
    }

    public function test_an_hmac_never_passes_for_core(): void
    {
        $payload = base64_encode(json_encode(['aud' => 'web', 'expires_at' => now()->timestamp + 300, 'nonce' => str_repeat('a', 32), 'purpose' => 'website.read', 'method' => 'GET', 'path' => '/api/core/v1/content', 'query' => (object) []]));
        $headers = [CoreEnvelope::PAYLOAD_HEADER => $payload, CoreEnvelope::SIGNATURE_HEADER => hash_hmac('sha256', $payload, 'secret'), CoreEnvelope::KEY_ID_HEADER => CoreEnvelopeSealer::test()->kid()];

        $this->call('GET', '/api/core/v1/content', [], [], [], self::server($headers))->assertForbidden()->assertExactJson(['error' => 'envelope_refused']);
    }

    public function test_the_query_is_read_as_received_whatever_the_middleware_makes_of_it(): void
    {
        $this->callSealed('GET', '/api/core/v1/content?updated_since=&cursor=%20a%20', 'website.read')
            ->assertOk()
            ->assertJsonPath('claims.query', ['cursor' => ' a ', 'updated_since' => '']);
    }

    public function test_the_path_is_bound_as_sent(): void
    {
        $this->callSealed('GET', '/api/core/v1/content/ab%20c', 'website.read')->assertOk()->assertJsonPath('claims.path', '/api/core/v1/content/ab%20c');
        $this->callSealed('GET', '/api/core/v1/content/page:42', 'website.read')->assertOk();

        $headers = $this->coreEnvelopeHeaders('GET', '/api/core/v1/content/1', 'website.read');
        $this->call('GET', '/api/core/v1/content/2', [], [], [], self::server($headers))->assertForbidden()->assertJsonPath('reason', 'path');
    }

    public function test_a_draft_binds_its_body(): void
    {
        $body = '{"collection":"pages","fields":{"title":"Café"}}';
        $headers = $this->coreEnvelopeHeaders('POST', '/api/core/v1/drafts', 'website.draft', $body);

        $this->call('POST', '/api/core/v1/drafts', [], [], [], self::server($headers), $body.' ')->assertForbidden()->assertJsonPath('reason', 'body');
        $this->call('POST', '/api/core/v1/drafts', [], [], [], self::server($headers), $body)->assertOk()->assertJsonPath('input.fields.title', 'Café');
    }

    public function test_an_envelope_opens_once(): void
    {
        $headers = $this->coreEnvelopeHeaders('GET', '/api/core/v1/content', 'website.read');

        $this->call('GET', '/api/core/v1/content', [], [], [], self::server($headers))->assertOk();
        $this->call('GET', '/api/core/v1/content', [], [], [], self::server($headers))->assertForbidden()->assertJsonPath('reason', 'replayed');
    }

    public function test_a_draft_from_another_environment_s_core_is_refused(): void
    {
        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'pages'], ['environment' => 'staging'])
            ->assertForbidden()
            ->assertJsonPath('error', 'wrong_environment');

        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'pages'], ['environment' => null])
            ->assertForbidden()
            ->assertJsonPath('error', 'wrong_environment');

        // A read needs no environment of its own: Core's other environments may read.
        $this->callSealed('GET', '/api/core/v1/content', 'website.read', claims: ['environment' => 'staging'])->assertOk();
    }

    public function test_the_site_s_environment_can_be_named(): void
    {
        config(['core-web.content.environment' => 'production']);

        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'pages'])->assertOk()->assertJsonPath('claims.environment', 'production');
        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'pages'], ['environment' => 'testing'])->assertForbidden();
    }

    public function test_a_site_without_a_client_id_opens_nothing(): void
    {
        $headers = $this->coreEnvelopeHeaders('GET', '/api/core/v1/content', 'website.read', claims: ['aud' => '']);
        config(['core-web.client.client_id' => '']);

        $this->call('GET', '/api/core/v1/content', [], [], [], self::server($headers))->assertForbidden()->assertExactJson(['error' => 'envelope_refused']);
    }

    public function test_an_envelope_bound_to_no_request_opens_for_its_purpose(): void
    {
        $sealer = CoreEnvelopeSealer::test();
        $claims = ['aud' => 'web', 'expires_at' => now()->timestamp + 300, 'nonce' => bin2hex(random_bytes(16)), 'purpose' => 'cms.sso', 'email' => 'editor@example.com'];
        $headers = $sealer->seal($claims);
        $envelope = CoreEnvelope::fromConfig();

        $opened = $envelope->open($headers[CoreEnvelope::PAYLOAD_HEADER], $headers[CoreEnvelope::SIGNATURE_HEADER], $headers[CoreEnvelope::KEY_ID_HEADER], 'cms.sso');
        $this->assertSame('editor@example.com', $opened['email']);

        $this->assertRefused('replayed', fn () => $envelope->open($headers[CoreEnvelope::PAYLOAD_HEADER], $headers[CoreEnvelope::SIGNATURE_HEADER], $headers[CoreEnvelope::KEY_ID_HEADER], 'cms.sso'));

        $other = $sealer->seal([...$claims, 'nonce' => bin2hex(random_bytes(16))]);
        $this->assertRefused('purpose', fn () => $envelope->open($other[CoreEnvelope::PAYLOAD_HEADER], $other[CoreEnvelope::SIGNATURE_HEADER], $other[CoreEnvelope::KEY_ID_HEADER], 'website.read'));
        $this->assertRefused('audience', fn () => $envelope->open($other[CoreEnvelope::PAYLOAD_HEADER], $other[CoreEnvelope::SIGNATURE_HEADER], $other[CoreEnvelope::KEY_ID_HEADER], 'cms.sso', 'another-site'));
        $this->assertRefused('missing', fn () => $envelope->open(null, $other[CoreEnvelope::SIGNATURE_HEADER], $other[CoreEnvelope::KEY_ID_HEADER], 'cms.sso'));
        $this->assertRefused('signature', fn () => $envelope->open($other[CoreEnvelope::PAYLOAD_HEADER], $other[CoreEnvelope::SIGNATURE_HEADER], null, 'cms.sso'));
    }

    public function test_an_envelope_sealed_to_a_request_does_not_open_without_it(): void
    {
        $envelope = CoreEnvelope::fromConfig();

        foreach (['method' => 'method', 'path' => 'path', 'query' => 'query', 'body_sha256' => 'body'] as $claim => $reason) {
            $headers = $this->coreEnvelopeHeaders('POST', '/api/core/v1/drafts', 'website.draft', '{}', array_fill_keys(array_diff(['method', 'path', 'query', 'body_sha256'], [$claim]), null));

            $this->assertRefused($reason, fn () => $envelope->open($headers[CoreEnvelope::PAYLOAD_HEADER], $headers[CoreEnvelope::SIGNATURE_HEADER], $headers[CoreEnvelope::KEY_ID_HEADER], 'website.draft'));
        }

        // Refused before the nonce is spent, so the same envelope still opens with its request.
        $headers = $this->coreEnvelopeHeaders('GET', '/api/core/v1/content', 'website.read');
        $this->assertRefused('method', fn () => $envelope->open($headers[CoreEnvelope::PAYLOAD_HEADER], $headers[CoreEnvelope::SIGNATURE_HEADER], $headers[CoreEnvelope::KEY_ID_HEADER], 'website.read'));
        $this->call('GET', '/api/core/v1/content', [], [], [], self::server($headers))->assertOk();
    }

    public function test_a_write_bound_to_no_request_checks_the_environment_too(): void
    {
        $headers = CoreEnvelopeSealer::test()->seal(['aud' => 'web', 'expires_at' => now()->timestamp + 300, 'nonce' => bin2hex(random_bytes(16)), 'purpose' => 'website.draft', 'environment' => 'staging']);

        $this->expectException(WrongEnvironment::class);

        CoreEnvelope::fromConfig()->open($headers[CoreEnvelope::PAYLOAD_HEADER], $headers[CoreEnvelope::SIGNATURE_HEADER], $headers[CoreEnvelope::KEY_ID_HEADER], 'website.draft');
    }

    public function test_the_key_document_is_read_once_while_cached(): void
    {
        $this->callSealed('GET', '/api/core/v1/content', 'website.read')->assertOk();
        $this->callSealed('GET', '/api/core/v1/content', 'website.read')->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://core.test/.well-known/core-signing-keys');
    }

    private function assertRefused(string $reason, callable $open): void
    {
        try {
            $open();
        } catch (EnvelopeRefused $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }

        $this->fail("The envelope opened; expected [{$reason}].");
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function server(array $headers): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
