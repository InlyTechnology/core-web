<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Content\ContentPings;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;

/** POST {core}/webhooks/connectors/{connectorId}: the site tells Core a page changed. */
class ContentPingsTest extends TestCase
{
    use FakesCore;

    protected array $features = ['content'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['core-web.content.connector_id' => '7']);
    }

    public function test_a_ping_names_the_page_with_a_fresh_nonce_and_the_client_token(): void
    {
        $this->fakeCore(['core.test/webhooks/connectors/7' => Http::response(['status' => 'accepted'], 202)]);

        app(ContentPings::class)->send('abc-123', 'saved');
        app(ContentPings::class)->send('abc-123', 'deleted');

        $pings = Http::recorded(fn (Request $request) => str_contains($request->url(), '/webhooks/connectors/'))->map(fn ($pair) => $pair[0])->values();

        $this->assertCount(2, $pings);
        $this->assertSame('https://core.test/webhooks/connectors/7', $pings[0]->url());
        $this->assertSame('POST', $pings[0]->method());
        $this->assertSame(['Bearer token'], $pings[0]->header('Authorization'));
        $this->assertSame(['type', 'id', 'action', 'nonce'], array_keys($pings[0]->data()));
        $this->assertSame(['page', 'abc-123', 'saved'], [$pings[0]['type'], $pings[0]['id'], $pings[0]['action']]);
        $this->assertSame('deleted', $pings[1]['action']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pings[0]['nonce']);
        $this->assertNotSame($pings[0]['nonce'], $pings[1]['nonce']);
    }

    public function test_the_retry_after_an_expired_token_carries_a_new_nonce(): void
    {
        $this->fakeCore(['core.test/webhooks/connectors/7' => Http::sequence()->push('expired', 401)->push(['status' => 'accepted'], 202)]);

        app(ContentPings::class)->send('abc-123', 'saved');

        $nonces = Http::recorded(fn (Request $request) => str_contains($request->url(), '/webhooks/connectors/'))->map(fn ($pair) => $pair[0]['nonce'])->values();

        $this->assertCount(2, $nonces);
        $this->assertNotSame($nonces[0], $nonces[1]);
    }

    public function test_the_connector_id_is_url_encoded(): void
    {
        config(['core-web.content.connector_id' => 'a b']);

        $this->assertSame('/webhooks/connectors/a%20b', app(ContentPings::class)->path());
    }

    public function test_a_refused_ping_throws_for_the_queue_to_retry(): void
    {
        $this->fakeCore(['core.test/webhooks/connectors/7' => Http::response('down', 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[500]');

        app(ContentPings::class)->send('abc-123', 'saved');
    }

    public function test_it_is_off_without_a_connector_or_a_client(): void
    {
        $this->fakeCore();
        $this->assertTrue(app(ContentPings::class)->enabled());

        config(['core-web.content.connector_id' => '']);
        $this->assertFalse(app(ContentPings::class)->enabled());

        config(['core-web.content.connector_id' => '7', 'core-web.client.client_secret' => '']);
        $this->assertFalse(app(ContentPings::class)->enabled());

        $this->expectException(RuntimeException::class);
        app(ContentPings::class)->send('abc-123', 'saved');
    }

    public function test_only_saved_and_deleted_are_sent(): void
    {
        $this->fakeCore();

        $this->expectException(InvalidArgumentException::class);

        app(ContentPings::class)->send('abc-123', 'published');
    }
}
