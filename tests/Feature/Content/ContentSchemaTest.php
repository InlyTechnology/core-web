<?php

namespace Inly\CoreWeb\Tests\Feature\Content;

use Illuminate\Support\Facades\File;
use Inly\CoreWeb\Drafts\ContentSchema;
use Inly\CoreWeb\Drafts\DescribesStoredValue;
use Inly\CoreWeb\Drafts\StatamicContentSchema;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Dictionaries\BasicDictionary;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** What Core AI builds a draft from, read from the blueprints and fieldsets of the draftable collections. */
class ContentSchemaTest extends TestCase
{
    use FakesCore;
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['content', 'drafts'];

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCore();
        $this->scratch = sys_get_temp_dir().'/core-web-schema-'.uniqid();
        Blueprint::setDirectory($this->scratch.'/blueprints');
        Fieldset::setDirectory($this->scratch.'/fieldsets');
        config(['core-web.drafts.collections' => ['pages' => ['blueprints' => ['page']]]]);
        SchemaTestBrands::register();

        Fieldset::make('block')->setContents(['fields' => [
            ['handle' => 'section_id', 'field' => ['type' => 'text']],
            ['handle' => 'background_color', 'field' => ['type' => 'color', 'swatches' => ['#ffffff', '#000000']]],
        ]])->save();
        Fieldset::make('hero')->setContents([
            'guide' => 'cms/blocks#hero',
            'ai_instructions' => 'One per page, first.',
            'fields' => [
                ['handle' => 'headline', 'field' => ['type' => 'text', 'validate' => ['required'], 'ai_instructions' => 'Short.']],
                ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'draft_validate' => ['dimensions:min_width=1200']]],
                ['handle' => 'show_cta', 'field' => ['type' => 'revealer']],
                ['handle' => 'cta', 'field' => ['type' => 'text', 'if' => ['show_cta' => 'true', 'headline' => 'not empty']]],
                ['handle' => 'brand', 'field' => ['type' => 'dictionary', 'dictionary' => 'schema_test_brands', 'max_items' => 1]],
                ['handle' => 'tone', 'field' => ['type' => 'select', 'options' => ['calm' => 'Calm', 'loud' => 'Loud']]],
                ['handle' => 'internal', 'field' => ['type' => 'text', 'visibility' => 'hidden']],
            ],
        ])->save();
        Fieldset::make('page_builder')->setContents(['guide_index' => 'cms/blocks', 'article_blocks' => ['hero'], 'page_blocks' => ['hero', 'faq'], 'fields' => []])->save();

        Taxonomy::make('tags')->sites(['default'])->save();
        Taxonomy::make('regions')->sites(['default'])->save();
        Term::make()->taxonomy('tags')->inDefaultLocale()->slug('news')->data(['title' => 'News'])->save();
        Term::make()->taxonomy('regions')->inDefaultLocale()->slug('north')->data(['title' => 'North'])->save();

        Collection::make('pages')->sites(['default'])->routes('/{slug}')->save();
        Collection::make('products')->sites(['default'])->routes('/products/{slug}')->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['tabs' => ['main' => ['sections' => [['display' => 'Content', 'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
            ['handle' => 'slug', 'field' => ['type' => 'slug']],
            ['handle' => 'intro', 'field' => ['type' => 'textarea', 'ai_fillable' => false]],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['bold'], 'sets' => ['main' => ['sets' => ['quote' => ['fields' => [['handle' => 'text', 'field' => ['type' => 'text']]]]]]]]],
            ['handle' => 'page_builder', 'field' => ['type' => 'replicator', 'sets' => [
                'heroes' => ['display' => 'Heroes', 'sets' => [
                    'hero' => ['display' => 'Hero', 'instructions' => 'The top of a page.', 'fields' => [['import' => 'block'], ['import' => 'hero']]],
                ]],
                'text' => ['display' => 'Text', 'sets' => [
                    'faq' => ['display' => 'FAQ', 'fields' => [['import' => 'block'],
                        ['handle' => 'tag', 'field' => ['type' => 'terms', 'taxonomies' => ['tags'], 'max_items' => 1]],
                        ['handle' => 'places', 'field' => ['type' => 'terms', 'taxonomies' => ['tags', 'regions']]],
                        ['handle' => 'items', 'field' => ['type' => 'grid', 'fields' => [
                            ['handle' => 'question', 'field' => ['type' => 'text', 'validate' => ['required']]],
                            ['handle' => 'nested', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['note' => ['fields' => [['handle' => 'note', 'field' => ['type' => 'text']]]]]]]]],
                        ]]]]],
                    'retired' => ['display' => 'Retired', 'hide' => true, 'fields' => [['handle' => 'x', 'field' => ['type' => 'text']]]],
                ]],
            ]]],
        ]]]]]])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $data */
    private function page(array $data, bool $published = true): EntryContract
    {
        $entry = Entry::make()->collection('pages')->blueprint('page')->locale('default')->published($published)->slug(uniqid('p'))->data(['title' => 'A page', ...$data]);
        $entry->save();

        return $entry;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private static function field(array $fields, string $handle): array
    {
        return collect($fields)->firstOrFail(fn (array $field) => $field['handle'] === $handle);
    }

    public function test_the_draftable_collections_blocks_and_entry_fields_are_read_from_the_blueprints(): void
    {
        $schema = app(ContentSchema::class)->describe();

        $this->assertInstanceOf(StatamicContentSchema::class, app(ContentSchema::class));
        $this->assertSame(['pages'], array_column($schema['collections'], 'handle'));
        $this->assertSame(['path' => 'cms/blocks', 'anchor' => null], $schema['guide_index']);
        $this->assertSame(['hero'], $schema['article_blocks']);
        $this->assertSame(['hero', 'faq'], $schema['page_blocks']);

        $pages = $schema['collections'][0];
        $this->assertSame(['field' => 'page_builder', 'type' => 'replicator', 'blocks' => ['hero', 'faq']], $pages['blueprints'][0]['builders'][0]);
        $this->assertSame(['hero', 'faq'], array_column($pages['blocks'], 'handle'), 'a set hidden from the picker is no block');

        $hero = $pages['blocks'][0];
        $this->assertSame(['handle' => 'heroes', 'display' => 'Heroes'], $hero['group']);
        $this->assertSame('One per page, first.', $hero['ai_instructions']);
        $this->assertSame(['path' => 'cms/blocks', 'anchor' => 'hero'], $hero['guide']);
        $this->assertSame(['headline' => 'text', 'image' => 'assets', 'cta' => 'text', 'brand' => 'dictionary', 'tone' => 'select'], $hero['fields']);
        $this->assertSame(['headline'], $hero['required']);
        $this->assertSame(['section_id', 'background_color'], $hero['settings']);

        $entryFields = $pages['blueprints'][0]['entry_fields'];
        $this->assertSame(['title', 'slug', 'intro', 'body'], array_column($entryFields, 'handle'), 'the builder is listed as blocks, not as an entry field');
        $this->assertSame(['main', 'Content'], [$entryFields[0]['tab'], $entryFields[0]['section']]);
        $this->assertStringContainsString('unique in the collection', self::field($entryFields, 'slug')['rules']);
        $this->assertFalse(self::field($entryFields, 'intro')['ai_fillable']);
        $this->assertSame('quote', self::field($entryFields, 'body')['sets'][0]['handle']);
        $this->assertSame(['bold'], self::field($entryFields, 'body')['toolbar']);
    }

    public function test_a_block_in_full_says_how_each_value_is_written_and_when_it_applies(): void
    {
        $hero = app(ContentSchema::class)->block('pages', 'hero')['data'];

        $this->assertSame('pages', $hero['collection']);
        $this->assertSame([['blueprint' => 'page', 'field' => 'page_builder']], $hero['builders']);

        $headline = self::field($hero['fields'], 'headline');
        $this->assertTrue($headline['required']);
        $this->assertSame('Short.', $headline['ai_instructions']);
        $this->assertSame('string', $headline['value']);

        $image = self::field($hero['fields'], 'image');
        $this->assertSame(['dimensions:min_width=1200'], $image['validate'], 'a rule only a draft is held to reads like any other');
        $this->assertSame(['container' => 'assets', 'images' => true], $image['assets']);
        $this->assertStringEndsWith('; one value', $image['value']);

        $this->assertSame(['if' => ['headline' => 'not empty']], self::field($hero['fields'], 'cta')['conditions'], 'a revealer only folds the form');
        $this->assertSame(['calm' => 'Calm', 'loud' => 'Loud'], self::field($hero['fields'], 'tone')['options']);
        $this->assertEquals(['handle' => 'schema_test_brands', 'core_type' => 'manufacturer', 'example' => 'audi|b9ce', 'value_format' => '{brand slug}|{public_id}'], self::field($hero['fields'], 'brand')['dictionary']);
        $this->assertSame(['#ffffff', '#000000'], self::field($hero['settings'], 'background_color')['swatches']);
        $this->assertNotContains('internal', array_column($hero['fields'], 'handle'), 'a hidden field is never filled');

        $faq = app(ContentSchema::class)->block('pages', 'faq')['data'];
        $items = self::field($faq['fields'], 'items');
        $this->assertSame(['question', 'nested'], array_column($items['fields'], 'handle'));
        $this->assertSame('note', $items['fields'][1]['sets'][0]['handle']);

        $this->assertSame('term slug; one value', self::field($faq['fields'], 'tag')['value']);
        $this->assertSame([['taxonomy' => 'tags', 'slug' => 'news', 'title' => 'News']], self::field($faq['fields'], 'tag')['terms']);
        $this->assertSame('array: `{taxonomy}::{term slug}` each', self::field($faq['fields'], 'places')['value'], 'a field of several taxonomies stores the taxonomy with the slug');
        $this->assertSame(['north', 'news'], array_column(self::field($faq['fields'], 'places')['terms'], 'slug'));

        $this->assertNull(app(ContentSchema::class)->block('pages', 'retired'));
        $this->assertNull(app(ContentSchema::class)->block('products', 'hero'), 'a collection nobody drafts has no blocks');
    }

    public function test_a_site_describes_its_own_fieldtypes_and_chrome_in_config(): void
    {
        config(['core-web.drafts.schema' => ['settings_fieldset' => null, 'guide_fieldset' => null, 'value_formats' => ['text' => 'plain text, no HTML']]]);

        $schema = app(ContentSchema::class)->describe('pages');
        $hero = $schema['collections'][0]['blocks'][0];

        $this->assertNull($schema['guide_index']);
        $this->assertSame([], $schema['page_blocks']);
        $this->assertSame([], $hero['settings']);
        $this->assertArrayHasKey('section_id', $hero['fields']);
        $this->assertSame('plain text, no HTML', self::field(app(ContentSchema::class)->block('pages', 'hero')['data']['fields'], 'headline')['value']);
    }

    public function test_usage_counts_the_entries_that_use_a_block_at_any_depth_and_skips_disabled_sets(): void
    {
        $this->page(['page_builder' => [['id' => 'a', 'type' => 'hero', 'enabled' => true, 'headline' => 'Hi']]], published: false);
        $this->page(['page_builder' => [
            ['id' => 'b', 'type' => 'hero', 'enabled' => true, 'headline' => 'Hi'],
            ['id' => 'c', 'type' => 'faq', 'enabled' => false],
            ['id' => 'd', 'type' => 'faq', 'items' => [['id' => 'r', 'question' => 'Q', 'nested' => [['id' => 'n', 'type' => 'note', 'note' => 'x']]]]],
        ]]);
        $this->page(['page_builder' => [['id' => 'e', 'type' => 'faq', 'enabled' => false]]]);
        $this->page(['body' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ['type' => 'set', 'attrs' => ['id' => 'q1', 'enabled' => true, 'values' => ['type' => 'quote', 'text' => 'x']]],
            ['type' => 'set', 'attrs' => ['id' => 'q2', 'enabled' => false, 'values' => ['type' => 'quote', 'text' => 'y']]],
        ]]);
        $this->page(['body' => [['type' => 'set', 'attrs' => ['id' => 'q3', 'enabled' => false, 'values' => ['type' => 'quote', 'text' => 'z']]]]]);

        $pages = app(ContentSchema::class)->describe('pages')['collections'][0];
        $usage = array_column($pages['blocks'], 'usage', 'handle');

        $this->assertSame(2, $usage['hero']['entries']);
        $this->assertEquals((object) ['default' => 2], $usage['hero']['by_site']);
        $this->assertSame(['published', 'draft'], array_column($usage['hero']['examples'], 'status'), 'published entries first');
        $this->assertSame(1, $usage['faq']['entries'], 'a disabled set counts for nothing');

        $faq = app(ContentSchema::class)->block('pages', 'faq')['data'];
        $this->assertSame(1, $faq['usage']['entries']);

        $counted = (new \ReflectionMethod(StatamicContentSchema::class, 'usage'))->invoke(app(ContentSchema::class), 'pages');
        $this->assertSame(1, $counted['quote']['entries'], 'a Bard set counts, a disabled one does not');
        $this->assertSame(1, $counted['note']['entries'], 'a set nested in a grid row counts');
    }

    public function test_the_hash_moves_with_the_blueprints_and_not_with_content(): void
    {
        $hash = app(ContentSchema::class)->hash();

        $this->page(['page_builder' => [['id' => 'a', 'type' => 'hero', 'headline' => 'Hi']]]);
        $this->assertSame($hash, app(ContentSchema::class)->hash());
        $this->assertSame($hash, app(ContentSchema::class)->describe()['catalog_hash']);

        $fieldset = Fieldset::find('hero');
        $fieldset->setContents([...$fieldset->contents(), 'ai_instructions' => 'Two per page.'])->save();
        Blink::flush();

        $this->assertNotSame($hash, app(ContentSchema::class)->hash());
    }

    public function test_core_reads_the_schema_sealed_for_reading(): void
    {
        $this->callSealed('GET', '/api/core/v1/blocks', 'website.read')
            ->assertOk()
            ->assertJsonPath('catalog_hash', app(ContentSchema::class)->hash())
            ->assertJsonPath('collections.0.handle', 'pages');

        $this->callSealed('GET', '/api/core/v1/blocks?collection=pages', 'website.read')->assertOk()->assertJsonCount(1, 'collections');
        $this->callSealed('GET', '/api/core/v1/blocks?collection=products', 'website.read')->assertUnprocessable();

        $this->callSealed('GET', '/api/core/v1/blocks/pages/hero', 'website.read')
            ->assertOk()
            ->assertJsonPath('data.handle', 'hero')
            ->assertJsonPath('data.usage.entries', 0);
        $this->callSealed('GET', '/api/core/v1/blocks/pages/nope', 'website.read')->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->get('/api/core/v1/blocks')->assertForbidden();
    }

    public function test_a_site_binds_its_own_schema(): void
    {
        $this->app->bind(ContentSchema::class, SchemaTestHidingSchema::class);
        $this->page(['page_builder' => [['id' => 'a', 'type' => 'hero', 'headline' => 'Hi']]]);

        $this->assertSame('expired', app(ContentSchema::class)->block('pages', 'hero')['data']['usage']['examples'][0]['status']);
    }
}

class SchemaTestBrands extends BasicDictionary implements DescribesStoredValue
{
    protected function getItems(): array
    {
        return [['value' => 'audi|b9ce', 'label' => 'Audi']];
    }

    public function storedValue(): array
    {
        return ['format' => '{brand slug}|{public_id}', 'example' => 'audi|b9ce', 'core_type' => 'manufacturer'];
    }
}

class SchemaTestHidingSchema extends StatamicContentSchema
{
    protected function status(EntryContract $entry): string
    {
        return 'expired';
    }
}
