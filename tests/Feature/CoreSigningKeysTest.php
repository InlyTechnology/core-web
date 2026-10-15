<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Content\CoreSigningKeys;
use Inly\CoreWeb\Testing\CoreEnvelopeSealer;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;

/** The key document at {core}/.well-known/core-signing-keys, as the opener reads it. */
class CoreSigningKeysTest extends TestCase
{
    use FakesCore;

    protected array $features = ['content'];

    private function keyFetches(): int
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), CoreSigningKeys::PATH))->count();
    }

    public function test_the_published_key_is_found_and_cached(): void
    {
        $this->fakeCore();
        $sealer = CoreEnvelopeSealer::test();

        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($sealer->kid()));
        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($sealer->kid()));
        $this->assertSame(1, $this->keyFetches());
    }

    public function test_an_unknown_key_id_refetches_at_most_once_a_minute(): void
    {
        $this->fakeCore();

        $this->assertNull(app(CoreSigningKeys::class)->publicKey('0000000000000000'));
        $this->assertNull(app(CoreSigningKeys::class)->publicKey('1111111111111111'));
        $this->assertSame(1, $this->keyFetches());

        $this->travel(61)->seconds();

        $this->assertNull(app(CoreSigningKeys::class)->publicKey('0000000000000000'));
        $this->assertSame(2, $this->keyFetches());
    }

    public function test_a_fetched_document_replaces_the_cached_one(): void
    {
        $old = new CoreEnvelopeSealer(str_repeat("\1", 32));
        $new = CoreEnvelopeSealer::test();

        $this->fakeCore(['core.test'.CoreSigningKeys::PATH => Http::sequence()->push($old->document())->push($new->document())]);

        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($old->kid()));

        $this->travel(61)->seconds();

        // Core rotated: the new key is fetched and the retired one stops opening anything.
        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($new->kid()));
        $this->assertNull(app(CoreSigningKeys::class)->publicKey($old->kid()));
    }

    public function test_the_cached_document_expires_after_five_minutes(): void
    {
        $this->fakeCore();
        $kid = CoreEnvelopeSealer::test()->kid();

        app(CoreSigningKeys::class)->publicKey($kid);
        $this->travel(CoreSigningKeys::TTL_SECONDS + 1)->seconds();
        app(CoreSigningKeys::class)->publicKey($kid);

        $this->assertSame(2, $this->keyFetches());
    }

    public function test_a_failed_fetch_keeps_the_cached_copy(): void
    {
        $sealer = CoreEnvelopeSealer::test();
        $this->fakeCore(['core.test'.CoreSigningKeys::PATH => Http::sequence()->push($sealer->document())->push('down', 503)]);

        app(CoreSigningKeys::class)->publicKey($sealer->kid());
        $this->travel(61)->seconds();

        $this->assertNull(app(CoreSigningKeys::class)->publicKey('0000000000000000'));
        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($sealer->kid()));
    }

    public function test_a_stale_copy_answers_while_another_call_fetches(): void
    {
        $this->fakeCore();
        $kid = CoreEnvelopeSealer::test()->kid();

        app(CoreSigningKeys::class)->publicKey($kid);
        $this->travel(CoreSigningKeys::TTL_SECONDS + 1)->seconds();
        // Another request holds the minute's fetch, as it does while Core answers slowly.
        Cache::store(config('core-web.content.store'))->put('core_web_signing_keys:fetched', true, CoreSigningKeys::REFETCH_SECONDS);

        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($kid));
        $this->assertNull(app(CoreSigningKeys::class)->publicKey('0000000000000000'));
        $this->assertSame(1, $this->keyFetches());
    }

    public function test_a_failed_refresh_keeps_the_stale_copy_until_a_fetch_succeeds(): void
    {
        $sealer = CoreEnvelopeSealer::test();
        $this->fakeCore(['core.test'.CoreSigningKeys::PATH => Http::sequence()->push($sealer->document())->push('down', 503)->push('down', 503)->push($sealer->document())]);

        app(CoreSigningKeys::class)->publicKey($sealer->kid());
        $this->travel(CoreSigningKeys::TTL_SECONDS + 1)->seconds();

        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($sealer->kid()));
        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($sealer->kid()));
        $this->assertSame(2, $this->keyFetches());

        $this->travel(CoreSigningKeys::REFETCH_SECONDS + 1)->seconds();

        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($sealer->kid()));
        $this->assertSame(3, $this->keyFetches());

        $this->travel(CoreSigningKeys::REFETCH_SECONDS + 1)->seconds();

        // This fetch succeeds, so the copy is fresh again and the next call reads nothing.
        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($sealer->kid()));
        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($sealer->kid()));
        $this->assertSame(4, $this->keyFetches());
    }

    public function test_with_no_copy_a_failed_fetch_refuses(): void
    {
        $this->fakeCore(['core.test'.CoreSigningKeys::PATH => Http::response('down', 503)]);

        $this->assertNull(app(CoreSigningKeys::class)->publicKey(CoreEnvelopeSealer::test()->kid()));
    }

    public function test_a_redirect_is_not_followed(): void
    {
        $this->fakeCore([
            'core.test'.CoreSigningKeys::PATH => Http::response('', 302, ['Location' => 'https://elsewhere.test/keys']),
            'elsewhere.test/*' => Http::response(CoreEnvelopeSealer::test()->document()),
        ]);

        $this->assertNull(app(CoreSigningKeys::class)->publicKey(CoreEnvelopeSealer::test()->kid()));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'elsewhere.test'));
    }

    public function test_a_key_filed_under_another_id_is_refiled_under_its_own(): void
    {
        $sealer = CoreEnvelopeSealer::test();
        $document = $sealer->document();
        $document['keys'][0]['kid'] = 'ffffffffffffffff';
        $document['keys'][] = ['kid' => 'x', 'alg' => 'HS256', 'public_key' => base64_encode(str_repeat('a', 32))];
        $document['keys'][] = ['kid' => 'y', 'alg' => 'Ed25519', 'public_key' => base64_encode('short')];

        $this->assertSame([$sealer->kid()], array_keys(CoreSigningKeys::parse($document)));
    }

    public function test_plain_http_is_read_only_in_local_and_testing(): void
    {
        $this->fakeCore();
        config(['core-web.client.base_url' => 'http://core.test']);
        $kid = CoreEnvelopeSealer::test()->kid();

        $this->assertNotNull(app(CoreSigningKeys::class)->publicKey($kid));

        app(CoreSigningKeys::class)->forget();
        $this->app['env'] = 'production';

        $this->assertNull(app(CoreSigningKeys::class)->publicKey($kid));
        $this->assertSame(1, $this->keyFetches());
    }

    public function test_no_core_means_no_keys(): void
    {
        Http::fake();
        config(['core-web.client.base_url' => '']);

        $this->assertNull(app(CoreSigningKeys::class)->publicKey(CoreEnvelopeSealer::test()->kid()));
        Http::assertNothingSent();
    }
}
