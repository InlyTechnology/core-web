<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Forms\CoreForm;
use Inly\CoreWeb\Forms\CoreForms;
use Inly\CoreWeb\Forms\FormDefinition;
use Inly\CoreWeb\Forms\FormRenderings;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

/** A site's own form markup posting to a Core form: the definition fills in what the markup leaves out. */
class CoreFormTest extends TestCase
{
    use FakesCore;

    protected array $features = ['snapshots', 'forms'];

    private const UUID = '0199aaaa-0000-7000-8000-000000000001';

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), LivewireServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.forms.renderers', ['inspection' => InspectionForm::class]);
        $app['view']->addLocation(__DIR__.'/../fixtures/views');
    }

    /** @return array<string, mixed> */
    private static function definition(): array
    {
        $contract = json_decode((string) file_get_contents(__DIR__.'/../fixtures/core-form-definition-contract.json'), true, flags: JSON_THROW_ON_ERROR);

        return [
            'version' => 1,
            'form' => [
                'id' => self::UUID,
                'name' => 'Inspection',
                'messages' => ['submit' => 'Book inspection'],
                'labels' => ['submit' => 'Send', 'choose' => 'Choose…'],
                ...$contract['definition'],
            ],
        ];
    }

    /** @param  array<string, mixed>  $stubs */
    private function fakeForms(array $stubs = [], bool $enabled = true): void
    {
        $this->fakeCore([
            'core.test/api/v1/objects/core/form*' => self::coreList([[
                'type' => 'core/form',
                'id' => '7',
                'attributes' => ['slug' => 'inspection', 'uuid' => self::UUID, 'name' => 'Inspection', 'site_key' => 'pk_test', 'accepts_server' => true, 'enabled' => $enabled],
            ]]),
            'core.test/api/forms/'.self::UUID.'/definition*' => Http::response(self::definition()),
            'core.test/api/forms/renderings' => Http::response(['accepted' => [['form' => self::UUID, 'renderer' => 'inspection']], 'refused' => []]),
            ...$stubs,
        ]);
    }

    private function warm(): void
    {
        app(SnapshotStore::class)->refresh(app(SnapshotRegistry::class)->get(CoreForms::TYPE));
    }

    /** @return array<string, mixed> */
    private static function filled(): array
    {
        return ['plate' => 'ABC123', 'name' => 'Ada', 'contact_method' => 'email', 'email' => 'ada@example.test', 'photos' => []];
    }

    public function test_the_snapshot_keeps_each_rendered_form_with_its_definition(): void
    {
        $this->fakeForms();
        $this->warm();

        $form = CoreForms::fromContainer()->form('inspection');

        $this->assertSame(self::UUID, $form['uuid']);
        $this->assertSame('plate', CoreForms::fromContainer()->definition('inspection')?->field('plate')['name']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/definition') && $request['sk'] === 'pk_test' && ! $request->hasHeader('Authorization'));
    }

    public function test_the_form_adds_the_required_fields_its_markup_leaves_out(): void
    {
        $this->fakeForms();
        $this->warm();

        Livewire::test(InspectionForm::class)
            ->assertSeeHtml('class="own-markup"')
            ->assertSeeHtml('wire:model.live="values.contact_method"')
            ->assertSeeHtml('wire:model="values.email"')
            ->assertSeeHtml('wire:model="values.photos"')
            ->assertDontSeeHtml('values.phone')
            ->assertDontSeeHtml('values.mileage')
            ->assertDontSee('leaves out required fields')
            ->assertSee('Book inspection')
            ->set('values.contact_method', 'phone')
            ->assertSeeHtml('wire:model="values.phone"');
    }

    public function test_local_development_says_which_fields_were_added(): void
    {
        $this->app['env'] = 'local';
        $this->fakeForms();
        $this->warm();

        Livewire::test(InspectionForm::class)->assertSee('leaves out required fields')->assertSee('photos, contact_method, email');
    }

    public function test_an_entry_is_checked_against_the_definition_before_it_goes(): void
    {
        $this->fakeForms();
        $this->warm();

        Livewire::test(InspectionForm::class)
            ->set('values.plate', '')
            ->call('submit')
            ->assertHasErrors(['values.plate', 'values.photos', 'values.email']);

        Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/submit'));
    }

    public function test_an_entry_goes_to_core_on_the_site_token_with_the_page_it_came_from(): void
    {
        $this->fakeForms(['core.test/api/forms/'.self::UUID.'/submit' => Http::response(['ok' => true], 201)]);
        $this->warm();

        $component = Livewire::withQueryParams(['utm_source' => 'mail'])->test(InspectionForm::class);

        foreach (self::filled() as $field => $value) {
            $component->set("values.{$field}", $value);
        }

        $component->set('values.photos', [UploadedFile::fake()->image('car.jpg')])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('status', 'sent')
            ->assertSee('Thanks!')
            ->assertSet('values.plate', '');

        Http::assertSent(function (HttpRequest $request) {
            if (! str_ends_with($request->url(), '/api/forms/'.self::UUID.'/submit')) {
                return false;
            }

            $parts = collect($request->data())->keyBy('name');
            $payload = json_decode((string) $parts['payload']['contents'], true);

            return $request->hasHeader('Authorization', 'Bearer token')
                && $payload['plate'] === 'ABC123'
                && $payload['utm_source'] === 'mail'
                && $payload['fuel'] === 'petrol'
                && ! array_key_exists('phone', $payload)
                && $parts->has('photos[]')
                && $parts['photos[]']['filename'] === 'file-1.jpg';
        });
    }

    public function test_the_fields_core_refuses_show_on_the_form(): void
    {
        $this->fakeForms(['core.test/api/forms/'.self::UUID.'/submit' => Http::response(['error' => ['code' => 'schema_validation_failed', 'fields' => ['email' => ['The email is taken.']]]], 422)]);
        $this->warm();

        $component = Livewire::test(InspectionForm::class);

        foreach (self::filled() as $field => $value) {
            $component->set("values.{$field}", $value);
        }

        $component->set('values.photos', [UploadedFile::fake()->image('car.jpg')])
            ->call('submit')
            ->assertSet('status', 'invalid')
            ->assertHasErrors(['values.email'])
            ->assertSet('values.plate', 'ABC123');
    }

    public function test_one_address_sends_a_limited_number_of_entries(): void
    {
        config(['core-web.forms.submit_rate_limit' => 1]);
        $this->fakeForms(['core.test/api/forms/'.self::UUID.'/submit' => Http::response(['ok' => true], 201)]);
        $this->warm();
        RateLimiter::hit('core-web-forms|inspection|'.sha1('127.0.0.1'), 60);

        $component = Livewire::test(InspectionForm::class);

        foreach (self::filled() as $field => $value) {
            $component->set("values.{$field}", $value);
        }

        $component->set('values.photos', [UploadedFile::fake()->image('car.jpg')])
            ->call('submit')
            ->assertSet('status', 'throttled');

        Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/submit'));
    }

    public function test_a_form_without_a_definition_is_unavailable(): void
    {
        $this->fakeForms(enabled: false);
        $this->warm();

        Livewire::test(InspectionForm::class)->call('submit')->assertSet('status', 'unavailable');
    }

    public function test_the_report_tells_core_what_each_renderer_renders(): void
    {
        $this->fakeForms();
        $this->warm();

        $this->assertSame(0, Artisan::call('core:forms-report'));

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
            && $request->url() === 'https://core.test/api/forms/renderings'
            && $request->hasHeader('Authorization', 'Bearer token')
            && $request['renderings'] === [[
                'form' => self::UUID,
                'renderer' => 'inspection',
                'fields' => [
                    ['name' => 'plate', 'type' => 'string'],
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'fuel', 'type' => 'enum', 'options' => ['petrol', 'diesel']],
                ],
            ]]);
    }

    public function test_a_change_to_a_form_reports_again(): void
    {
        $this->fakeForms();
        $this->warm();

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/api/forms/renderings'));
    }

    public function test_the_report_fails_when_core_refuses_a_rendering(): void
    {
        $this->fakeForms(['core.test/api/forms/renderings' => Http::response(['accepted' => [], 'refused' => [['form' => self::UUID, 'renderer' => 'inspection', 'reason' => 'app_client_not_allowed']]])]);
        $this->warm();

        $this->assertSame(1, Artisan::call('core:forms-report'));
        $this->assertStringContainsString('app_client_not_allowed', Artisan::output());
    }

    public function test_a_form_switched_off_in_core_stays_guarded(): void
    {
        $this->fakeForms();
        $this->warm();
        $this->fakeForms(enabled: false);
        app(SnapshotStore::class)->forget(app(SnapshotRegistry::class)->get(CoreForms::TYPE));
        $this->warm();

        $this->assertNull(CoreForms::fromContainer()->definition('inspection'));
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT' && count($request['renderings'][0]['fields'] ?? []) === 3);
    }

    public function test_a_bot_filling_the_honeypot_is_told_its_entry_went(): void
    {
        $this->fakeForms();
        $this->warm();

        Livewire::test(InspectionForm::class)
            ->set('website_url', 'https://spam.test')
            ->call('submit')
            ->assertSet('status', 'sent');

        Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/submit'));
    }

    public function test_the_privacy_box_links_the_policy_and_the_notice_shows(): void
    {
        $definition = self::definition();
        $definition['form']['notice'] = 'We handle your details as described in our [privacy policy](https://site.test/privacy).';
        $definition['form']['fields'][] = ['name' => '_privacy_policy', 'type' => 'boolean', 'label' => 'I accept the [privacy policy](https://site.test/privacy) <script>x</script>', 'required' => true];
        $this->fakeForms(['core.test/api/forms/'.self::UUID.'/definition*' => Http::response($definition)]);
        $this->warm();

        Livewire::test(InspectionForm::class)
            ->assertSeeHtml('<a href="https://site.test/privacy">privacy policy</a>')
            ->assertSeeHtml('class="core-form-notice"')
            ->assertDontSeeHtml('<script>x</script>')
            ->call('submit')
            ->assertHasErrors(['values._privacy_policy']);
    }

    public function test_an_error_core_gives_a_field_the_visitor_cannot_see_is_the_forms(): void
    {
        $this->fakeForms(['core.test/api/forms/'.self::UUID.'/submit' => Http::response(['error' => ['code' => 'schema_validation_failed', 'fields' => ['utm_source' => ['Bad source.'], 'photos.0' => ['Too large.']]]], 422)]);
        $this->warm();

        $component = Livewire::test(InspectionForm::class);

        foreach (self::filled() as $field => $value) {
            $component->set("values.{$field}", $value);
        }

        $component->set('values.photos', [UploadedFile::fake()->image('car.jpg')])
            ->call('submit')
            ->assertHasErrors(['form', 'values.photos']);
    }

    public function test_forms_need_snapshots(): void
    {
        config(['core-web.features.snapshots' => false]);

        $this->expectException(\LogicException::class);

        (new CoreWebServiceProvider($this->app))->boot();
    }

    public function test_a_missing_required_field_is_found_without_rendering(): void
    {
        $definition = new FormDefinition(self::definition());

        $this->assertSame(['photos', 'contact_method', 'email'], CoreForm::missingFrom($definition, InspectionForm::rendered()));
    }

    public function test_a_renderer_must_be_a_form_class(): void
    {
        config(['core-web.forms.renderers' => ['inspection' => \stdClass::class]]);

        $this->expectException(\InvalidArgumentException::class);

        FormRenderings::renderers();
    }
}

class InspectionForm extends CoreForm
{
    public static function coreForm(): string
    {
        return 'inspection';
    }

    public static function renders(): array
    {
        return ['plate', 'name', 'fuel' => ['petrol', 'diesel']];
    }

    protected function fieldsView(): string
    {
        return 'inspection-form';
    }
}
