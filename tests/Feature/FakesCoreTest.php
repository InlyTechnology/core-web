<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;

/** The fake Core answers a sparse fieldset and compact row meta as Core does, so a site can test what its pulls ask for. */
class FakesCoreTest extends TestCase
{
    use FakesCore;

    private const MODEL = ['type' => 'vehicle-model', 'id' => '1', 'attributes' => ['title' => 'Q3', 'description' => 'SUV', 'sort_rank' => 'a'], 'relationships' => ['manufacturer' => ['data' => ['type' => 'manufacturer', 'id' => '2']]]];

    private const BRAND = ['type' => 'manufacturer', 'id' => '2', 'attributes' => ['slug' => 'audi', 'name' => 'Audi', 'web_slug' => 'audi']];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCore([
            'core.test/api/v1/objects/vehicle-model/*' => Http::response(['data' => self::MODEL, 'included' => [self::BRAND]]),
            'core.test/api/v1/objects/vehicle-model*' => self::coreList([self::MODEL], [self::BRAND]),
        ]);
        $this->answerSparseFieldsets(['manufacturer' => 'manufacturers']);
    }

    public function test_a_sparse_fieldset_narrows_the_objects_and_the_included_ones(): void
    {
        $core = CoreClient::fromConfig();

        $listed = iterator_to_array($core->objects('vehicle-model', 25, ['fields[_]' => 'title,sort_rank', 'include' => 'manufacturer', 'fields[manufacturers]' => 'slug']), false);
        $read = $core->object('vehicle-model', '1', ['fields[_]' => 'title']);

        $this->assertSame(['title' => 'Q3', 'sort_rank' => 'a'], $listed[0]['data']['attributes']);
        $this->assertSame(['slug' => 'audi'], $listed[0]['included'][0]['attributes']);
        $this->assertSame(self::MODEL['relationships'], $listed[0]['data']['relationships'], 'relationships are not fields');
        $this->assertSame(['title' => 'Q3'], $read['data']['attributes']);
        $this->assertSame(self::BRAND['attributes'], $read['included'][0]['attributes'], 'no fieldset for the table: the whole resource');
    }

    public function test_a_request_without_a_fieldset_gets_the_whole_answer(): void
    {
        $listed = iterator_to_array(CoreClient::fromConfig()->objects('vehicle-model'), false);

        $this->assertSame(self::MODEL['attributes'], $listed[0]['data']['attributes']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/vehicle-model') && self::requestedFieldsets($request) === []);
    }

    public function test_compact_row_meta_drops_the_schema_and_the_object_meta_and_keeps_the_identity(): void
    {
        $model = [...self::MODEL, 'meta' => ['id' => '1', 'updated_at' => '2026-10-01T10:00:00Z']];
        $this->fakeCore([
            'core.test/api/v1/objects/vehicle-model/*' => Http::response(['data' => $model, 'included' => [self::BRAND]]),
            'core.test/api/v1/objects/vehicle-model*' => self::coreList([$model], [self::BRAND]),
        ]);
        $this->answerRowMeta();
        $core = CoreClient::fromConfig();

        $compact = iterator_to_array($core->objects('vehicle-model'), false)[0];
        $full = $core->object('vehicle-model', '1', ['meta' => 'full']);

        $this->assertSame(['id' => '1', 'updated_at' => '2026-10-01T10:00:00Z'], $compact['data']['meta']);
        $this->assertArrayNotHasKey('schema', $compact['included'][0]['meta']);
        $this->assertSame(self::MODEL['attributes'], $compact['data']['attributes']);
        $this->assertSame(['value' => 'Q3', 'formatted' => 'Q3'], array_intersect_key($full['data']['meta']['schema']['title'], ['value' => 1, 'formatted' => 1]));
        $this->assertSame('vehicle-model', $full['data']['meta']['object']['lookup_key']);
        $this->assertSame('2026-10-01T10:00:00Z', $full['data']['meta']['updated_at']);
        $this->assertArrayHasKey('schema', $full['included'][0]['meta']);
    }

    public function test_a_fake_core_before_9_answers_full_row_meta_whatever_it_is_asked(): void
    {
        $this->answerRowMeta();
        $this->fakeCoreCompactsMeta = false;

        $listed = iterator_to_array(CoreClient::fromConfig()->objects('vehicle-model'), false)[0];

        $this->assertArrayHasKey('schema', $listed['data']['meta']);
        $this->assertArrayHasKey('object', $listed['data']['meta']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/vehicle-model') && self::requestedRowMeta($request) === 'compact');
    }
}
