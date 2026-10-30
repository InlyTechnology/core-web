<?php

namespace Inly\CoreWeb\Tests\Feature\Translation;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class TranslateFieldTest extends TestCase
{
    use FakesCore;
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['translation'];

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        Site::setSites([
            'default' => ['name' => 'Svenska', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'English', 'url' => 'http://default.test/en/', 'locale' => 'en_GB'],
        ]);
        $this->scratch = sys_get_temp_dir().'/core-web-translate-field-'.uniqid();
        Blueprint::setDirectory($this->scratch.'/blueprints');

        Collection::make('pages')->sites(['default', 'other'])->routes('/{slug}')->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
            ['handle' => 'image', 'field' => ['type' => 'assets']],
            ['handle' => 'page_builder', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'hero' => ['fields' => [['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']]]],
                'text' => ['fields' => [['handle' => 'body', 'field' => ['type' => 'bard', 'sets' => ['main' => ['sets' => ['quote' => ['fields' => [['handle' => 'quote', 'field' => ['type' => 'text']]]]]]]]]]],
            ]]]]],
        ]])->save();

        $this->fakeCore([
            'core.test/api/app/translations' => fn (Request $request) => Http::response(['translations' => collect($request['texts'])
                ->mapWithKeys(fn (array $text) => [$text['key'] => str_replace(['Familjeföretaget', 'Vi bygger'], ['The family business', 'We build'], $text['text'])])]),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    private function translation(array $own = []): string
    {
        $origin = Entry::make()->collection('pages')->locale('default')->slug('om')->data(['title' => 'Om oss', 'page_builder' => [
            ['id' => 'hero', 'type' => 'hero', 'heading' => 'Familjeföretaget'],
            ['id' => 'text', 'type' => 'text', 'body' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Vi bygger bilar.']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Kontakt', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc']]]]]],
                ['type' => 'set', 'attrs' => ['id' => 'q1', 'values' => ['type' => 'quote', 'quote' => 'Citat']]],
            ]],
        ]]);
        $origin->save();
        $translation = $origin->makeLocalization('other')->data($own);
        $translation->save();

        return $translation->id();
    }

    private function editor(): mixed
    {
        $user = User::make()->email('eva@example.com')->makeSuper();
        $user->save();

        return $user;
    }

    public function test_a_block_text_is_translated_from_the_original_row_with_the_same_id(): void
    {
        $id = $this->translation(['page_builder' => [['id' => 'own', 'type' => 'hero', 'heading' => 'Mine'], ['id' => 'hero', 'type' => 'hero', 'heading' => '']]]);

        $this->actingAs($this->editor())
            ->postJson(cp_route('core-web.translation.field'), ['entry' => $id, 'path' => 'page_builder.1.heading', 'value' => ''])
            ->assertOk()
            ->assertExactJson(['value' => 'The family business']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/app/translations')
            && $request['source_locale'] === 'sv_SE' && $request['target_locale'] === 'en_GB'
            && $request['texts'] === [['key' => 'text', 'text' => 'Familjeföretaget']]);
    }

    public function test_a_bard_field_is_translated_node_by_node_and_keeps_its_sets(): void
    {
        $id = $this->translation();

        $value = $this->actingAs($this->editor())
            ->postJson(cp_route('core-web.translation.field'), ['entry' => $id, 'path' => 'page_builder.1.body'])
            ->assertOk()
            ->json('value');

        $this->assertSame('We build bilar.', $value[0]['content'][0]['text']);
        $this->assertSame('statamic://entry::abc', $value[1]['content'][0]['marks'][0]['attrs']['href'], 'An entry link keeps following the entry.');
        $this->assertSame(['id' => 'q1', 'values' => ['type' => 'quote', 'quote' => 'Citat']], array_intersect_key($value[2]['attrs'], ['id' => 1, 'values' => 1]));
    }

    public function test_an_original_and_a_field_that_is_no_text_are_refused_with_a_reason(): void
    {
        $id = $this->translation();
        $this->actingAs($this->editor());

        $this->postJson(cp_route('core-web.translation.field'), ['entry' => Entry::find($id)->origin()->id(), 'path' => 'title'])
            ->assertStatus(422)->assertJson(['message' => 'Only a translation can be translated from its original.']);
        $this->postJson(cp_route('core-web.translation.field'), ['entry' => $id, 'path' => 'image'])
            ->assertStatus(422)->assertJson(['message' => 'This field is not text.']);
        Http::assertNothingSent();
    }

    public function test_a_site_whose_client_may_not_translate_is_told_what_to_grant(): void
    {
        $id = $this->translation();
        $this->fakeCore(['core.test/api/app/translations' => Http::response(['message' => 'Forbidden'], 403)]);

        $this->actingAs($this->editor())
            ->postJson(cp_route('core-web.translation.field'), ['entry' => $id, 'path' => 'title'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Inly Core does not let this site translate. Grant its app client "Translate content with AI".']);
    }

    public function test_only_an_editor_of_the_entry_may_translate(): void
    {
        $id = $this->translation();
        $user = User::make()->email('guest@example.com');
        $user->save();

        $this->actingAs($user)->postJson(cp_route('core-web.translation.field'), ['entry' => $id, 'path' => 'title'])->assertForbidden();
    }
}
