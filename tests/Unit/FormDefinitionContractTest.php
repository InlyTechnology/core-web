<?php

namespace Inly\CoreWeb\Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Inly\CoreWeb\Forms\FormDefinition;
use Inly\CoreWeb\Tests\TestCase;

/**
 * Read against Core's definition contract (docs/learn/forms/definition-contract.md
 * in inly-core, copied to tests/fixtures): when Core changes what a
 * definition carries, the copy is replaced and these say what to catch up on.
 */
class FormDefinitionContractTest extends TestCase
{
    private static function definition(): FormDefinition
    {
        $contract = json_decode((string) file_get_contents(__DIR__.'/../fixtures/core-form-definition-contract.json'), true, flags: JSON_THROW_ON_ERROR);

        return new FormDefinition(['version' => $contract['version'], 'form' => ['id' => 'f-1', ...$contract['definition']]]);
    }

    public function test_it_reads_the_version_it_was_written_for(): void
    {
        $this->assertSame(FormDefinition::VERSION, self::definition()->version());
    }

    public function test_it_knows_every_field_type_and_key_core_sends(): void
    {
        foreach (self::definition()->fields() as $name => $field) {
            $this->assertTrue(FormDefinition::knows($field), "Field [{$name}] has a type core-web does not know: ".FormDefinition::typeOf($field));
            $this->assertSame([], array_values(array_diff(array_keys($field), FormDefinition::KEYS)), "Field [{$name}] carries a key core-web does not know.");
        }
    }

    public function test_required_fields_follow_the_conditions(): void
    {
        $definition = self::definition();

        $this->assertSame(['plate', 'photos', 'name', 'contact_method', 'email'], $definition->required($definition->defaults()));
        $this->assertContains('phone', $definition->required(['contact_method' => 'phone']));
    }

    public function test_options_follow_their_own_conditions(): void
    {
        $definition = self::definition();

        $this->assertSame(['towbar'], array_column($definition->options('extras', ['fuel' => 'petrol']), 'value'));
        $this->assertSame(['towbar', 'charger'], array_column($definition->options('extras', ['fuel' => 'electric']), 'value'));
    }

    public function test_a_field_a_condition_hid_reads_blank_to_the_fields_after_it(): void
    {
        $definition = new FormDefinition(['version' => 1, 'form' => ['fields' => [
            ['name' => 'kind', 'type' => 'enum', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
            ['name' => 'detail', 'type' => 'string', 'visibleWhen' => ['field' => 'kind', 'operator' => 'equals', 'value' => 'a']],
            ['name' => 'more', 'type' => 'string', 'required' => true, 'visibleWhen' => ['field' => 'detail', 'operator' => 'filled']],
            ['name' => 'pick', 'type' => 'enum', 'required' => true, 'options' => [['value' => 'x', 'label' => 'X', 'visibleWhen' => ['field' => 'kind', 'operator' => 'equals', 'value' => 'a']]]],
        ]]]);

        $this->assertSame(['more', 'pick'], $definition->required(['kind' => 'a', 'detail' => 'left over']));
        $this->assertSame([], $definition->required(['kind' => 'b', 'detail' => 'left over']));
    }

    public function test_the_rules_check_what_core_checks(): void
    {
        $definition = self::definition();
        $values = ['plate' => '', 'fuel' => 'hydrogen', 'email' => 'no', 'contact_method' => 'email', 'name' => 'Ada', 'photos' => [], 'mileage' => 'many'];

        $errors = Validator::make(['values' => $values], $definition->rules($values))->errors();

        foreach (['values.plate', 'values.fuel', 'values.email', 'values.photos', 'values.mileage'] as $key) {
            $this->assertTrue($errors->has($key), "Expected an error on [{$key}].");
        }

        $this->assertFalse($errors->has('values.name'));
        $this->assertArrayNotHasKey('values.phone', $definition->rules($values));
        $this->assertArrayNotHasKey('values.utm_source', $definition->rules($values));
    }

    public function test_the_payload_sends_visible_values_and_the_hidden_fields_core_fills(): void
    {
        $definition = self::definition();
        $values = [...$definition->defaults(), 'plate' => 'ABC123', 'contact_method' => 'email', 'phone' => '0701234567', 'callback' => '1', 'mileage' => '1200'];

        $payload = $definition->payload($values);

        $this->assertSame('ABC123', $payload['plate']);
        $this->assertSame('petrol', $payload['fuel']);
        $this->assertTrue($payload['callback']);
        $this->assertSame(1200, $payload['mileage']);
        $this->assertSame('direct', $payload['utm_source']);
        $this->assertArrayNotHasKey('phone', $payload);
        $this->assertArrayNotHasKey('photos', $payload);

        $this->assertSame('newsletter', $definition->payload($values, ['utm_source' => 'newsletter'])['utm_source']);
    }

    public function test_a_source_names_a_value_of_the_page(): void
    {
        $page = ['url' => 'https://site.test/a', 'referrer' => 'https://search.test', 'lang' => 'sv', 'query' => ['utm_source' => 'mail'], 'context' => ['shop' => 'north']];

        $this->assertSame('https://site.test/a', FormDefinition::sourceValue('page.url', $page));
        $this->assertSame('https://search.test', FormDefinition::sourceValue('referrer', $page));
        $this->assertSame('mail', FormDefinition::sourceValue('query.utm_source', $page));
        $this->assertSame('north', FormDefinition::sourceValue('context.shop', $page));
        $this->assertNull(FormDefinition::sourceValue('page.title', $page));
    }
}
