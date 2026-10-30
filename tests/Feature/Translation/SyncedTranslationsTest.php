<?php

namespace Inly\CoreWeb\Tests\Feature\Translation;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Inly\CoreWeb\Checks\ContentChecks;
use Inly\CoreWeb\Tests\TestCase;
use Inly\CoreWeb\Translation\Alignment;
use Inly\CoreWeb\Translation\SyncedTranslations;
use Inly\CoreWeb\Translation\TranslationBlueprint;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class SyncedTranslationsTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['translation', 'checks', 'drafts'];

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/core-web-translation-'.uniqid();
        Blueprint::setDirectory($this->scratch.'/blueprints');
        config(['core-web.translation.sites' => ['other' => 'synced']]);

        Collection::make('pages')->sites(['default', 'other'])->routes('/{slug}')->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'page_builder', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'hero' => ['fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'background', 'field' => ['type' => 'select', 'options' => ['light', 'dark']]],
                    ['handle' => 'code', 'field' => ['type' => 'text', 'translate' => false]],
                ]],
                'cards' => ['fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'items', 'field' => ['type' => 'grid', 'fields' => [
                        ['handle' => 'title', 'field' => ['type' => 'text']],
                        ['handle' => 'icon', 'field' => ['type' => 'select', 'options' => ['heart', 'leaf']]],
                    ]]],
                ]],
            ]]]]],
        ]])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function blocks(string $heading = 'Familjeföretaget', string $card = 'Engagemang'): array
    {
        return [
            ['id' => 'hero', 'type' => 'hero', 'enabled' => true, 'heading' => $heading, 'background' => 'dark', 'code' => 'H1'],
            ['id' => 'vision', 'type' => 'cards', 'enabled' => true, 'heading' => 'Vår vision', 'items' => [
                ['id' => 'v1', 'title' => $card, 'icon' => 'heart'],
                ['id' => 'v2', 'title' => 'Respekt', 'icon' => 'leaf'],
            ]],
        ];
    }

    /** @return array{0: EntryContract, 1: EntryContract} */
    private function pageWithTranslation(?array $translated = null, array $data = []): array
    {
        $origin = Entry::make()->collection('pages')->locale('default')->slug('om')->data(['title' => 'Om oss', 'page_builder' => $this->blocks()]);
        $origin->save();

        $translation = $origin->makeLocalization('other')->data(array_filter(['title' => 'About us', 'page_builder' => $translated, ...$data], fn ($value) => $value !== null));
        $translation->save();

        return [Entry::find($origin->id()), Entry::find($translation->id())];
    }

    /** @return list<array<string, mixed>> */
    private function english(): array
    {
        $blocks = $this->blocks('The family business', 'Commitment');
        $blocks[1]['heading'] = 'Our vision';
        $blocks[1]['items'][1]['title'] = 'Respect';

        return $blocks;
    }

    public function test_an_original_save_carries_its_blocks_and_shared_values_while_the_translation_keeps_its_texts(): void
    {
        [$origin, $translation] = $this->pageWithTranslation($this->english());

        $blocks = $this->blocks();
        $blocks[0]['background'] = 'light';
        $blocks[0]['code'] = 'H2';
        $blocks[1]['items'] = array_reverse($blocks[1]['items']);
        $blocks = array_reverse($blocks);
        $blocks[] = ['id' => 'news', 'type' => 'hero', 'enabled' => true, 'heading' => 'Nyheter', 'background' => 'light'];
        $origin->set('page_builder', $blocks)->save();

        $aligned = Entry::find($translation->id())->get('page_builder');

        $this->assertSame(['vision', 'hero', 'news'], array_column($aligned, 'id'));
        $this->assertSame('The family business', $aligned[1]['heading']);
        $this->assertSame('light', $aligned[1]['background']);
        $this->assertSame('H2', $aligned[1]['code'], 'A field marked translate: false follows the original.');
        $this->assertSame(['Respect', 'Commitment'], array_column($aligned[0]['items'], 'title'));
        $this->assertSame('Nyheter', $aligned[2]['heading'], 'A new block arrives with the original\'s text.');
    }

    public function test_a_block_removed_or_retyped_in_the_original_leaves_the_translation(): void
    {
        [$origin, $translation] = $this->pageWithTranslation($this->english());

        $blocks = $this->blocks();
        $blocks[0]['type'] = 'cards';
        unset($blocks[1]);
        $origin->set('page_builder', array_values($blocks))->save();

        $aligned = Entry::find($translation->id())->get('page_builder');

        $this->assertCount(1, $aligned);
        $this->assertSame('Familjeföretaget', $aligned[0]['heading'], 'A block of another type keeps none of the old block\'s texts.');
    }

    public function test_a_translation_that_keeps_its_own_blocks_is_left_alone(): void
    {
        [$origin, $translation] = $this->pageWithTranslation([['id' => 'only', 'type' => 'hero', 'heading' => 'Own']], [SyncedTranslations::FIELD => false]);

        $origin->set('page_builder', $this->blocks('Ny rubrik'))->save();

        $this->assertSame([['id' => 'only', 'type' => 'hero', 'heading' => 'Own']], Entry::find($translation->id())->get('page_builder'));
    }

    public function test_a_site_left_out_keeps_its_own_blocks_unless_the_translation_follows(): void
    {
        config(['core-web.translation.sites' => []]);
        [$origin, $translation] = $this->pageWithTranslation([['id' => 'only', 'type' => 'hero', 'heading' => 'Own']]);

        $origin->save();
        $this->assertSame('only', Entry::find($translation->id())->get('page_builder')[0]['id']);

        Entry::find($translation->id())->set(SyncedTranslations::FIELD, true)->save();
        $this->assertSame(['hero', 'vision'], array_column(Entry::find($translation->id())->get('page_builder'), 'id'));
    }

    public function test_a_text_changed_in_the_original_shows_as_outdated_until_it_is_translated_again(): void
    {
        [$origin, $translation] = $this->pageWithTranslation($this->english());

        $this->assertSame(['drift' => [], 'outdated' => [], 'untranslated' => []], SyncedTranslations::status($translation));

        $origin->set('page_builder', $this->blocks('Familjeföretaget i Norrland'))->save();
        $translation = Entry::find($translation->id());

        $this->assertSame(['page_builder.hero.heading'], SyncedTranslations::status($translation)['outdated']);

        $blocks = $translation->get('page_builder');
        $blocks[0]['heading'] = 'The family business in Norrland';
        $translation->set('page_builder', $blocks)->save();

        $this->assertSame([], SyncedTranslations::status(Entry::find($translation->id()))['outdated']);
    }

    public function test_texts_still_in_the_original_language_and_inherited_blocks_are_untranslated(): void
    {
        [, $partial] = $this->pageWithTranslation(array_replace($this->blocks(), [0 => $this->english()[0]]));
        $this->assertSame(['page_builder.vision.heading', 'page_builder.vision.items.v1.title', 'page_builder.vision.items.v2.title'], SyncedTranslations::status($partial)['untranslated']);

        $inherits = Entry::find($partial->id())->remove('page_builder');
        $inherits->save();
        $this->assertCount(4, SyncedTranslations::status(Entry::find($partial->id()))['untranslated']);
    }

    public function test_the_control_panel_refuses_new_blocks_while_the_translation_follows(): void
    {
        [$origin, $translation] = $this->pageWithTranslation($this->english());
        $rule = TranslationBlueprint::RULE.':'.$translation->id().',page_builder';

        $posted = fn (array $blocks, array $extra = []) => Validator::make(['page_builder' => $blocks, ...$extra], ['page_builder' => [$rule]]);
        $cp = array_map(fn (array $block) => ['_id' => $block['id'], ...array_diff_key($block, ['id' => 1])], $this->english());

        $this->assertTrue($posted($cp)->passes(), 'The control panel posts row ids as _id.');
        $this->assertTrue($posted(array_map(fn ($block) => ['heading' => 'Changed', ...$block], $cp))->passes(), 'Texts are the translation\'s own.');
        $this->assertTrue($posted([...$cp, ['_id' => 'new', 'type' => 'hero']])->fails());
        $this->assertSame('This page follows the original\'s blocks. Switch off "Follow the original\'s blocks" to add, remove or move blocks here.', $posted(array_reverse($cp))->errors()->first('page_builder'));
        $this->assertTrue($posted(array_reverse($cp), [SyncedTranslations::FIELD => false])->passes(), 'The same save may switch to own blocks.');
    }

    public function test_the_control_panel_shows_the_toggle_with_where_the_translation_stands(): void
    {
        [$origin, $translation] = $this->pageWithTranslation($this->english());
        $origin->set('page_builder', $this->blocks('Ny'))->save();
        $translation = Entry::find($translation->id());
        $this->app->instance('request', Request::create('/cp/collections/pages/entries/'.$translation->id()));

        $blueprint = Blueprint::find('collections.pages.page');
        (new TranslationBlueprint)->handle(new EntryBlueprintFound($blueprint, $translation));

        $toggle = $blueprint->field(SyncedTranslations::FIELD);
        $this->assertSame('toggle', $toggle->type());
        $this->assertTrue($toggle->config()['default']);
        $this->assertStringContainsString('1 text changed in the original since it was translated.', $toggle->config()['instructions']);
        $this->assertContains(TranslationBlueprint::RULE.':{id},page_builder', $blueprint->field('page_builder')->config()['validate']);
        $this->assertSame(['page_builder' => [['id' => 'hero', 'type' => 'hero'], ['id' => 'vision', 'type' => 'cards', 'children' => ['items' => [['id' => 'v1'], ['id' => 'v2']]]]]], json_decode($blueprint->field(SyncedTranslations::SEEN)->defaultValue(), true));

        (new TranslationBlueprint)->handle(new EntryBlueprintFound($blueprint, $translation));
        $this->assertCount(1, array_keys($blueprint->field('page_builder')->config()['validate'], TranslationBlueprint::RULE.':{id},page_builder'), 'The rule is added once, whichever translation resolves the blueprint.');
    }

    public function test_an_original_gets_no_toggle(): void
    {
        [$origin] = $this->pageWithTranslation($this->english());
        $this->app->instance('request', Request::create('/cp/collections/pages/entries/'.$origin->id()));

        $blueprint = Blueprint::find('collections.pages.page');
        (new TranslationBlueprint)->handle(new EntryBlueprintFound($blueprint, $origin));

        $this->assertNull($blueprint->field(SyncedTranslations::FIELD));
    }

    public function test_the_check_finds_drifted_blocks_and_texts_the_original_changed(): void
    {
        [$origin, $translation] = $this->pageWithTranslation($this->english());
        $origin->set('page_builder', $this->blocks('Ny'))->save();
        Entry::find($translation->id())->set('page_builder', array_reverse($this->english()))->saveQuietly();

        $findings = collect(app(ContentChecks::class)->run(app(ContentChecks::class)->get('translation-sync'))['findings'])->keyBy('path');

        $this->assertSame(['page_builder', 'outdated'], $findings->keys()->all());
        $this->assertSame('entry:'.$translation->id(), $findings['page_builder']['subject']);
        $this->assertSame(['page_builder.hero.heading'], $findings['outdated']['details']['found']);
        $this->assertFalse($findings['outdated']['fixable']);
    }

    public function test_a_control_panel_save_keeps_what_each_text_was_translated_from(): void
    {
        [$origin, $translation] = $this->pageWithTranslation($this->english());
        $origin->set('page_builder', $this->blocks('Ny rubrik'))->save();

        $translation = Entry::find($translation->id());
        $this->app->instance('request', Request::create('/cp/collections/pages/entries/'.$translation->id()));
        (new TranslationBlueprint)->handle(new EntryBlueprintFound(Blueprint::find('collections.pages.page'), $translation));
        $translation->data($translation->data()->except(SyncedTranslations::SOURCES)->put('title', 'About'))->save();

        $this->assertSame(['page_builder.hero.heading'], SyncedTranslations::status(Entry::find($translation->id()))['outdated']);
    }

    public function test_rows_saved_without_ids_keep_their_texts_once_the_original_gets_ids(): void
    {
        $strip = fn (array $blocks) => array_map(fn (array $block) => array_diff_key($block, ['id' => 1]), $blocks);
        $origin = Entry::make()->collection('pages')->locale('default')->slug('om')->data(['page_builder' => $strip($this->blocks())]);
        $origin->save();
        $translation = $origin->makeLocalization('other')->data(['page_builder' => $strip($this->english())]);
        $translation->save();

        Entry::find($origin->id())->set('page_builder', $this->blocks())->save();
        $aligned = Entry::find($translation->id())->get('page_builder');

        $this->assertSame(['hero', 'vision'], array_column($aligned, 'id'));
        $this->assertSame('The family business', $aligned[0]['heading']);

        $rule = TranslationBlueprint::RULE.':'.$translation->id().',page_builder';
        $seen = json_encode(['page_builder' => [['id' => '#0', 'type' => 'hero'], ['id' => '#1', 'type' => 'cards', 'children' => ['items' => [['id' => '#0'], ['id' => '#1']]]]]]);
        $cp = array_map(fn (array $block) => ['_id' => 'new-'.$block['type'], ...$block], $strip($this->english()));

        $this->assertTrue(Validator::make(['page_builder' => $cp, SyncedTranslations::SEEN => $seen], ['page_builder' => [$rule]])->passes(), 'A form opened on rows without ids posts new ids for them.');
    }

    public function test_a_text_cleared_in_the_translation_stays_cleared(): void
    {
        [, $translation] = $this->pageWithTranslation($this->english());

        $blocks = $translation->get('page_builder');
        unset($blocks[1]['heading']);
        $translation->set('page_builder', $blocks)->save();

        $this->assertArrayNotHasKey('heading', Entry::find($translation->id())->get('page_builder')[1]);
    }

    public function test_a_form_opened_before_the_original_changed_its_blocks_still_saves(): void
    {
        [$origin, $translation] = $this->pageWithTranslation($this->english());
        $seen = json_encode(['page_builder' => Alignment::structure($this->english(), SyncedTranslations::fields($translation)['page_builder'])]);

        $origin->set('page_builder', [...$this->blocks(), ['id' => 'news', 'type' => 'hero', 'heading' => 'Nyheter']])->save();

        $rule = TranslationBlueprint::RULE.':'.$translation->id().',page_builder';
        $cp = array_map(fn (array $block) => ['_id' => $block['id'], ...array_diff_key($block, ['id' => 1])], $this->english());

        $this->assertTrue(Validator::make(['page_builder' => $cp, SyncedTranslations::SEEN => $seen], ['page_builder' => [$rule]])->passes());
        $this->assertTrue(Validator::make(['page_builder' => [...$cp, ['_id' => 'mine', 'type' => 'hero']], SyncedTranslations::SEEN => $seen], ['page_builder' => [$rule]])->fails());
    }
}
