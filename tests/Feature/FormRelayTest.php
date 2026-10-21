<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Forms\CoreForm;
use Inly\CoreWeb\Forms\CoreForms;
use Inly\CoreWeb\Forms\FormRelay;
use Inly\CoreWeb\Forms\FormSubmitter;
use Inly\CoreWeb\Forms\SubmissionResult;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\Feature\ContentSync\SyncsContent;
use Inly\CoreWeb\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * A local checkout's Core forms through the live site: this app plays both,
 * with a Core address only while it answers as the live site.
 */
class FormRelayTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;
    use SyncsContent;

    protected array $features = ['snapshots', 'forms', 'content_sync'];

    private const UUID = '0199aaaa-0000-7000-8000-000000000001';

    private const OTHER = '0199aaaa-0000-7000-8000-000000000002';

    /** A form on the live site's list that no form class posts to. */
    private const EMBED_ONLY = '0199aaaa-0000-7000-8000-000000000003';

    private const LIVE_URL = 'https://www.live.test';

    private const CORE = [
        'core-web.client.base_url' => 'https://core.test',
        'core-web.client.client_id' => 'site',
        'core-web.client.client_secret' => 'site-secret',
    ];

    private string $token;

    /** @var list<Request> what reached Core */
    private array $core = [];

    /** @var array<string, array{0: mixed, 1: int}> Core's answer by the end of the path */
    private array $answers = [];

    /** @var array<string, mixed>|null the options of the last call to the live site */
    private ?array $liveOptions = null;

    /** @var array{0: mixed, 1: int}|null the live site's answer for its whole form relay, as an older core-web without it */
    private ?array $noRelay = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.forms.classes', [RelayedContactForm::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        Cache::flush();
        Storage::fake('local');
        config([
            'core-web.content_sync.tokens.disk' => 'local',
            'core-web.content_sync.remote_url' => 'https://remote.test',
            'core-web.content_sync.allow_local_remote' => true,
        ]);
        $this->roles(['editor' => ['access cp', 'pull content'], 'reader' => ['access cp']]);
        $this->routeToThisApp();

        config(self::CORE);
        app(SnapshotStore::class)->refresh(app(SnapshotRegistry::class)->get(CoreForms::TYPE));
        $this->core = [];
        config(array_map(fn () => '', self::CORE));

        $this->token = $this->tokenFor($this->cmsUser('boss@example.com', super: true));
        RemoteSite::store('https://remote.test', $this->token, ['name' => 'Boss', 'email' => 'boss@example.com'], now()->addDays(30)->toIso8601String());
    }

    protected function tearDown(): void
    {
        RemoteSite::forget();

        parent::tearDown();
    }

    private function routeToThisApp(): void
    {
        Http::swap(new Factory);
        Http::fake(function (Request $request, array $options) {
            $host = parse_url($request->url(), PHP_URL_HOST);

            if ($host === 'core.test') {
                return $this->coreAnswer($request);
            }

            if ($host !== 'remote.test') {
                return Http::response('unexpected host', 500);
            }

            $this->liveOptions = $options;

            if ($this->noRelay !== null && str_contains($request->url(), '/sync/forms/')) {
                return Http::response(...$this->noRelay);
            }

            $server = [];

            foreach ($request->headers() as $name => $values) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $values[0];
            }

            $server['CONTENT_TYPE'] = $request->header('Content-Type')[0] ?? '';
            [$parameters, $files] = $request->isMultipart() ? self::multipart($request->data()) : [$request->isJson() ? [] : (array) $request->data(), []];

            // The live site has its Core and its own address; this checkout has neither.
            $local = array_map(fn (string $key) => config($key), array_combine([...array_keys(self::CORE), 'app.url'], [...array_keys(self::CORE), 'app.url']));
            config([...self::CORE, 'app.url' => self::LIVE_URL]);

            try {
                $response = $this->call($request->method(), $request->url(), $parameters, [], $files, $server, $request->isJson() ? $request->body() : null);
            } finally {
                config($local);
            }

            return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
        });
    }

    /**
     * The parts a multipart request sent, as PHP would hand them to the live site: fields as input, parts with a file name as uploads.
     *
     * @param  list<array{name: string, contents: mixed, filename?: string}>  $parts
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function multipart(array $parts): array
    {
        $fields = [];
        $files = [];

        foreach ($parts as $part) {
            $contents = (string) $part['contents'];

            if (! isset($part['filename'])) {
                self::assign($fields, $part['name'], $contents);

                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'relay');
            file_put_contents($path, $contents);
            self::assign($files, $part['name'], new UploadedFile($path, $part['filename'], null, null, true));
        }

        return [$fields, $files];
    }

    /** @param  array<string, mixed>  $into */
    private static function assign(array &$into, string $name, mixed $value): void
    {
        preg_match_all('/\[([^\]]*)\]/', $name, $matches);
        $keys = [strtok($name, '['), ...$matches[1]];
        $target = &$into;

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $key === '' ? $target[] = $value : $target[$key] = $value;

                break;
            }

            $target[$key] ??= [];
            $target = &$target[$key];
        }
    }

    private function coreAnswer(Request $request)
    {
        if (str_ends_with($request->url(), '/oauth/token')) {
            return Http::response(['access_token' => 'site-access-token', 'expires_in' => 3600]);
        }

        if (str_contains($request->url(), '/api/v1/objects/core/form')) {
            return Http::response(['data' => [
                ['type' => 'core/form', 'id' => '7', 'attributes' => ['slug' => 'contact', 'uuid' => self::UUID, 'name' => 'Contact', 'site_key' => 'pk_test', 'accepts_server' => true, 'enabled' => true]],
                ['type' => 'core/form', 'id' => '8', 'attributes' => ['slug' => 'newsletter', 'uuid' => self::EMBED_ONLY, 'name' => 'Newsletter', 'site_key' => 'pk_news', 'accepts_server' => true, 'enabled' => true]],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 2]]);
        }

        $this->core[] = $request;
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        foreach ($this->answers as $end => [$body, $status]) {
            if (str_ends_with($path, $end)) {
                return Http::response($body, $status);
            }
        }

        if (str_ends_with($path, '/public/forms/embed/v1.js')) {
            return Http::response('window.CoreForms = {};', 200, ['Content-Type' => 'application/javascript']);
        }

        if (str_ends_with($path, '/definition')) {
            return Http::response(['version' => 1, 'form' => ['id' => self::UUID, 'fields' => []]]);
        }

        if (str_ends_with($path, '/nonce')) {
            return Http::response(['nonce' => 'n-1', 'expires_in' => 600]);
        }

        return Http::response(['status' => 'received'], 201);
    }

    private function relay(string $path): string
    {
        return '/!/core-web/forms-relay/'.ltrim($path, '/');
    }

    public function test_the_embed_takes_the_relay_as_its_core_on_a_signed_in_checkout_without_one(): void
    {
        $this->assertTrue(CoreForms::isRelayed());
        $this->assertSame(FormRelay::url(), CoreForms::embedBaseUrl());

        config(['core-web.client.base_url' => 'https://core.test']);
        $this->assertFalse(CoreForms::isRelayed());
        $this->assertSame('https://core.test', CoreForms::embedBaseUrl());

        config(['core-web.client.base_url' => '']);
        RemoteSite::forget();
        $this->assertFalse(CoreForms::isRelayed());
        $this->assertNull(CoreForms::embedBaseUrl());
    }

    public function test_a_server_never_relays(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse(CoreForms::isRelayed());
        $this->assertNull(CoreForms::embedBaseUrl());
    }

    public function test_the_definition_reaches_core_as_the_live_site(): void
    {
        $this->getJson($this->relay('api/forms/'.self::UUID.'/definition?sk=pk_test&locale=sv'))
            ->assertOk()
            ->assertJsonPath('form.id', self::UUID);

        $this->assertCount(1, $this->core);
        $this->assertSame('pk_test', $this->core[0]['sk']);
        $this->assertSame('sv', $this->core[0]['locale']);
        $this->assertSame(self::LIVE_URL, $this->core[0]->header('Origin')[0]);
        $this->assertFalse($this->core[0]->hasHeader('Authorization'));
    }

    public function test_an_entry_goes_to_core_with_the_embed_s_nonce_and_site_key(): void
    {
        $this->getJson($this->relay('api/forms/'.self::UUID.'/nonce'))->assertOk()->assertJsonPath('nonce', 'n-1');

        $this->postJson($this->relay('api/forms/'.self::UUID.'/submit'), ['name' => 'Ada'], ['X-Core-Form-Site-Key' => 'pk_test', 'X-Core-Form-Nonce' => 'n-1', 'Cookie' => 'secret=1'])
            ->assertStatus(201)
            ->assertJsonPath('status', 'received');

        $submit = $this->core[1];
        $this->assertSame('POST', $submit->method());
        $this->assertSame(['name' => 'Ada'], $submit->data());
        $this->assertSame('pk_test', $submit->header('X-Core-Form-Site-Key')[0]);
        $this->assertSame('n-1', $submit->header('X-Core-Form-Nonce')[0]);
        $this->assertSame(self::LIVE_URL, $submit->header('Origin')[0]);
        $this->assertFalse($submit->hasHeader('Cookie'));
        $this->assertFalse($submit->hasHeader('Authorization'));
    }

    public function test_core_s_refusal_comes_back_as_it_is(): void
    {
        $this->answers['/submit'] = [['error' => ['code' => 'validation_failed', 'message' => 'Nope', 'fields' => ['email' => ['Invalid']]]], 422];

        $this->postJson($this->relay('api/forms/'.self::UUID.'/submit'), ['email' => 'x'], ['X-Core-Form-Nonce' => 'n-1'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.email.0', 'Invalid');
    }

    public function test_a_form_off_the_site_s_list_is_refused_before_core(): void
    {
        $this->getJson($this->relay('api/forms/'.self::OTHER.'/definition?sk=pk_x'))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'unknown_form');

        $this->assertSame([], $this->core);
    }

    public function test_without_a_login_the_embed_is_told_to_sign_in(): void
    {
        RemoteSite::forget();

        $this->getJson($this->relay('api/forms/'.self::UUID.'/definition'))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'relay_login_needed');

        $this->assertSame([], $this->core);
    }

    public function test_the_live_site_refuses_a_call_without_a_token(): void
    {
        config(self::CORE);

        $this->getJson('https://remote.test/!/core-web/sync/forms/'.self::UUID.'/definition')->assertUnauthorized();
    }

    public function test_the_embed_script_comes_through_the_live_site_once(): void
    {
        $this->get($this->relay('public/forms/embed/v1.js'))->assertOk()->assertSee('window.CoreForms');
        $this->get($this->relay('public/forms/embed/v1.js'))->assertOk();

        $this->assertCount(1, $this->core);
        $this->get($this->relay('public/forms/embed/other.js'))->assertNotFound();
    }

    public function test_a_form_class_s_entry_goes_through_the_live_site_on_its_client_token(): void
    {
        $submitter = new FormSubmitter(CoreClient::fromConfig());

        $this->assertSame(SubmissionResult::SENT, $submitter->submit(self::UUID, ['name' => 'Ada']));

        $this->assertSame('Bearer site-access-token', $this->core[0]->header('Authorization')[0]);
        $this->assertSame(['name' => 'Ada'], $this->core[0]->data());
    }

    public function test_a_relayed_entry_core_refuses_names_its_fields(): void
    {
        $this->answers['/submit'] = [['error' => ['code' => 'validation_failed', 'fields' => ['email' => ['Invalid email']]]], 422];
        $submitter = new FormSubmitter(CoreClient::fromConfig());

        $this->assertSame(SubmissionResult::INVALID, $submitter->submit(self::UUID, ['email' => 'x']));
        $this->assertSame(['email' => 'Invalid email'], $submitter->errors());
    }

    public function test_the_notice_shows_only_while_forms_are_relayed(): void
    {
        $this->assertStringContainsString('Submissions are real leads', Blade::render('<x-core-web::forms.relay-notice />'));

        config(self::CORE);
        $this->assertStringNotContainsString('Submissions are real leads', Blade::render('<x-core-web::forms.relay-notice />'));
    }

    /** @return list<string> the multipart part names Core received */
    private static function partNames(Request $request): array
    {
        return array_map(fn (array $part) => $part['name'], $request->data());
    }

    public function test_an_embed_entry_with_files_reaches_core_as_multipart(): void
    {
        config(self::CORE);

        $this->post('https://remote.test/!/core-web/sync/forms/'.self::UUID.'/submit', [
            'name' => 'Ada',
            'photos' => [UploadedFile::fake()->image('car.jpg')],
        ], ['Authorization' => 'Bearer '.$this->token, 'X-Core-Form-Nonce' => 'n-1', 'Accept' => 'application/json'])->assertStatus(201);

        $this->assertTrue($this->core[0]->isMultipart());
        $this->assertSame(['name', 'photos[0]'], self::partNames($this->core[0]));
        $this->assertSame('n-1', $this->core[0]->header('X-Core-Form-Nonce')[0]);
    }

    public function test_a_form_class_s_entry_with_files_is_sent_on_by_the_live_site(): void
    {
        config(self::CORE);

        $this->post('https://remote.test/!/core-web/sync/forms/'.self::UUID.'/entries', [
            'payload' => json_encode(['name' => 'Ada']),
            'photos' => [UploadedFile::fake()->image('car.jpg')],
        ], ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])->assertOk()->assertJsonPath('result', 'sent');

        $this->assertTrue($this->core[0]->isMultipart());
        $this->assertSame(['payload', 'photos[]'], self::partNames($this->core[0]));
        $this->assertSame('Bearer site-access-token', $this->core[0]->header('Authorization')[0]);
    }

    public function test_the_live_site_sends_on_no_entry_for_a_form_off_its_list(): void
    {
        config(self::CORE);

        $this->postJson('https://remote.test/!/core-web/sync/forms/'.self::OTHER.'/entries', ['payload' => ['name' => 'Ada']], ['Authorization' => 'Bearer '.$this->token])
            ->assertNotFound();

        $this->assertSame([], $this->core);
    }

    public function test_the_local_relay_answers_only_this_machine_without_a_proxy_in_between(): void
    {
        $url = $this->relay('api/forms/'.self::UUID.'/definition?sk=pk_test');

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->getJson($url)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson($url, ['X-Forwarded-For' => '203.0.113.9'])->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '::1'])->getJson($url, ['Via' => '1.1 tunnel'])->assertNotFound();
        $this->assertSame([], $this->core);

        $this->withServerVariables(['REMOTE_ADDR' => '::1'])->getJson($url)->assertOk();
    }

    public function test_a_form_class_s_files_cross_from_the_checkout_to_the_live_site_to_core(): void
    {
        $submitter = new FormSubmitter(CoreClient::fromConfig());
        $photo = UploadedFile::fake()->image('car.jpg', 20, 20);

        $this->assertSame(SubmissionResult::SENT, $submitter->submit(self::UUID, ['name' => 'Ada'], ['photos' => [$photo]]));

        $sent = $this->core[0];
        $this->assertTrue($sent->isMultipart());
        $this->assertSame('Bearer site-access-token', $sent->header('Authorization')[0]);
        $this->assertSame(['payload', 'photos[]'], self::partNames($sent));
        $this->assertSame(['name' => 'Ada'], json_decode((string) $sent->data()[0]['contents'], true));
    }

    public function test_the_live_site_sends_on_no_entry_for_a_form_no_class_posts_to(): void
    {
        config(self::CORE);

        $this->postJson('https://remote.test/!/core-web/sync/forms/'.self::EMBED_ONLY.'/entries', ['payload' => ['name' => 'Ada']], ['Authorization' => 'Bearer '.$this->token])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'unknown_form');

        $this->assertSame([], $this->core);
    }

    public function test_core_s_own_401_reaches_the_embed_as_core_said_it(): void
    {
        $this->answers['/submit'] = [['error' => ['code' => 'invalid_site_key', 'message' => 'Site key is invalid or missing.']], 401];

        $this->postJson($this->relay('api/forms/'.self::UUID.'/submit'), ['name' => 'Ada'], ['X-Core-Form-Nonce' => 'n-1'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_site_key');
    }

    public function test_a_proxy_s_401_in_front_of_the_live_site_is_named_as_such(): void
    {
        $this->noRelay = ['Unauthorized', 401];

        $this->getJson($this->relay('api/forms/'.self::UUID.'/definition'))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'relay_unavailable');
    }

    public function test_the_local_submit_waits_longer_than_the_live_site_waits_on_core(): void
    {
        $this->postJson($this->relay('api/forms/'.self::UUID.'/submit'), ['name' => 'Ada'], ['X-Core-Form-Nonce' => 'n-1'])->assertStatus(201);

        $this->assertSame(config('core-web.forms.timeouts.submit') + 10, $this->liveOptions['timeout']);
    }

    public function test_the_origin_is_the_live_site_s_configured_address_not_the_host_it_was_called_on(): void
    {
        config([...self::CORE, 'app.url' => self::LIVE_URL]);

        $this->getJson('https://remote.test/!/core-web/sync/forms/'.self::UUID.'/nonce', ['Authorization' => 'Bearer '.$this->token, 'Host' => 'evil.test'])->assertOk();

        $this->assertSame(self::LIVE_URL, $this->core[0]->header('Origin')[0]);
    }

    /** @return iterable<string, array{0: mixed, 1: int}> */
    public static function liveSitesWithoutTheRelay(): iterable
    {
        yield 'json 404' => [['message' => 'Not Found.'], 404];
        yield 'html 404' => ['<html>Not Found</html>', 404];
    }

    /** @param  array{0: mixed, 1: int}  $answer */
    #[DataProvider('liveSitesWithoutTheRelay')]
    public function test_a_live_site_without_the_relay_is_named_as_such(mixed $body, int $status): void
    {
        $this->noRelay = [$body, $status];

        $this->getJson($this->relay('api/forms/'.self::UUID.'/definition'))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'relay_missing')
            ->assertJsonPath('error.message', FormRelay::NO_RELAY);

        Log::spy();
        $this->assertSame(SubmissionResult::UNAVAILABLE, (new FormSubmitter(CoreClient::fromConfig()))->submit(self::UUID, ['name' => 'Ada']));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, FormRelay::NO_RELAY))->once();
    }

    public function test_the_whole_loopback_range_and_mapped_addresses_count_as_this_machine(): void
    {
        $url = $this->relay('api/forms/'.self::UUID.'/definition?sk=pk_test');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2'])->getJson($url)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '::ffff:127.0.0.1'])->getJson($url)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.1'])->getJson($url)->assertNotFound();
    }

    public function test_a_page_served_to_another_machine_gets_no_relay(): void
    {
        $this->app->instance('request', \Illuminate\Http\Request::create('http://default.test/', server: ['REMOTE_ADDR' => '192.168.1.20']));

        $this->assertNull(CoreForms::embedBaseUrl());
        $this->assertFalse(FormRelay::relaysSubmissions());
        $this->assertStringNotContainsString('production', Blade::render('<x-core-web::forms.relay-notice />'));
    }

    public function test_uploads_past_the_limit_are_refused_before_they_are_relayed(): void
    {
        config(['core-web.forms.relay_max_upload_bytes' => 1024]);

        $this->post($this->relay('api/forms/'.self::UUID.'/submit'), ['photo' => UploadedFile::fake()->create('big.jpg', 2)], ['Accept' => 'application/json'])
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'payload_too_large');

        $this->assertNull($this->liveOptions);
    }

    public function test_an_upload_that_did_not_arrive_whole_is_a_422(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'relay');
        file_put_contents($path, 'part');
        $broken = new UploadedFile($path, 'car.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL, true);

        $this->post($this->relay('api/forms/'.self::UUID.'/submit'), ['photo' => $broken], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_upload');

        config(self::CORE);
        $this->post('https://remote.test/!/core-web/sync/forms/'.self::UUID.'/entries', ['payload' => '{}', 'photo' => $broken], ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame([], $this->core);
    }

    public function test_a_turnstile_entry_is_refused_with_the_reason(): void
    {
        $this->postJson($this->relay('api/forms/'.self::UUID.'/submit'), ['name' => 'Ada'], ['Cf-Turnstile-Response' => 'tok'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'turnstile_not_relayable');

        $this->assertNull($this->liveOptions);
    }

    public function test_a_form_is_found_by_its_uuid_whatever_its_case(): void
    {
        $this->assertSame('contact', CoreForms::fromContainer()->formByUuid(strtoupper(self::UUID))['key']);
    }

    public function test_the_notice_speaks_the_site_s_language(): void
    {
        app()->setLocale('sv');

        $this->assertStringContainsString('Det som skickas blir riktiga leads', Blade::render('<x-core-web::forms.relay-notice />'));
    }
}

class RelayedContactForm extends CoreForm
{
    public static function coreForm(): string
    {
        return 'contact';
    }

    public static function renders(): array
    {
        return [];
    }

    protected function fieldsView(): string
    {
        return 'contact-form';
    }
}
