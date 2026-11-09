<?php

namespace Inly\CoreWeb\Tests\Unit;

use Inly\CoreWeb\Search\SearchQuery;
use Inly\CoreWeb\Search\SearchSchema;
use Inly\CoreWeb\Tests\TestCase;

/** A search read from a page address and written back, against the contract's description. */
class SearchQueryTest extends TestCase
{
    private static function schema(): SearchSchema
    {
        $contract = json_decode((string) file_get_contents(__DIR__.'/../fixtures/core-search-contract.json'), true, flags: JSON_THROW_ON_ERROR);

        return SearchSchema::fromDescribe($contract['describe']);
    }

    public function test_a_page_address_keeps_only_the_filters_and_sorts_the_object_offers(): void
    {
        $query = SearchQuery::fromUrl([
            'q' => '  elbil volvo ',
            'make' => 'Volvo,Škoda',
            'colour' => 'red',
            '_segment' => '4',
            'sort' => '-price,name',
            'page' => '3',
        ], self::schema());

        $this->assertSame('elbil volvo', $query->term);
        $this->assertSame(['make' => ['Volvo', 'Škoda']], $query->filters);
        $this->assertSame('-price,name', $query->sort);
        $this->assertSame(3, $query->page);
        $this->assertNull(SearchQuery::fromUrl(['sort' => 'price,secret'], self::schema())->sort);
    }

    public function test_a_token_is_kept_only_where_the_key_takes_it(): void
    {
        $query = SearchQuery::fromUrl(['gearbox' => ':empty,Automat', 'name' => ':empty', 'fuel' => ':not,El', 'price_min' => ':empty'], self::schema());

        $this->assertSame(['gearbox' => [':empty', 'Automat']], $query->filters);
    }

    public function test_bounds_are_read_from_min_and_max_and_must_be_numbers(): void
    {
        $query = SearchQuery::fromUrl(['price_min' => '200000', 'price_max' => '400000', 'id' => 'abc'], self::schema());

        $this->assertSame(['price' => ['>=200000', '<=400000']], $query->filters);
        $this->assertSame(['price_max' => '400000', 'price_min' => '200000'], $query->toUrl(self::schema()));
    }

    public function test_a_value_in_use_holding_a_comma_stays_whole_in_a_list(): void
    {
        $schema = self::schema();
        $query = SearchQuery::fromUrl(['make' => ['Acme, Inc', 'Volvo'], 'fuel' => ['El,Diesel']], $schema);

        $this->assertSame(['make' => ['Acme, Inc', 'Volvo'], 'fuel' => ['El', 'Diesel']], $query->filters);
        $this->assertSame(['fuel' => 'El,Diesel', 'make' => ['Acme, Inc', 'Volvo']], $query->toUrl($schema));
        $this->assertEquals($query->filters, SearchQuery::fromUrl($query->toUrl($schema), $schema)->filters);
    }

    public function test_a_page_address_bounds_how_much_it_asks(): void
    {
        $query = SearchQuery::fromUrl(['make' => implode(',', range(1, 50)), 'name' => str_repeat('x', 101)], self::schema());

        $this->assertCount(SearchQuery::MAX_URL_VALUES, $query->filters['make']);
        $this->assertArrayNotHasKey('name', $query->filters);
    }

    public function test_a_page_address_asks_for_a_page_that_exists(): void
    {
        foreach (['99999999999999999999', '1e30', '-3', '2.5', 'abc'] as $page) {
            $this->assertContains(SearchQuery::fromUrl(['page' => $page], self::schema())->page, [1, SearchQuery::MAX_PAGE], $page);
        }

        $this->assertSame(SearchQuery::MAX_PAGE, SearchQuery::fromUrl(['page' => '99999999'], self::schema())->page);
        $this->assertSame(1, SearchQuery::fromUrl(['page' => ['2']], self::schema())->page);
        $this->assertSame(1, (new SearchQuery)->withPage(1, 0)->pageSize);
    }

    public function test_a_bound_is_a_number_or_a_date_and_empty_stays_where_the_key_takes_it(): void
    {
        $schema = self::schema();

        $this->assertSame([], SearchQuery::fromUrl(['price_min' => '1.2.3', 'price_max' => '2026-1-5'], $schema)->filters);
        $this->assertSame(['price' => ['>=2026-01-05']], SearchQuery::fromUrl(['price_min' => '2026-01-05'], $schema)->filters);
        $this->assertSame(['price' => [':empty']], SearchQuery::fromUrl(['price' => ':empty'], $schema)->filters);
        $this->assertSame(['price' => ['200000']], SearchQuery::fromUrl(['price' => '200000'], $schema)->filters);
    }

    public function test_a_value_none_of_the_declared_options_holds_is_dropped(): void
    {
        $query = SearchQuery::fromUrl(['fuel' => 'El,Vätgas', 'gearbox' => 'automat', 'make' => 'Lada'], self::schema());

        $this->assertSame(['fuel' => ['El'], 'make' => ['Lada']], $query->filters);
    }

    public function test_the_api_parameters_carry_the_term_filters_sort_page_and_facets(): void
    {
        $query = (new SearchQuery(term: 'volvo', filters: ['make' => ['Volvo']], sort: '-price', page: 2, pageSize: 10))->withFacets(['make', 'fuel', 'make']);

        $this->assertSame([
            'filter' => ['_search' => 'volvo', 'make' => ['Volvo']],
            'sort' => '-price',
            'page' => ['number' => 2, 'size' => 10],
            'facets' => 'make,fuel',
        ], $query->toApi());
        $this->assertEquals($query, SearchQuery::fromApi($query->toApi()));
    }

    public function test_the_same_search_has_one_key_whatever_order_it_came_in(): void
    {
        $a = new SearchQuery(filters: ['make' => ['Volvo', 'Škoda'], 'fuel' => ['El']]);
        $b = new SearchQuery(filters: ['fuel' => ['El'], 'make' => ['Škoda', 'Volvo']]);

        $this->assertSame($a->key(), $b->key());
        $this->assertNotSame($a->key(), $a->withPage(2)->key());
        $this->assertSame($a->withFacets(['make', 'fuel'])->key(), $a->withFacets(['fuel', 'make'])->key());
        $this->assertNotSame(
            (new SearchQuery(filters: ['make' => [':not', 'Volvo']]))->key(),
            (new SearchQuery(filters: ['make' => ['Volvo', ':not']]))->key(),
        );
    }

    public function test_a_change_to_the_search_starts_again_on_its_first_page(): void
    {
        $query = new SearchQuery(page: 4);

        $this->assertSame(1, $query->withFilter('make', 'Volvo')->page);
        $this->assertSame(1, $query->withTerm('volvo')->page);
        $this->assertSame(1, $query->withSort('-price')->page);
        $this->assertSame([], $query->withFilter('make', 'Volvo')->withFilter('make', [])->filters);
    }
}
