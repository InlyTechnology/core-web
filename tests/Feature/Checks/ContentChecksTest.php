<?php

namespace Inly\CoreWeb\Tests\Feature\Checks;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Checks\ContentChecks;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Tests\TestCase;
use Inly\CoreWeb\Widgets\ContentChecksWidget;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class ContentChecksTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['drafts', 'checks'];

    private string $scratch;

    private EntryContract $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->scratch = sys_get_temp_dir().'/core-web-checks-'.uniqid();
        config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => $this->scratch.'/revisions']);
        Blueprint::setDirectory($this->scratch.'/blueprints');
        config(['core-web.drafts.collections' => ['pages' => []]]);

        Collection::make('pages')->sites(['default'])->revisionsEnabled(true)->routes('/{slug}')->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'cta_link', 'field' => ['type' => 'link']],
            ['handle' => 'body', 'field' => ['type' => 'bard']],
            ['handle' => 'seo_description', 'field' => ['type' => 'text']],
            ['handle' => 'note_url', 'field' => ['type' => 'text']],
        ]])->save();

        $this->contact = $this->page('kontakt', ['title' => 'Kontakt', 'seo_description' => 'Ring oss.']);

        $nav = Nav::make('main')->title('Main')->collections(['pages']);
        $nav->save();
        $nav->makeTree('default', [
            ['url' => '/kontakt', 'title' => 'Kontakt'],
            ['url' => '/kontakt#karta', 'title' => 'Karta'],
            ['entry' => 'deleted-page'],
        ])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $data */
    private function page(string $slug, array $data): EntryContract
    {
        $entry = Entry::make()->collection('pages')->locale('default')->slug($slug)->data($data)->published(true);
        $entry->save();

        return $entry;
    }

    private function author(): DraftAuthor
    {
        $user = User::make()->email('ann@example.com')->data(['name' => 'Ann'])->makeSuper();
        $user->save();

        return new DraftAuthor($user, 'Ann', DraftProvenance::make('content-check'), 'Fixed by a content check');
    }

    private function bardLink(string $href): array
    {
        return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hör av dig', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]]]];
    }

    /** @return list<array<string, mixed>> */
    private function findings(string $check): array
    {
        return app(ContentChecks::class)->run(app(ContentChecks::class)->get($check))['findings'];
    }

    public function test_links_to_own_pages_typed_as_urls_are_found_in_entries_and_navs(): void
    {
        $page = $this->page('om-oss', [
            'title' => 'Om oss',
            'seo_description' => 'Vi.',
            'cta_link' => 'http://default.test/kontakt',
            'body' => $this->bardLink('/kontakt?amne=dack#form'),
            'note_url' => '/kontakt',
        ]);

        $findings = collect($this->findings('own-url-links'));
        $inPage = $findings->where('subject', 'entry:'.$page->id())->keyBy('path');

        $this->assertSame('entry::'.$this->contact->id(), $inPage['cta_link']['details']['replacement']);
        $this->assertSame('statamic://entry::'.$this->contact->id().'?amne=dack#form', $inPage['body.0.content.0.marks.0.attrs.href']['details']['replacement']);
        $this->assertFalse($inPage['note_url']['fixable'], 'A plain text field cannot hold an entry reference.');
        $this->assertTrue($inPage['cta_link']['fixable']);

        $inNav = $findings->where('subject', 'nav:main:default')->keyBy('path');
        $this->assertTrue($inNav['0']['fixable']);
        $this->assertFalse($inNav['1']['fixable'], 'A menu item with an anchor needs the site\'s own suffix field.');
    }

    public function test_external_links_and_entry_references_are_left_alone(): void
    {
        $this->page('om-oss', ['title' => 'Om oss', 'cta_link' => 'entry::'.$this->contact->id(), 'body' => $this->bardLink('https://example.com/kontakt'), 'note_url' => 'mailto:hej@example.com']);

        $this->assertSame([], collect($this->findings('own-url-links'))->where('subject', '!=', 'nav:main:default')->values()->all());
    }

    public function test_fixing_an_entry_writes_one_draft_with_every_fixable_link(): void
    {
        $page = $this->page('om-oss', ['title' => 'Om oss', 'cta_link' => '/kontakt', 'body' => $this->bardLink('/kontakt')]);
        $finding = collect($this->findings('own-url-links'))->firstWhere('path', 'cta_link');

        $result = app(ContentChecks::class)->fix('own-url-links', $finding['id'], $this->author());

        $entry = Entry::find($page->id());

        $this->assertSame('working_copy', $result['status']);
        $this->assertSame('/kontakt', $entry->get('cta_link'), 'The live page is left as it is.');
        $this->assertSame('entry::'.$this->contact->id(), $entry->fromWorkingCopy()->get('cta_link'));
        $this->assertSame('statamic://entry::'.$this->contact->id(), $entry->fromWorkingCopy()->get('body')[0]['content'][0]['marks'][0]['attrs']['href']);
        $this->assertSame('content-check', DraftProvenance::via($entry->workingCopy()));
    }

    public function test_fixing_a_nav_stages_a_proposal(): void
    {
        $finding = collect($this->findings('own-url-links'))->where('subject', 'nav:main:default')->firstWhere('path', '0');

        $result = app(ContentChecks::class)->fix('own-url-links', $finding['id'], $this->author());

        $this->assertSame('nav:main:default', $result['target']);
        $this->assertSame('/kontakt', Nav::find('main')->in('default')->tree()[0]['url'], 'The nav waits for an editor to apply it.');
    }

    public function test_a_known_unfixable_finding_is_refused_without_reading_the_site_again(): void
    {
        $this->page('om-oss', ['title' => 'Om oss', 'cta_link' => 'entry::gone']);
        $this->artisan('content:check', ['check' => ['dead-targets']]);
        $finding = app(ContentChecks::class)->latest('dead-targets')['findings'][0];

        try {
            app(ContentChecks::class)->fix('dead-targets', $finding['id'], $this->author());
            $this->fail('An unfixable finding was fixed.');
        } catch (DraftRefused $e) {
            $this->assertSame('not_fixable', $e->error);
        }
    }

    public function test_a_fix_rereads_only_the_findings_own_page(): void
    {
        $page = $this->page('om-oss', ['title' => 'Om oss', 'cta_link' => '/kontakt']);
        $this->artisan('content:check', ['check' => ['own-url-links']]);
        $finding = collect(app(ContentChecks::class)->latest('own-url-links')['findings'])->firstWhere('path', 'cta_link');

        $result = app(ContentChecks::class)->fix('own-url-links', $finding['id'], $this->author());

        $this->assertSame($page->id(), $result['entry_id']);
    }

    public function test_fixes_need_the_drafts_feature(): void
    {
        config(['core-web.features.drafts' => false]);

        $this->expectException(DraftRefused::class);

        app(ContentChecks::class)->fix('own-url-links', 'any', $this->author());
    }

    public function test_a_finding_that_is_gone_is_not_fixed(): void
    {
        $this->expectException(DraftRefused::class);

        app(ContentChecks::class)->fix('own-url-links', 'nope', $this->author());
    }

    public function test_references_to_deleted_pages_and_files_are_found(): void
    {
        $page = $this->page('om-oss', ['title' => 'Om oss', 'cta_link' => 'entry::gone', 'body' => $this->bardLink('statamic://asset::assets::gone.jpg')]);

        $findings = collect($this->findings('dead-targets'));

        $this->assertEqualsCanonicalizing(['cta_link', 'body.0.content.0.marks.0.attrs.href'], $findings->where('subject', 'entry:'.$page->id())->pluck('path')->all());
        $this->assertSame(['2'], $findings->where('subject', 'nav:main:default')->pluck('path')->values()->all());
    }

    public function test_seo_gaps_name_missing_and_long_descriptions_and_titles(): void
    {
        $missing = $this->page('utan', ['title' => 'Utan beskrivning']);
        $long = $this->page('lang', ['title' => str_repeat('Lång titel ', 8), 'seo_description' => str_repeat('a', 200)]);

        $findings = collect($this->findings('seo-gaps'));

        $this->assertSame(['seo_description'], $findings->where('subject', 'entry:'.$missing->id())->pluck('path')->values()->all());
        $this->assertEqualsCanonicalizing(['seo_description', 'title'], $findings->where('subject', 'entry:'.$long->id())->pluck('path')->all());
        $this->assertCount(0, $findings->where('subject', 'entry:'.$this->contact->id()));
    }

    public function test_the_command_runs_every_check_and_keeps_the_findings(): void
    {
        $this->artisan('content:check')->assertSuccessful()->expectsOutputToContain('own-url-links: 2 found');

        $this->assertCount(2, app(ContentChecks::class)->latest('own-url-links')['findings']);
        $this->artisan('content:check', ['check' => ['nope']])->assertFailed();
    }

    public function test_the_checks_run_nightly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'content:check'));

        $this->assertSame('30 3 * * *', $event?->expression);
    }

    public function test_the_widget_shows_the_latest_findings(): void
    {
        $this->artisan('content:check');

        $widget = new ContentChecksWidget;
        $widget->setConfig([]);
        $html = (string) $widget->html()->render();

        $this->assertStringContainsString('Links to the site', $html);
        $this->assertStringContainsString('Kontakt', $html);
    }
}
