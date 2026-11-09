<?php

namespace Inly\CoreWeb\Tests\Unit;

use Inly\CoreWeb\Search\LocalEngine;
use Inly\CoreWeb\Search\SearchQuery;
use Inly\CoreWeb\Search\SearchSchema;
use Inly\CoreWeb\Tests\TestCase;

/** What the local engine leaves to Core beyond the contract's cases, and the pages it gives. */
class LocalEngineTest extends TestCase
{
    protected array $features = ['search'];

    private static function engine(): LocalEngine
    {
        return new LocalEngine(SearchSchema::fromDescribe([
            'fields' => [
                'name' => ['type' => 'string', 'filter_key' => 'name'],
                'year' => ['type' => 'number', 'filter_key' => 'year'],
                'price' => ['type' => 'number', 'filter_key' => 'price'],
                'registered' => ['type' => 'date', 'filter_key' => 'registered'],
                'fuel' => ['type' => 'string', 'filter_key' => 'fuel', 'options' => [['value' => 'El', 'label' => 'El'], ['value' => 'Bensin', 'label' => 'Bensin']]],
                'gearbox' => ['type' => 'string', 'filter_key' => 'gearbox', 'options_from_values' => 50],
                'wholesale' => ['type' => 'number', 'filter_key' => 'wholesale', 'sort_custom' => true],
            ],
            'fields_available' => ['name', 'year', 'price', 'registered', 'fuel', 'gearbox', 'wholesale'],
            'filters_available' => ['name', 'year', 'price', 'registered', 'fuel', 'gearbox'],
            'filters_with_operators' => ['year', 'price', 'registered'],
            'filters_nullable' => ['price', 'fuel'],
            'sorts_available' => ['name', 'year', 'price', 'registered', 'wholesale'],
            'page' => ['default_size' => 2, 'max_size' => 2],
        ]));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return [
            ['id' => 1, 'name' => 'Octavia', 'year' => 2019, 'price' => 189000, 'registered' => '2019-03-01', 'fuel' => 'Bensin', 'gearbox' => 'Manuell'],
            ['id' => 2, 'name' => 'Enyaq', 'year' => null, 'price' => null, 'registered' => '2022-05-10', 'fuel' => 'El', 'gearbox' => 'Automat'],
            ['id' => 3, 'name' => 'XC60', 'year' => 2021, 'price' => 459000, 'registered' => '2021-09-15', 'fuel' => null, 'gearbox' => 'Automat'],
        ];
    }

    public function test_a_page_past_the_last_holds_no_rows(): void
    {
        $result = self::engine()->run(new SearchQuery(page: 9), self::rows());

        $this->assertSame([], $result->rows);
        $this->assertSame(3, $result->total);
        $this->assertSame(2, $result->lastPage);
    }

    public function test_a_page_size_is_kept_within_the_description(): void
    {
        $this->assertCount(2, self::engine()->run(new SearchQuery(pageSize: PHP_INT_MAX), self::rows())->rows);
        $this->assertCount(1, self::engine()->run(new SearchQuery(pageSize: -5), self::rows())->rows);
    }

    public function test_a_sort_the_database_orders_goes_to_core(): void
    {
        $engine = self::engine();

        $this->assertNotNull($engine->refusal(new SearchQuery(sort: 'name')));
        $this->assertNotNull($engine->refusal(new SearchQuery(sort: '-wholesale')));
        $this->assertNull($engine->refusal(new SearchQuery(sort: '-price')));
        $this->assertNull($engine->refusal(new SearchQuery(sort: 'registered')));
    }

    public function test_a_bound_is_compared_only_as_its_field_s_type(): void
    {
        $engine = self::engine();

        $this->assertNull($engine->refusal(new SearchQuery(filters: ['price' => ['>=200000']])));
        $this->assertNull($engine->refusal(new SearchQuery(filters: ['registered' => ['>=2021-01-01']])));
        $this->assertNotNull($engine->refusal(new SearchQuery(filters: ['price' => ['>=2021-01-01']])));
        $this->assertNotNull($engine->refusal(new SearchQuery(filters: ['registered' => ['>=2021']])));
        $this->assertNotNull($engine->refusal(new SearchQuery(filters: ['price' => ['>=many']])));
    }

    public function test_a_value_none_of_the_declared_options_holds_goes_to_core(): void
    {
        $engine = self::engine();

        $this->assertNull($engine->refusal(new SearchQuery(filters: ['fuel' => ['El']])));
        $this->assertNotNull($engine->refusal(new SearchQuery(filters: ['fuel' => ['Diesel']])));
        $this->assertNull($engine->refusal(new SearchQuery(filters: ['gearbox' => ['Halvautomat']])));
    }

    public function test_leaving_out_a_value_keeps_a_row_with_none_only_where_the_key_takes_empty(): void
    {
        $engine = self::engine();

        $notOld = $engine->run(new SearchQuery(filters: ['year' => [':not', '2019']]), self::rows());
        $notElectric = $engine->run(new SearchQuery(filters: ['fuel' => [':not', 'El']]), self::rows());

        $this->assertSame([3], array_map(intval(...), $notOld->ids()));
        $this->assertSame([1, 3], array_map(intval(...), $notElectric->ids()));
    }
}
