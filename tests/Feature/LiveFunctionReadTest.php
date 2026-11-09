<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Live\CoreLive;
use Inly\CoreWeb\Live\LiveRead;
use Inly\CoreWeb\Live\LiveRequest;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use InvalidArgumentException;

/** A live read that runs a Core function: Core's functions contract mapped onto the read's answers. */
class LiveFunctionReadTest extends TestCase
{
    use FakesCore;

    protected array $features = ['live'];

    private const URL = '/!/core-web/live/financing?vehicle=v1&months=12';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.live.reads', ['financing' => FinancingRead::class, 'uncached' => UncachedFinancingRead::class, 'lasting' => LastingFinancingRead::class]);
    }

    /** @param  array<string, string>  $headers */
    private function fakeFunction(mixed $answer = null, array $headers = ['Cache-Control' => 'private, max-age=60']): void
    {
        $this->fakeCore([
            'core.test/api/v1/functions/vehicles/calculate-financing*' => $answer ?? fn (HttpRequest $request) => Http::response(
                ['data' => ['monthly' => (int) $request['months'] * 10]],
                200,
                $headers,
            ),
        ]);
    }

    public function test_the_read_runs_the_function_with_its_arguments(): void
    {
        $this->fakeFunction();

        $this->getJson(self::URL.'&winter=1')->assertOk()->assertExactJson(['data' => ['monthly' => 120]]);

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET'
            && $request->url() === 'https://core.test/api/v1/functions/vehicles/calculate-financing?vehicle=v1&months=12&winter=true'
            && $request->hasHeader('Authorization', 'Bearer token'));
    }

    public function test_the_answer_is_kept_for_the_max_age_core_sends(): void
    {
        $this->fakeFunction();

        $this->getJson(self::URL)->assertOk()->assertHeader('Cache-Control', 'max-age=60, private');
        $this->travel(45)->seconds();
        $this->getJson(self::URL)->assertOk()->assertHeader('Cache-Control', 'max-age=15, private');

        Http::assertSentCount(2); // the token, then one call
    }

    public function test_an_answer_core_sends_no_store_is_kept_nowhere(): void
    {
        $this->fakeFunction(headers: ['Cache-Control' => 'no-store, private']);

        $this->getJson(self::URL)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(self::URL)->assertOk();

        Http::assertSentCount(3);
    }

    public function test_a_ttl_the_read_names_wins_over_core_s_max_age(): void
    {
        $this->fakeFunction();

        $this->getJson('/!/core-web/live/uncached?vehicle=v1&months=12')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/!/core-web/live/uncached?vehicle=v1&months=12')->assertOk();

        Http::assertSentCount(3);
    }

    public function test_core_s_refusal_is_passed_on_per_input(): void
    {
        $this->fakeFunction(Http::response(['errors' => [
            ['code' => 'invalid', 'title' => 'Max 84 months.', 'source' => ['parameter' => 'months']],
            ['code' => 'invalid', 'title' => 'Margin below floor.', 'source' => ['parameter' => 'margin']],
            ['code' => 'invalid', 'title' => 'No parameter.'],
        ]], 422));

        $this->getJson(self::URL)->assertStatus(422)->assertExactJson(['error' => 'invalid', 'errors' => ['months' => ['Max 84 months.']]]);
    }

    public function test_nothing_for_the_input_is_not_found(): void
    {
        $this->fakeFunction(Http::response(['errors' => [['code' => 'not_found', 'title' => 'Not found.']]], 404));

        $this->getJson(self::URL)->assertNotFound()->assertExactJson(['error' => 'not_found']);
    }

    public function test_a_dependency_down_switches_the_read_off_for_its_retry_after(): void
    {
        $this->fakeFunction(Http::response(['errors' => [['code' => 'unavailable', 'title' => 'Down.']]], 503, ['Retry-After' => '120']));
        $this->getJson(self::URL)->assertStatus(503);

        $this->fakeFunction();
        $this->travel(60)->seconds();
        $this->getJson(self::URL)->assertStatus(503);
        Http::assertNothingSent();

        $this->travel(61)->seconds();
        $this->getJson(self::URL)->assertOk();
    }

    public function test_past_the_function_s_rate_limit_the_read_is_off_for_its_retry_after(): void
    {
        $this->fakeFunction(Http::response(['errors' => [['code' => 'throttled', 'title' => 'Too many.']]], 429, ['Retry-After' => '20']));
        $this->getJson(self::URL)->assertStatus(503);

        $this->fakeFunction();
        $this->getJson('/!/core-web/live/financing?vehicle=v2&months=12')->assertStatus(503);
        Http::assertNothingSent();

        $this->travel(21)->seconds();
        $this->getJson('/!/core-web/live/financing?vehicle=v2&months=12')->assertOk();
    }

    public function test_an_answer_not_ready_for_one_input_leaves_the_others(): void
    {
        $this->fakeFunction(Http::response(['errors' => [['code' => 'pending', 'title' => 'Working.']]], 202, ['Retry-After' => '5']));
        $this->getJson(self::URL)->assertStatus(503);

        $this->fakeFunction();
        $this->getJson('/!/core-web/live/financing?vehicle=v2&months=12')->assertOk();
    }

    public function test_nothing_to_answer_is_not_found(): void
    {
        $this->fakeFunction(Http::response(null, 204));

        $this->getJson(self::URL)->assertNotFound()->assertExactJson(['error' => 'not_found']);
    }

    public function test_an_answer_that_cannot_be_given_yet_leaves_the_read_on(): void
    {
        $this->fakeFunction(Http::response(['errors' => [['code' => 'open_period', 'title' => 'Open.']]], 409));
        $this->getJson(self::URL)->assertStatus(503)->assertExactJson(['error' => 'unavailable']);

        $this->fakeFunction();
        $this->getJson(self::URL)->assertOk();
    }

    public function test_the_last_answer_kept_for_core_s_max_age_is_served_while_the_function_is_down(): void
    {
        $this->fakeFunction();
        $this->getJson('/!/core-web/live/lasting?vehicle=v1&months=12')->assertOk();

        $this->travel(2)->minutes();
        $this->fakeFunction(Http::response(['errors' => [['code' => 'unavailable', 'title' => 'Down.']]], 503, ['Retry-After' => '60']));

        $this->getJson('/!/core-web/live/lasting?vehicle=v1&months=12')->assertOk()->assertExactJson(['data' => ['monthly' => 120], 'stale' => true]);
    }

    public function test_a_long_retry_after_is_capped_at_five_minutes(): void
    {
        $this->fakeFunction(Http::response(['errors' => [['code' => 'unavailable', 'title' => 'Down.']]], 503, ['Retry-After' => '86400']));
        $this->getJson(self::URL)->assertStatus(503);

        $this->fakeFunction();
        $this->travel(301)->seconds();
        $this->getJson(self::URL)->assertOk();
    }

    public function test_upper_case_cache_control_is_read(): void
    {
        $this->fakeFunction(headers: ['Cache-Control' => 'Private, Max-Age=30']);

        $this->getJson(self::URL)->assertOk()->assertHeader('Cache-Control', 'max-age=30, private');
    }

    public function test_a_function_key_is_checked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LiveRequest::function('../objects');
    }

    public function test_server_code_runs_it_too(): void
    {
        $this->fakeFunction();

        $this->assertSame(['monthly' => 60], app(CoreLive::class)->read('financing', ['vehicle' => 'v1', 'months' => 6])->data);
    }
}

class FinancingRead extends LiveRead
{
    public function public(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['vehicle' => ['required', 'alpha_dash', 'max:40'], 'months' => ['required', 'integer', 'min:1', 'max:240'], 'winter' => ['sometimes', 'boolean']];
    }

    public function request(array $input): ?LiveRequest
    {
        return LiveRequest::function('vehicles/calculate-financing', [
            'vehicle' => $input['vehicle'],
            'months' => (int) $input['months'],
            ...isset($input['winter']) ? ['winter' => (bool) $input['winter']] : [],
        ]);
    }
}

class UncachedFinancingRead extends FinancingRead
{
    public function ttl(): int
    {
        return 0;
    }
}

class LastingFinancingRead extends FinancingRead
{
    public function staleFor(): int
    {
        return 3600;
    }
}
