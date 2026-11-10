<?php

namespace Inly\CoreWeb\Tests\Unit;

use Inly\CoreWeb\Search\LocalEngine;
use Inly\CoreWeb\Search\SearchQuery;
use Inly\CoreWeb\Search\SearchSchema;
use Inly\CoreWeb\Search\SearchText;
use Inly\CoreWeb\Tests\TestCase;

/**
 * Read against Core's search contract (docs/api/search-contract.md in
 * inly-core, copied to tests/fixtures): the local engine answers each
 * request the way Core answers it, and refuses each one only Core can.
 * When Core changes a rule, the copy is replaced and these say what to
 * catch up on.
 */
class SearchContractTest extends TestCase
{
    protected array $features = ['search'];

    /** @return array<string, mixed> */
    private static function contract(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../fixtures/core-search-contract.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function schema(): SearchSchema
    {
        return SearchSchema::fromDescribe(self::contract()['describe']);
    }

    public function test_it_reads_the_version_it_was_written_for(): void
    {
        $this->assertSame(1, self::contract()['version']);
    }

    public function test_it_folds_with_the_map_core_publishes(): void
    {
        $contract = self::contract();

        $this->assertSame($contract['accent_map'], SearchText::MAP);

        foreach ($contract['fold'] as [$text, $folded]) {
            $this->assertSame($folded, SearchText::fold($text), $text);
        }
    }

    public function test_the_local_engine_answers_every_case_core_answers_by_the_rules(): void
    {
        $contract = self::contract();
        $engine = new LocalEngine(self::schema());
        $answers = [];
        $expected = [];

        foreach ($contract['cases'] as $case) {
            $query = SearchQuery::fromApi($case['query']);

            if (self::onlyCoreRuns($case['query'])) {
                continue;
            }

            $this->assertNull($engine->refusal($query), $case['title']);

            $result = $engine->run($query, $contract['rows']);
            $ids = array_map(intval(...), $result->ids());

            // A sorted case pins the order; any other only which rows match.
            if (! isset($case['query']['sort'])) {
                sort($ids);
            }

            $answers[$case['title']] = array_filter(['ids' => $ids, 'facets' => isset($case['query']['facets']) ? $result->facets : null], fn ($value): bool => $value !== null);
            $expected[$case['title']] = array_filter(['ids' => $case['ids'], 'facets' => $case['facets'] ?? null], fn ($value): bool => $value !== null);
        }

        $this->assertSame($expected, $answers);
    }

    public function test_the_local_engine_refuses_every_case_only_core_runs(): void
    {
        $engine = new LocalEngine(self::schema());
        $refused = 0;

        foreach (self::contract()['cases'] as $case) {
            if (self::onlyCoreRuns($case['query'])) {
                $this->assertNotNull($engine->refusal(SearchQuery::fromApi($case['query'])), $case['title']);
                $refused++;
            }
        }

        $this->assertGreaterThan(0, $refused);
    }

    public function test_each_filter_follows_the_rule_the_contract_names_for_it(): void
    {
        $schema = self::schema();

        $this->assertSame([
            'name' => SearchSchema::PARTIAL,
            'make' => SearchSchema::EXACT,
            'city' => SearchSchema::EXACT,
            'fuel' => SearchSchema::EXACT,
            'gearbox' => SearchSchema::EXACT,
            'four_wheel_drive' => SearchSchema::BOOLEAN,
            'price' => SearchSchema::RANGE,
            'plate' => SearchSchema::PLATE,
            'affordable' => null,
            'id' => SearchSchema::RANGE,
        ], array_combine(
            $keys = self::contract()['describe']['filters_available'],
            array_map($schema->rule(...), $keys),
        ));
    }

    /** @param  array<string, mixed>  $query */
    private static function onlyCoreRuns(array $query): bool
    {
        $fields = self::contract()['describe']['fields'];

        foreach (array_keys((array) ($query['filter'] ?? [])) as $key) {
            foreach ($fields as $field) {
                if (($field['filter_key'] ?? null) === $key && ($field['filter_custom'] ?? false)) {
                    return true;
                }
            }
        }

        return false;
    }
}
