<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View as ViewFactory;
use Inly\CoreWeb\Buttons\Buttons;
use Inly\CoreWeb\Buttons\SnapshotPagesDictionary;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Dictionaries\BasicDictionary;
use Statamic\Facades\Collection;
use Statamic\Facades\Dictionary;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\YAML;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class ButtonsTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['buttons', 'snapshots'];

    protected function setUp(): void
    {
        parent::setUp();

        ViewFactory::addLocation(__DIR__.'/../fixtures/views');
        config(['statamic.system.multisite' => true]);
        Collection::make('pages')->sites(['default', 'other'])->routes('{slug}')->save();
        Entry::make()->collection('pages')->locale('default')->id('about')->slug('om-oss')->save();
        Entry::make()->collection('pages')->locale('other')->id('elsewhere')->slug('borta')->save();
    }

    public function test_the_shipped_fieldset_is_what_the_default_config_builds(): void
    {
        $this->assertSame(YAML::file(__DIR__.'/../../resources/fieldsets/button.yaml')->parse(), Buttons::fieldset());
        $this->assertSame(Buttons::fieldset(), Fieldset::find('core-web::button')->contents());
    }

    public function test_the_fieldset_follows_the_sites_config(): void
    {
        $this->configure();
        $fields = collect(Buttons::fieldset()['fields'])->keyBy('handle')->map(fn (array $field) => $field['field']);

        $this->assertSame(['button' => 'Button', 'inline' => 'Inline'], $fields['button_type']['options']);
        $this->assertSame(['primary' => 'Primary', 'cta' => 'Call to action', 'ghost' => 'Ghost'], $fields['style']['options']);
        $this->assertSame('equals button', $fields['style']['if']['button_type']);
        $this->assertSame(['entry' => 'Entry', 'brand' => 'Brand', 'campaign' => 'Campaign', 'url' => 'URL', 'sms' => 'SMS'], $fields['link_type']['options']);
        $this->assertSame(['type' => 'core_snapshot_pages', 'snapshot' => 'brands'], $fields['brand']['dictionary']);
        $this->assertSame('site_campaigns', $fields['campaign']['dictionary']);
        $this->assertSame('equals campaign', $fields['campaign']['if']['link_type']);
        $this->assertTrue($fields['entry']['select_across_sites']);
        $this->assertFalse($fields->has('label_link'));
        $this->assertFalse($fields->has('email'));
        $this->assertFalse($fields->has('icon_left'));
    }

    public function test_a_core_link_that_would_take_a_fields_handle_is_left_out(): void
    {
        config(['core-web.buttons.core_links' => ['url' => ['snapshot' => 'brands'], 'Bad key' => ['snapshot' => 'brands'], 'both' => ['snapshot' => 'a', 'dictionary' => 'b'], 'ok' => ['snapshot' => 'brands']]]);

        $this->assertSame(['ok'], array_keys(Buttons::coreLinks()));
    }

    public function test_an_entry_link_carries_its_suffix_and_a_raw_id_resolves(): void
    {
        $button = Buttons::resolve(['label' => 'Om oss', 'link_type' => 'entry', 'entry' => ['about'], 'query_string' => '#team']);

        $this->assertSame('/om-oss#team', $button['href']);
        $this->assertSame('Om oss', $button['label']);
        $this->assertSame('primary', $button['style']);
        $this->assertSame([], $button['attrs']);
        $this->assertSame('/om-oss?filter=x', Buttons::resolve(['link_type' => 'entry', 'entry' => Entry::find('about'), 'query_string' => 'filter=x'])['href']);
        $this->assertSame('', Buttons::resolve(['link_type' => 'entry', 'entry' => ['gone']])['href']);
    }

    public function test_another_sites_entry_keeps_its_host(): void
    {
        $this->assertSame('http://other.test/borta', Buttons::resolve(['link_type' => 'entry', 'entry' => ['elsewhere']])['href']);
    }

    public function test_the_other_link_types(): void
    {
        config(['core-web.buttons.link_types' => ['entry', 'url', 'email', 'tel', 'sms', 'asset']]);

        $this->assertSame('https://example.com', Buttons::resolve(['link_type' => 'url', 'url' => ' https://example.com '])['href']);
        $this->assertSame('mailto:hej@example.com', Buttons::resolve(['link_type' => 'email', 'email' => 'hej@example.com'])['href']);
        $this->assertSame('tel:+46701234567', Buttons::resolve(['link_type' => 'tel', 'tel' => '+46 70-123 45 67'])['href']);
        $this->assertSame('sms:0701234567', Buttons::resolve(['link_type' => 'sms', 'sms' => '070 123 45 67'])['href']);
        $this->assertSame('', Buttons::resolve(['link_type' => 'email'])['href']);
        $this->assertSame('https://cdn.example.com/a.pdf', Buttons::resolve(['link_type' => 'asset', 'asset' => 'https://cdn.example.com/a.pdf'])['href']);
    }

    public function test_an_address_a_browser_would_run_is_dropped(): void
    {
        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', 'data:text/html,x', 'vbscript:x'] as $url) {
            $this->assertSame('', Buttons::resolve(['link_type' => 'url', 'url' => $url])['href'], $url);
        }

        foreach (['/kontakt', '#team', '?q=1', 'kontakt', '//cdn.example.com/a', 'mailto:a@example.com'] as $url) {
            $this->assertSame($url, Buttons::resolve(['link_type' => 'url', 'url' => $url])['href'], $url);
        }

        config(['core-web.buttons.link_types' => ['tel']]);
        $this->assertSame('', Buttons::resolve(['link_type' => 'tel', 'tel' => 'ring oss'])['href']);
    }

    public function test_an_entry_links_its_page_on_the_current_site_and_never_a_draft(): void
    {
        Entry::make()->collection('pages')->locale('other')->origin('about')->id('about-other')->slug('about')->save();
        Entry::make()->collection('pages')->locale('default')->id('draft')->slug('utkast')->published(false)->save();

        $this->assertSame('', Buttons::resolve(['link_type' => 'entry', 'entry' => ['draft']])['href']);

        Site::setCurrent('other');
        $this->assertSame('/about', Buttons::resolve(['link_type' => 'entry', 'entry' => ['about']])['href']);
    }

    public function test_a_snapshot_core_link_needs_the_snapshots_feature(): void
    {
        config(['core-web.features.snapshots' => false, 'core-web.buttons.core_links' => ['brand' => ['snapshot' => 'brands'], 'campaign' => ['dictionary' => 'site_campaigns']]]);

        $this->assertSame(['campaign'], array_keys(Buttons::coreLinks()));
    }

    public function test_link_attributes_style_and_icons(): void
    {
        $button = Buttons::resolve(['link_type' => 'url', 'url' => 'https://example.com', 'style' => 'secondary', 'icon_right' => 'fa-arrow-right', 'target_blank' => true, 'attr_title' => 'Hover', 'attr_aria' => 'Read more about us']);

        $this->assertSame('secondary', $button['style']);
        $this->assertSame('arrow-right', $button['icon_right']);
        $this->assertSame(['target' => '_blank', 'rel' => 'noopener', 'title' => 'Hover', 'aria-label' => 'Read more about us'], $button['attrs']);
        $this->assertSame('primary', Buttons::resolve(['style' => 'neon'])['style']);
        $this->assertFalse(Buttons::resolve(['button_type' => 'inline'])['inline']);

        config(['core-web.buttons.inline' => true]);
        $this->assertTrue(Buttons::resolve(['button_type' => 'inline'])['inline']);
    }

    public function test_a_tracker_event_goes_to_the_sites_tracker_only_where_its_tags_render(): void
    {
        $seo = GlobalSet::make('seo');
        $seo->save();
        $seo->in('default')->data(['trackers_production' => true, 'tracker_type' => 'gtm'])->save();
        $row = ['link_type' => 'url', 'url' => 'https://example.com', 'tracker_event' => "cta');alert(1)//"];

        $this->assertArrayNotHasKey('onclick', Buttons::resolve($row)['attrs']);

        $this->app['env'] = 'production';
        $this->assertSame("(window.dataLayer=window.dataLayer||[]).push({'event':'ctaalert1'})", Buttons::resolve($row)['attrs']['onclick']);

        $seo->in('default')->data(['trackers_production' => true, 'tracker_type' => 'gtag'])->save();
        $this->assertSame("window.gtag&&gtag('event','ctaalert1')", Buttons::resolve($row)['attrs']['onclick']);
    }

    public function test_a_core_link_resolves_to_its_snapshot_documents_page(): void
    {
        $this->brands();
        config(['core-web.buttons.core_links' => ['brand' => ['display' => 'Brand', 'snapshot' => 'brands']]]);

        $this->assertSame('/brands/volvo', Buttons::resolve(['link_type' => 'brand', 'brand' => 'v1'])['href']);
        $this->assertSame('', Buttons::resolve(['link_type' => 'brand', 'brand' => 'hidden'])['href']);
        $this->assertSame('', Buttons::resolve(['link_type' => 'brand', 'brand' => 'nope'])['href']);
    }

    public function test_a_core_link_resolves_through_a_sites_own_dictionary(): void
    {
        Dictionary::swap(Dictionary::getFacadeRoot());
        SiteCampaigns::register();
        config(['core-web.buttons.core_links' => ['campaign' => ['dictionary' => 'site_campaigns']]]);

        $this->assertSame('/kampanjer/vinter', Buttons::resolve(['link_type' => 'campaign', 'campaign' => 'winter'])['href']);
        $this->assertSame('', Buttons::resolve(['link_type' => 'campaign', 'campaign' => 'summer'])['href']);
    }

    public function test_the_snapshot_pages_dictionary_lists_the_documents_with_a_page_here(): void
    {
        $this->brands();
        $dictionary = Dictionary::find(SnapshotPagesDictionary::HANDLE, ['snapshot' => 'brands']);

        $this->assertSame(['a1' => 'Audi', 'v1' => 'Volvo'], $dictionary->options());
        $this->assertSame([], Dictionary::find(SnapshotPagesDictionary::HANDLE, ['snapshot' => 'unknown'])->options());
    }

    public function test_the_component_renders_the_sites_button_for_each_row_that_links_somewhere(): void
    {
        $html = Blade::render('<x-core-web::buttons :buttons="$buttons" align="center" />', ['buttons' => [
            ['label' => 'Om oss', 'link_type' => 'entry', 'entry' => ['about'], 'icon_right' => 'arrow-right'],
            ['label' => 'Gone', 'link_type' => 'entry', 'entry' => ['gone']],
            ['link_type' => 'url', 'url' => 'https://example.com'],
            ['label' => 'Ny flik', 'link_type' => 'url', 'url' => 'https://example.com', 'target_blank' => true, 'style' => 'secondary'],
        ]]);

        $this->assertStringContainsString('justify-content:center', $html);
        $this->assertStringContainsString('<a href="/om-oss" data-variant="primary" data-inline="NULL" data-icon-right="arrow-right"', $html);
        $this->assertStringContainsString('data-variant="secondary"', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener">Ny flik</a>', $html);
        $this->assertStringNotContainsString('Gone', $html);
        $this->assertSame(2, substr_count($html, '<a '));
        $this->assertSame('', trim(Blade::render('<x-core-web::buttons :buttons="[]" />')));
        $this->assertStringContainsString('<div class="flex gap-2">', Blade::render('<x-core-web::buttons :buttons="$buttons" class="flex gap-2" />', ['buttons' => [['label' => 'A', 'link_type' => 'url', 'url' => 'https://a.test']]]));

        config(['core-web.buttons.inline' => true]);
        $this->assertStringContainsString('data-inline="true"', Blade::render('<x-core-web::buttons :buttons="$buttons" />', ['buttons' => [['label' => 'A', 'link_type' => 'url', 'url' => 'https://a.test', 'button_type' => 'inline']]]));
    }

    private function configure(): void
    {
        config(['core-web.buttons' => [
            'styles' => ['primary' => 'Primary', 'cta' => 'Call to action', 'ghost' => 'Ghost'],
            'inline' => true,
            'link_types' => ['sms', 'entry', 'url'],
            'core_links' => ['brand' => ['display' => 'Brand', 'snapshot' => 'brands'], 'campaign' => ['display' => 'Campaign', 'dictionary' => 'site_campaigns']],
            'entries_across_sites' => true,
            'icons' => false,
            'asset_container' => 'assets',
            'component' => 'ui.button',
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
