<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\Forms\CoreForms;
use Inly\CoreWeb\Forms\FormRelay;
use Inly\CoreWeb\Forms\FormSubmitter;
use Inly\CoreWeb\Forms\SubmissionResult;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\Feature\ContentSync\SyncsContent;
use Inly\CoreWeb\Tests\TestCase;
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
        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);

            if ($host === 'core.test') {
                return $this->coreAnswer($request);
            }

            if ($host !== 'remote.test') {
                return Http::response('unexpected host', 500);
            }

            $server = [];

            foreach ($request->headers() as $name => $values) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $values[0];
            }

            $server['CONTENT_TYPE'] = $request->header('Content-Type')[0] ?? '';

            // The live site has its Core; this checkout does not.
            $local = array_map(fn (string $key) => config($key), array_combine(array_keys(self::CORE), array_keys(self::CORE)));
            config(self::CORE);

            try {
                $response = $this->call($request->method(), $request->url(), $request->isJson() ? [] : (array) $request->data(), [], [], $server, $request->isJson() ? $request->body() : null);
            } finally {
                config($local);
            }

            return Http::response($response->getContent(), $response->getStatusCode(), $response->headers->all());
        });
    }

    private function coreAnswer(Request $request)
    {
        if (str_ends_with($request->url(), '/oauth/token')) {
            return Http::response(['access_token' => 'site-access-token', 'expires_in' => 3600]);
        }

        if (str_contains($request->url(), '/api/v1/objects/core/form')) {
            return Http::response(['data' => [
                ['type' => 'core/form', 'id' => '7', 'attributes' => ['slug' => 'contact', 'uuid' => self::UUID, 'name' => 'Contact', 'site_key' => 'pk_test', 'accepts_server' => true, 'enabled' => true]],
            ], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]);
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
        $this->assertSame('https://remote.test', $this->core[0]->header('Origin')[0]);
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
        $this->assertSame('https://remote.test', $submit->header('Origin')[0]);
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
}
