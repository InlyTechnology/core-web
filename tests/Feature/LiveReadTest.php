<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Live\CoreLive;
use Inly\CoreWeb\Live\LiveRead;
use Inly\CoreWeb\Live\LiveRequest;
use Inly\CoreWeb\Live\LiveResult;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use InvalidArgumentException;

/** Named live reads: validated input, one call to Core on the site's token, Core's answer mapped for the page. */
class LiveReadTest extends TestCase
{
    use FakesCore;

    protected array $features = ['live'];

    private const URL = '/!/core-web/live/quote';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.live.reads', ['quote' => QuoteRead::class, 'cached' => CachedRead::class]);
    }

    private function fakeQuote(mixed $answer = null): void
    {
        $this->fakeCore([
            'core.test/api/app/items/*/quote' => $answer ?? fn (HttpRequest $request) => Http::response([
                'data' => ['monthly' => (int) $request['months'] * 10, 'internal' => 'kept back'],
            ]),
        ]);
    }

    public function test_the_page_gets_what_the_read_presents(): void
    {
        $this->fakeQuote();

        $this->getJson(self::URL.'?item=a1&months=12&injected=1')
            ->assertOk()
            ->assertExactJson(['data' => ['monthly' => 120]])
            ->assertHeader('Cache-Control', 'no-store, private');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://core.test/api/app/items/a1/quote'
            && $request->method() === 'POST'
            && $request->data() === ['months' => 12]
            && $request->hasHeader('Authorization', 'Bearer token'));
    }

    public function test_input_the_rules_refuse_never_reaches_core(): void
    {
        $this->fakeQuote();

        $this->getJson(self::URL.'?item=a1&months=nope')
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid')
            ->assertJsonStructure(['errors' => ['months']]);

        Http::assertNothingSent();
    }

    public function test_core_s_refusal_is_passed_on_per_field(): void
    {
        $this->fakeQuote(Http::response(['message' => 'Invalid', 'errors' => ['months' => ['Max 84 months.']]], 422));

        $this->getJson(self::URL.'?item=a1&months=120')
            ->assertStatus(422)
            ->assertExactJson(['error' => 'invalid', 'errors' => ['months' => ['Max 84 months.']]]);
    }

    public function test_a_record_core_does_not_know_is_not_found(): void
    {
        $this->fakeQuote(Http::response(['message' => 'Not found'], 404));

        $this->getJson(self::URL.'?item=a1&months=12')->assertNotFound()->assertExactJson(['error' => 'not_found']);
    }

    public function test_a_read_with_nothing_to_ask_does_not_call_core(): void
    {
        $this->fakeQuote();

        $this->getJson(self::URL.'?item=none&months=12')->assertNotFound()->assertExactJson(['error' => 'not_found']);

        Http::assertNothingSent();
    }

    public function test_core_failing_is_unavailable(): void
    {
        $this->fakeQuote(Http::response('Server error', 500));

        $this->getJson(self::URL.'?item=a1&months=12')->assertStatus(503)->assertExactJson(['error' => 'unavailable']);
    }

    public function test_core_unreachable_is_unavailable(): void
    {
        $this->fakeQuote(fn () => throw new ConnectionException('timed out'));

        $this->getJson(self::URL.'?item=a1&months=12')->assertStatus(503);
    }

    public function test_a_site_without_core_credentials_is_unavailable(): void
    {
        $this->getJson(self::URL.'?item=a1&months=12')->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_an_unnamed_read_is_a_json_404(): void
    {
        $this->fakeQuote();

        $this->get('/!/core-web/live/other')->assertNotFound()->assertExactJson(['error' => 'not_found']);
    }

    public function test_a_kept_answer_tells_the_browser_what_is_left_of_its_ttl(): void
    {
        $this->fakeQuote();

        $this->getJson('/!/core-web/live/cached?item=a1&months=12')->assertHeader('Cache-Control', 'max-age=60, private');
        $this->travel(45)->seconds();
        $this->getJson('/!/core-web/live/cached?item=a1&months=12')->assertHeader('Cache-Control', 'max-age=15, private');
    }

    public function test_an_answer_the_read_will_not_keep_is_kept_nowhere(): void
    {
        $this->fakeCore(['core.test/api/app/items/*/quote' => Http::response(['data' => ['monthly' => 99, 'fallback' => true]])]);

        $this->getJson('/!/core-web/live/cached?item=a1&months=12')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/!/core-web/live/cached?item=a1&months=12')->assertOk();

        Http::assertSentCount(3); // the token, then both calls
    }

    public function test_a_cache_that_fails_still_answers(): void
    {
        $this->fakeQuote();
        config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'nowhere'], 'core-web.live.store' => 'broken']);

        $this->getJson('/!/core-web/live/cached?item=a1&months=12')->assertOk()->assertJsonPath('data.monthly', 120);
    }

    public function test_a_read_with_a_ttl_is_kept_per_input(): void
    {
        $this->fakeQuote();

        $this->getJson('/!/core-web/live/cached?item=a1&months=12')->assertOk()->assertHeader('Cache-Control', 'max-age=60, private');
        $this->getJson('/!/core-web/live/cached?months=12&item=a1')->assertOk()->assertJsonPath('data.monthly', 120);
        $this->getJson('/!/core-web/live/cached?item=a1&months=24')->assertOk()->assertJsonPath('data.monthly', 240);

        Http::assertSentCount(3); // the token, then one call per distinct input
    }

    public function test_a_failure_is_not_kept(): void
    {
        $this->fakeQuote(Http::response('Server error', 500));
        $this->getJson('/!/core-web/live/cached?item=a1&months=12')->assertStatus(503);

        $this->fakeQuote();
        $this->getJson('/!/core-web/live/cached?item=a1&months=12')->assertOk();
    }

    public function test_server_code_reads_without_the_route(): void
    {
        $this->fakeQuote();

        $result = app(CoreLive::class)->read('quote', ['item' => 'a1', 'months' => 6]);

        $this->assertTrue($result->successful());
        $this->assertSame(['monthly' => 60], $result->data);
        $this->assertSame(LiveResult::INVALID, app(CoreLive::class)->read('quote', [])->status);
    }

    public function test_a_read_that_is_not_a_live_read_is_named(): void
    {
        config(['core-web.live.reads.broken' => \stdClass::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('core-web.live.reads.broken');

        app(CoreLive::class)->read('broken');
    }

    public function test_read_names_are_slugs(): void
    {
        config(['core-web.live.reads' => ['Bad Name' => QuoteRead::class]]);

        $this->expectException(InvalidArgumentException::class);

        CoreWebServiceProvider::liveReads();
    }

    public function test_the_route_is_named(): void
    {
        $this->assertTrue(app(Router::class)->has('core-web.live'));
    }
}

class QuoteRead extends LiveRead
{
    public function rules(): array
    {
        return ['item' => ['required', 'string', 'max:40'], 'months' => ['required', 'integer', 'min:1']];
    }

    public function request(array $input): ?LiveRequest
    {
        return $input['item'] === 'none'
            ? null
            : LiveRequest::post('/api/app/items/'.rawurlencode($input['item']).'/quote', ['months' => (int) $input['months']]);
    }

    public function present(array $json, array $input): ?array
    {
        return isset($json['data']['monthly']) ? ['monthly' => $json['data']['monthly'], ...array_intersect_key($json['data'], ['fallback' => true])] : null;
    }

    public function keep(array $data): bool
    {
        return ! isset($data['fallback']);
    }
}

class CachedRead extends QuoteRead
{
    public function ttl(): int
    {
        return 60;
    }
}
