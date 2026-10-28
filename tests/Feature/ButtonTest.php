<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Buttons\Button;
use Inly\CoreWeb\Buttons\SnapshotPagesDictionary;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Dictionaries\BasicDictionary;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Dictionary;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\YAML;
use Statamic\Fields\Field;
use Statamic\Fields\Value;
use Statamic\Fields\Values;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class ButtonTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['buttons', 'snapshots'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.system.multisite' => true]);
        Collection::make('pages')->sites(['default', 'other'])->routes('{slug}')->save();
        Entry::make()->collection('pages')->locale('default')->id('about')->slug('om-oss')->save();
        Entry::make()->collection('pages')->locale('other')->id('elsewhere')->slug('borta')->save();
    }

    public function test_the_shipped_fieldset_is_what_the_default_config_builds(): void
    {
        $this->assertSame(YAML::file(__DIR__.'/../../resources/fieldsets/button.yaml')->parse(), Button::fieldset());
        $this->assertSame(Button::fieldset(), Fieldset::find('core-web::button')->contents());
    }

    public function test_the_fieldset_follows_the_sites_config(): void
    {
        $this->configure();
        $fields = collect(Button::fieldset()['fields'])->keyBy('handle')->map(fn (array $field) => $field['field']);

        $this->assertSame(['button' => 'Button', 'inline' => 'Text link'], $fields['button_type']['options']);
        $this->assertSame(['primary' => 'Primary', 'cta' => 'Call to action', 'ghost' => 'Ghost'], $fields['variant']['options']);
        $this->assertSame('equals button', $fields['variant']['if']['button_type']);
        $this->assertSame(['entry' => 'Entry', 'brand' => 'Brand', 'campaign' => 'Campaign', 'url' => 'URL', 'sms' => 'SMS'], $fields['link_type']['options']);
        $this->assertSame(['type' => 'core_snapshot_pages', 'snapshot' => 'brands'], $fields['brand']['dictionary']);
        $this->assertSame('site_campaigns', $fields['campaign']['dictionary']);
        $this->assertSame('equals campaign', $fields['campaign']['if']['link_type']);
        $this->assertTrue($fields['entry']['select_across_sites']);
        $this->assertFalse($fields->has('label_link'));
        $this->assertFalse($fields->has('email'));
        $this->assertFalse($fields->has('icon_left'));
    }

    public function test_a_button_with_a_label_needs_its_link_and_an_empty_one_saves(): void
    {
        $valid = function (array $data): bool {
            try {
                Blueprint::make()->setContents(['fields' => [['import' => 'core-web::button']]])->fields()->addValues($data)->validator()->validate();

                return true;
            } catch (ValidationException) {
                return false;
            }
        };

        $this->assertTrue($valid([]));
        $this->assertTrue($valid(['link_type' => 'entry']));
        $this->assertFalse($valid(['label' => 'Boka', 'link_type' => 'entry']));
        $this->assertTrue($valid(['label' => 'Boka', 'link_type' => 'url', 'url' => 'https://example.com']));
    }

    public function test_the_icon_fields_are_opt_in_and_link_the_projects_icon_reference(): void
    {
        $this->assertNull(collect(Button::fieldset()['fields'])->firstWhere('handle', 'icon_left'));
        config(['core-web.buttons.icons' => true]);
        $instructions = fn (): string => collect(Button::fieldset()['fields'])->firstWhere('handle', 'icon_left')['field']['instructions'];

        config(['core-web.icons.reference' => 'https://fontawesome.com/search']);
        $this->assertStringContainsString('(https://fontawesome.com/search)', $instructions());

        config(['core-web.icons.reference' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('javascript', $instructions());
    }

    public function test_an_icon_fields_name_is_read_not_its_augmented_svg(): void
    {
        $field = (new Field('icon_right', ['type' => 'icon']))->setValue('arrow-right');
        $row = new Values(['label' => 'A', 'link_type' => 'url', 'url' => 'https://example.com', 'icon_right' => new Value('arrow-right', 'icon_right', $field->fieldtype())]);

        $this->assertSame('arrow-right', Button::resolve($row)['icon_right']);
        $this->assertSame('arrow-right', Button::resolve(new Value($row))['icon_right']);
        $this->assertNull(Button::resolve(['label' => 'A', 'link_type' => 'url', 'url' => 'https://example.com', 'icon_right' => '<svg></svg>'])['icon_right']);
    }

    public function test_a_core_link_that_would_take_a_fields_handle_is_left_out(): void
    {
        config(['core-web.buttons.core_links' => ['url' => ['snapshot' => 'brands'], 'Bad key' => ['snapshot' => 'brands'], 'both' => ['snapshot' => 'a', 'dictionary' => 'b'], 'ok' => ['snapshot' => 'brands']]]);

        $this->assertSame(['ok'], array_keys(Button::coreLinks()));
    }

    public function test_an_entry_link_carries_its_suffix_and_a_raw_id_resolves(): void
    {
        $button = Button::resolve(['label' => 'Om oss', 'link_type' => 'entry', 'entry' => ['about'], 'query_string' => '#team']);

        $this->assertSame('/om-oss#team', $button['href']);
        $this->assertSame('Om oss', $button['label']);
        $this->assertSame('primary', $button['variant']);
        $this->assertSame([], $button['attrs']);
        $this->assertSame('/om-oss?filter=x', $this->href(['link_type' => 'entry', 'entry' => Entry::find('about'), 'query_string' => 'filter=x']));
        $this->assertSame('', $this->href(['link_type' => 'entry', 'entry' => ['gone']]));
    }

    public function test_a_button_that_would_render_nothing_resolves_to_null(): void
    {
        $this->assertNull(Button::resolve(null));
        $this->assertNull(Button::resolve([]));
        $this->assertNull(Button::resolve(['link_type' => 'url', 'url' => 'https://example.com']));
        $this->assertNull(Button::resolve(['label' => 'Borta', 'link_type' => 'entry', 'entry' => ['gone']]));
        $this->assertNotNull(Button::resolve(['label' => 'Om oss', 'link_type' => 'entry', 'entry' => ['about']]));
    }

    public function test_another_sites_entry_keeps_its_host(): void
    {
        $this->assertSame('http://other.test/borta', $this->href(['link_type' => 'entry', 'entry' => ['elsewhere']]));
    }

    public function test_the_other_link_types(): void
    {
        config(['core-web.buttons.link_types' => ['entry', 'url', 'email', 'tel', 'sms', 'asset']]);

        $this->assertSame('https://example.com', $this->href(['link_type' => 'url', 'url' => ' https://example.com ']));
        $this->assertSame('mailto:hej@example.com', $this->href(['link_type' => 'email', 'email' => 'hej@example.com']));
        $this->assertSame('tel:+46701234567', $this->href(['link_type' => 'tel', 'tel' => '+46 70-123 45 67']));
        $this->assertSame('sms:0701234567', $this->href(['link_type' => 'sms', 'sms' => '070 123 45 67']));
        $this->assertSame('', $this->href(['link_type' => 'email']));
        $this->assertSame('https://cdn.example.com/a.pdf', $this->href(['link_type' => 'asset', 'asset' => 'https://cdn.example.com/a.pdf']));
        $this->assertSame(['download' => ''], Button::resolve(['label' => 'PDF', 'link_type' => 'asset', 'asset' => 'https://cdn.example.com/a.pdf'])['attrs']);
    }

    public function test_an_address_a_browser_would_run_is_dropped(): void
    {
        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', 'data:text/html,x', 'vbscript:x'] as $url) {
            $this->assertSame('', $this->href(['link_type' => 'url', 'url' => $url]), $url);
        }

        foreach (['/kontakt', '#team', '?q=1', 'kontakt', '//cdn.example.com/a', 'mailto:a@example.com'] as $url) {
            $this->assertSame($url, $this->href(['link_type' => 'url', 'url' => $url]), $url);
        }

        config(['core-web.buttons.link_types' => ['tel']]);
        $this->assertSame('', $this->href(['link_type' => 'tel', 'tel' => 'ring oss']));
    }

    public function test_an_entry_links_its_page_on_the_current_site_and_never_a_draft(): void
    {
        Entry::make()->collection('pages')->locale('other')->origin('about')->id('about-other')->slug('about')->save();
        Entry::make()->collection('pages')->locale('default')->id('draft')->slug('utkast')->published(false)->save();

        $this->assertSame('', $this->href(['link_type' => 'entry', 'entry' => ['draft']]));

        Site::setCurrent('other');
        $this->assertSame('/about', $this->href(['link_type' => 'entry', 'entry' => ['about']]));
    }

    public function test_a_snapshot_core_link_needs_the_snapshots_feature(): void
    {
        config(['core-web.features.snapshots' => false, 'core-web.buttons.core_links' => ['brand' => ['snapshot' => 'brands'], 'campaign' => ['dictionary' => 'site_campaigns']]]);

        $this->assertSame(['campaign'], array_keys(Button::coreLinks()));
    }

    public function test_link_attributes_variant_and_icons(): void
    {
        $button = Button::resolve(['label' => 'A', 'link_type' => 'url', 'url' => 'https://example.com', 'variant' => 'secondary', 'icon_right' => 'fa-arrow-right', 'target_blank' => true, 'attr_title' => 'Hover', 'attr_aria' => 'Read more about us']);

        $this->assertSame('secondary', $button['variant']);
        $this->assertSame('arrow-right', $button['icon_right']);
        $this->assertSame(['target' => '_blank', 'rel' => 'noopener', 'title' => 'Hover', 'aria-label' => 'Read more about us'], $button['attrs']);
        $link = ['label' => 'A', 'link_type' => 'url', 'url' => 'https://example.com'];
        $this->assertSame('primary', Button::resolve($link + ['variant' => 'neon'])['variant']);
        $this->assertSame('secondary', Button::resolve($link + ['style' => 'secondary'])['variant']);
        $this->assertFalse(Button::resolve($link + ['button_type' => 'inline'])['inline']);

        config(['core-web.buttons.inline' => true]);
        $this->assertTrue(Button::resolve($link + ['button_type' => 'inline'])['inline']);
    }

    public function test_a_tracker_event_goes_to_the_sites_tracker_only_where_its_tags_render(): void
    {
        $seo = GlobalSet::make('seo');
        $seo->save();
        $seo->in('default')->data(['trackers_production' => true, 'tracker_type' => 'gtm'])->save();
        $row = ['label' => 'A', 'link_type' => 'url', 'url' => 'https://example.com', 'tracker_event' => "cta');alert(1)//"];

        $this->assertArrayNotHasKey('onclick', Button::resolve($row)['attrs']);

        $this->app['env'] = 'production';
        $this->assertSame("(window.dataLayer=window.dataLayer||[]).push({'event':'ctaalert1'})", Button::resolve($row)['attrs']['onclick']);

        $seo->in('default')->data(['trackers_production' => true, 'tracker_type' => 'gtag'])->save();
        $this->assertSame("window.gtag&&gtag('event','ctaalert1')", Button::resolve($row)['attrs']['onclick']);
    }

    public function test_a_core_link_resolves_to_its_snapshot_documents_page(): void
    {
        $this->brands();
        config(['core-web.buttons.core_links' => ['brand' => ['display' => 'Brand', 'snapshot' => 'brands']]]);

        $this->assertSame('/brands/volvo', $this->href(['link_type' => 'brand', 'brand' => 'v1']));
        $this->assertSame('', $this->href(['link_type' => 'brand', 'brand' => 'hidden']));
        $this->assertSame('', $this->href(['link_type' => 'brand', 'brand' => 'nope']));
    }

    public function test_a_core_link_resolves_through_a_sites_own_dictionary(): void
    {
        Dictionary::swap(Dictionary::getFacadeRoot());
        SiteCampaigns::register();
        config(['core-web.buttons.core_links' => ['campaign' => ['dictionary' => 'site_campaigns']]]);

        $this->assertSame('/kampanjer/vinter', $this->href(['link_type' => 'campaign', 'campaign' => 'winter']));
        $this->assertSame('', $this->href(['link_type' => 'campaign', 'campaign' => 'summer']));
    }

    public function test_the_snapshot_pages_dictionary_lists_the_documents_with_a_page_here(): void
    {
        $this->brands();
        $dictionary = Dictionary::find(SnapshotPagesDictionary::HANDLE, ['snapshot' => 'brands']);

        $this->assertSame(['a1' => 'Audi', 'v1' => 'Volvo'], $dictionary->options());
        $this->assertSame([], Dictionary::find(SnapshotPagesDictionary::HANDLE, ['snapshot' => 'unknown'])->options());
    }

    public function test_migrate_renames_style_to_variant_in_button_rows_only(): void
    {
        Entry::make()->collection('pages')->locale('default')->id('buttons')->slug('knappar')->data([
            'cta' => ['label' => 'A', 'link_type' => 'url', 'url' => 'https://a.test', 'style' => 'secondary'],
            'card' => ['link_type' => 'phone', 'style' => 'flat'],
            'blocks' => [['type' => 'hero', 'style' => 'dark', 'buttons' => [['label' => 'B', 'link_type' => 'entry', 'style' => 'primary']]]],
        ])->save();

        $this->artisan('buttons:migrate', ['--dry-run' => true])->expectsOutputToContain('cta, blocks.0.buttons.0')->expectsOutputToContain('Would change 1 item.')->assertSuccessful();
        $this->assertSame('secondary', Entry::find('buttons')->get('cta')['style']);

        $this->artisan('buttons:migrate')->assertSuccessful();
        $entry = Entry::find('buttons');
        $this->assertSame(['label' => 'A', 'link_type' => 'url', 'url' => 'https://a.test', 'variant' => 'secondary'], $entry->get('cta'));
        $this->assertSame('dark', $entry->get('blocks')[0]['style']);
        $this->assertSame('flat', $entry->get('card')['style']);
        $this->assertSame('primary', $entry->get('blocks')[0]['buttons'][0]['variant']);
        $this->artisan('buttons:migrate')->expectsOutputToContain('Changed 0 items.')->assertSuccessful();
    }

    /** @param array<string, mixed> $row */
    private function href(array $row): string
    {
        return Button::resolve(['label' => 'A'] + $row)['href'] ?? '';
    }

    private function configure(): void
    {
        config(['core-web.buttons' => [
            'variants' => ['primary' => 'Primary', 'cta' => 'Call to action', 'ghost' => 'Ghost'],
            'inline' => true,
            'link_types' => ['sms', 'entry', 'url'],
            'core_links' => ['brand' => ['display' => 'Brand', 'snapshot' => 'brands'], 'campaign' => ['display' => 'Campaign', 'dictionary' => 'site_campaigns']],
            'entries_across_sites' => true,
            'icons' => false,
            'asset_container' => 'assets',
        ]]);
    }

    private function brands(): void
    {
        app(SnapshotRegistry::class)->register(Snapshot::make('brands')
            ->pull(fn () => [
                'v1' => ['name' => 'Volvo', 'slug' => 'volvo'],
                'a1' => ['name' => 'Audi', 'slug' => 'audi'],
                'hidden' => ['name' => 'Hidden', 'slug' => 'hidden', 'hidden' => true],
            ])
            ->pages(fn (array $doc) => ($doc['hidden'] ?? false) ? null : '/brands/'.$doc['slug'])
            ->listing(fn (array $doc) => $doc['name']));
    }
}

class SiteCampaigns extends BasicDictionary
{
    protected static $handle = 'site_campaigns';

    protected function getItems(): array
    {
        return [
            ['value' => 'winter', 'label' => 'Vinter', 'url' => '/kampanjer/vinter'],
            ['value' => 'summer', 'label' => 'Sommar'],
        ];
    }
}
