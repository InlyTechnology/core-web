<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use RuntimeException;

/** The Objects API client: a cached client_credentials token, JSON:API reads, paging and its truncation guard. */
class CoreClientTest extends TestCase
{
    use FakesCore;

    protected function setUp(): void
    {
        parent::setUp();

        // The client retries a failed read twice, half a second apart.
        Sleep::fake();
    }

    private function client(): CoreClient
    {
        return app(CoreClient::class);
    }

    private function tokenRequests(): int
    {
        return count(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/oauth/token')));
    }

    public function test_it_is_built_from_config_each_time_it_is_resolved(): void
    {
        $this->assertFalse($this->client()->configured());

        config(['core-web.client.base_url' => 'https://core.test/', 'core-web.client.client_id' => 'web', 'core-web.client.client_secret' => 'secret']);

        $this->assertTrue($this->client()->configured());
        $this->assertSame('https://core.test', $this->client()->baseUrl());
    }

    public function test_the_token_is_fetched_once_and_cached(): void
    {
        $this->fakeCore(['core.test/api/v1/objects/facility/1*' => Http::response(['data' => ['id' => '1']])]);

        $this->assertSame(['data' => ['id' => '1']], $this->client()->object('facility', 1));
        $this->assertSame(['data' => ['id' => '1']], $this->client()->object('facility', 1));

        $this->assertSame(1, $this->tokenRequests());
        $this->assertSame('token', Cache::get('core_web_access_token'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth/token')
            && $request['grant_type'] === 'client_credentials' && $request['client_id'] === 'web' && $request['client_secret'] === 'secret');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/facility/1')
            && $request->hasHeader('Authorization', 'Bearer token')
            && $request->hasHeader('Accept', 'application/vnd.api+json'));
    }

    public function test_the_token_cache_key_is_configurable(): void
    {
        config(['core-web.client.token_cache_key' => 'site_core_token']);
        $this->fakeCore(['core.test/api/v1/objects/facility/1*' => Http::response(['data' => []])]);

        $this->client()->object('facility', 1);

        $this->assertSame('token', Cache::get('site_core_token'));
    }

    public function test_a_401_drops_the_cached_token_and_retries_once(): void
    {
        $this->fakeCore(['core.test/api/v1/objects/facility/1*' => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer token')
            ? Http::response(['data' => ['id' => '1']])
            : Http::response(['message' => 'expired'], 401)]);
        Cache::put('core_web_access_token', 'stale', 3600);

        $this->assertSame(['data' => ['id' => '1']], $this->client()->object('facility', 1));

        $this->assertSame(1, $this->tokenRequests());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/facility/1') && $request->hasHeader('Authorization', 'Bearer stale'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/facility/1') && $request->hasHeader('Authorization', 'Bearer token'));
    }

    public function test_a_404_is_null_and_any_other_failure_throws(): void
    {
        $this->fakeCore(['core.test/api/v1/objects/facility/2*' => Http::response('boom', 500)]);

        $this->assertNull($this->client()->object('facility', 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Core API error [500] on GET /api/v1/objects/facility/2');
        $this->client()->object('facility', 2);
    }

    public function test_a_failed_token_request_throws(): void
    {
        $this->fakeCore();
        Http::swap(new Factory);
        Http::fake(['core.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to obtain a Core access token');
        $this->client()->object('facility', 1);
    }

    public function test_objects_walks_every_page_and_reports_the_total(): void
    {
        $this->fakeCore(['core.test/api/v1/objects/facility*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) $query['page']['number'];

            return Http::response([
                'data' => [['type' => 'facility', 'id' => (string) $page]],
                'included' => [['type' => 'address', 'id' => "a{$page}"]],
                'meta' => ['current_page' => $page, 'last_page' => 3, 'total' => 3],
            ]);
        }]);

        $listing = $this->client()->objects('facility', 1, ['filter[unit]' => 'cars']);
        $objects = iterator_to_array($listing, false);

        $this->assertSame(['1', '2', '3'], array_column(array_column($objects, 'data'), 'id'));
        $this->assertSame([['type' => 'address', 'id' => 'a2']], $objects[1]['included']);
        $this->assertSame(['total' => 3, 'truncated' => false], $listing->getReturn());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'page%5Bnumber%5D=3')
            && str_contains($request->url(), 'page%5Bsize%5D=1')
            && str_contains($request->url(), 'filter%5Bunit%5D=cars'));
    }

    public function test_object_reads_ask_for_compact_row_meta_unless_the_query_names_its_own(): void
    {
        $this->fakeCore([
            'core.test/api/v1/objects/facility/*' => Http::response(['data' => ['id' => '1']]),
            'core.test/api/v1/objects/facility*' => self::coreList([['type' => 'facility', 'id' => '1']]),
        ]);

        $this->client()->object('facility', 1);
        iterator_to_array($this->client()->objects('facility'), false);
        $this->client()->object('facility', 2, ['meta' => 'full']);
        iterator_to_array($this->client()->objects('facility', 50, ['meta' => 'full']), false);
        $this->client()->get('/api/v1/objects/facility');

        $metas = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), '/api/v1/objects/'))
            ->map(fn (Request $request) => self::requestedRowMeta($request))
            ->values()
            ->all();

        $this->assertSame(['compact', 'compact', 'full', 'full', 'full'], $metas, 'a plain get() is left as it is asked');
    }

    public function test_an_answer_without_last_page_is_one_page_that_may_be_truncated(): void
    {
        $this->fakeCore([
            'core.test/api/v1/objects/full*' => Http::response(['data' => [['id' => '1'], ['id' => '2']]]),
            'core.test/api/v1/objects/short*' => Http::response(['data' => [['id' => '1']], 'meta' => ['total' => 5]]),
            'core.test/api/v1/objects/whole*' => Http::response(['data' => [['id' => '1']], 'meta' => ['total' => 1]]),
        ]);

        foreach (['full' => [null, true], 'short' => [5, true], 'whole' => [1, false]] as $type => [$total, $truncated]) {
            $listing = $this->client()->objects($type, 2);
            iterator_to_array($listing, false);

            $this->assertSame(['total' => $total, 'truncated' => $truncated], $listing->getReturn(), $type);
        }
    }

    public function test_post_sends_json_with_the_token(): void
    {
        $this->fakeCore(['core.test/api/v1/chat*' => Http::response(['id' => 'c1'], 201)]);

        $this->assertSame(['id' => 'c1'], $this->client()->post('/api/v1/chat', ['message' => 'Hej']));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/chat')
            && $request->method() === 'POST' && $request->isJson() && $request['message'] === 'Hej'
            && $request->hasHeader('Authorization', 'Bearer token'));
    }

    public function test_a_ca_bundle_is_trusted_when_configured(): void
    {
        $this->assertArrayNotHasKey('verify', CoreClient::request()->getOptions());

        config(['core-web.client.ca_bundle' => '/tmp/ca.pem']);

        $this->assertSame('/tmp/ca.pem', CoreClient::request()->getOptions()['verify']);
    }
}
