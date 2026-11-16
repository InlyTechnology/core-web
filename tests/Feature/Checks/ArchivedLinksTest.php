<?php

namespace Inly\CoreWeb\Tests\Feature\Checks;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Checks\ContentChecks;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Proposals\ProposalStore;
use Inly\CoreWeb\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class ArchivedLinksTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['drafts', 'checks'];

    private string $scratch;

    private EntryContract $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->scratch = sys_get_temp_dir().'/core-web-archived-'.uniqid();
        config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => $this->scratch.'/revisions']);
        Blueprint::setDirectory($this->scratch.'/blueprints');
        config(['core-web.drafts.collections' => ['pages' => []]]);

        Collection::make('pages')->sites(['default'])->revisionsEnabled(true)->routes('/{slug}')->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'cta_link', 'field' => ['type' => 'link']],
            ['handle' => 'body', 'field' => ['type' => 'bard']],
            ['handle' => 'intro', 'field' => ['type' => 'textarea']],
        ]])->save();

        $this->contact = $this->page('kontakt', ['title' => 'Kontakt']);
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

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function findings(): \Illuminate\Support\Collection
    {
        return collect(app(ContentChecks::class)->run(app(ContentChecks::class)->get('archived-links'))['findings']);
    }

    /** @param  array<int, array<string, mixed>>  $tree */
    private function nav(array $tree): void
    {
        $nav = Nav::make('main')->title('Main')->collections(['pages']);
        $nav->save();
        $nav->makeTree('default', $tree)->save();
    }

    private function global(array $data): void
    {
        Blueprint::make('footer')->setNamespace('globals')->setContents(['fields' => [
            ['handle' => 'text', 'field' => ['type' => 'bard']],
            ['handle' => 'phone', 'field' => ['type' => 'text']],
        ]])->save();
        $set = GlobalSet::make('footer')->title('Footer');
        $set->save();
        $set->makeLocalization('default')->data($data)->save();
    }

    public function test_archive_links_are_found_in_bard_link_fields_text_navs_and_globals_with_the_address_they_archived(): void
    {
        $page = $this->page('om-oss', [
            'title' => 'Om oss',
            'cta_link' => 'https://web.archive.org/web/20250512152946/https://other.example/page/',
            'body' => $this->bardLink('https://web.archive.org/web/20250512152946if_/https://other.example/om/'),
            'intro' => 'Läs mer på https://archive.ph/2024.05.12-152946/https://other.example/nyheter/. Tack!',
        ]);
        $this->nav([['url' => 'https://www.archive.org/web/2019/http://other.example/', 'title' => 'Start']]);
        $this->global(['text' => $this->bardLink('https://webcache.googleusercontent.com/search?q=cache:AbCdEfGh1234:https://other.example/kontakt/+&cd=1&hl=sv')]);

        $findings = $this->findings();
        $inPage = $findings->where('subject', 'entry:'.$page->id())->keyBy('path');

        $this->assertSame('https://other.example/page/', $inPage['cta_link']['details']['original']);
        $this->assertSame('https://other.example/om/', $inPage['body.0.content.0.marks.0.attrs.href']['details']['original']);
        $this->assertSame('https://other.example/nyheter/', $inPage['intro']['details']['original']);
        $this->assertSame('https://archive.ph/2024.05.12-152946/https://other.example/nyheter/', $inPage['intro']['details']['found'], 'A link written in text is found without the punctuation after it.');
        $this->assertSame('web.archive.org', $inPage['cta_link']['details']['archive']);

        $inNav = $findings->where('subject', 'nav:main:default')->keyBy('path');
        $this->assertSame('http://other.example/', $inNav['0']['details']['original']);
        $this->assertStringContainsString('The menu item “Start”', $inNav['0']['message']);

        $inGlobal = $findings->where('subject', 'global:footer:default')->keyBy('path');
        $this->assertSame('https://other.example/kontakt/', $inGlobal['text.0.content.0.marks.0.attrs.href']['details']['original']);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function archiveUrls(): array
    {
        return [
            'wayback, id_ suffix' => ['https://web.archive.org/web/20250512152946id_/https://site.example/a/', 'https://site.example/a/'],
            'wayback, no timestamp' => ['http://wayback.archive.org/web/https://site.example/a/', 'https://site.example/a/'],
            'wayback, wildcard' => ['https://web.archive.org/web/2025*/site.example/a', 'http://site.example/a'],
            'wayback, collapsed slashes' => ['https://web.archive.org/web/20250512152946/https:/site.example/a', 'https://site.example/a'],
            'wayback, encoded' => ['https://web.archive.org/web/20250512152946/https%3A%2F%2Fsite.example%2Fa', 'https://site.example/a'],
            'archive.today newest' => ['https://archive.today/newest/https://site.example/a?b=1', 'https://site.example/a?b=1'],
            'archive.is short id' => ['https://archive.is/AbCd1', null],
            'archive.md' => ['https://www.archive.md/o/AbCd1/https://site.example/a', 'https://site.example/a'],
            'google cache, no id' => ['https://webcache.googleusercontent.com/search?q=cache:site.example/a', 'http://site.example/a'],
            'cachedview' => ['https://cachedview.nl/#https://site.example/a', 'https://site.example/a'],
            'wayback home' => ['https://web.archive.org/', null],
        ];
    }

    #[DataProvider('archiveUrls')]
    public function test_the_archived_address_is_read_from_each_kind_of_archive_url(string $url, ?string $original): void
    {
        $page = $this->page('om-oss', ['title' => 'Om oss', 'cta_link' => $url]);

        $finding = $this->findings()->where('subject', 'entry:'.$page->id())->firstWhere('path', 'cta_link');

        $this->assertNotNull($finding, "{$url} was not found.");
        $this->assertSame($original, $finding['details']['original']);
    }

    public function test_other_archive_org_pages_and_other_links_are_left_alone(): void
    {
        $page = $this->page('om-oss', [
            'title' => 'Om oss',
            'cta_link' => 'https://archive.org/details/some-collection',
            'body' => $this->bardLink('https://example.com/web/20250512152946/https://other.example/'),
            'intro' => 'Se https://archive.org/about/ och https://notarchive.ph/x.',
        ]);

        $this->assertSame([], $this->findings()->where('subject', 'entry:'.$page->id())->values()->all());
    }

    public function test_an_archived_copy_of_an_own_page_is_fixed_to_the_entry_in_a_draft(): void
    {
        $page = $this->page('om-oss', [
            'title' => 'Om oss',
            'cta_link' => 'https://web.archive.org/web/20250512152946/http://default.test/kontakt/',
            'body' => $this->bardLink('https://web.archive.org/web/20250512152946/http://default.test/kontakt?amne=dack#form'),
        ]);
        $findings = $this->findings()->where('subject', 'entry:'.$page->id())->keyBy('path');

        $this->assertTrue($findings['cta_link']['fixable']);
        $this->assertSame($this->contact->id(), $findings['cta_link']['details']['entry_id']);
        $this->assertStringContainsString('“Kontakt”', $findings['cta_link']['message']);

        $result = app(ContentChecks::class)->fix('archived-links', $findings['cta_link']['id'], $this->author());
        $entry = Entry::find($page->id());

        $this->assertSame('working_copy', $result['status']);
        $this->assertSame('https://web.archive.org/web/20250512152946/http://default.test/kontakt/', $entry->get('cta_link'), 'The live page is left as it is.');
        $this->assertSame('entry::'.$this->contact->id(), $entry->fromWorkingCopy()->get('cta_link'));
        $this->assertSame('statamic://entry::'.$this->contact->id().'?amne=dack#form', $entry->fromWorkingCopy()->get('body')[0]['content'][0]['marks'][0]['attrs']['href']);
    }

    public function test_an_archived_copy_of_another_sites_page_is_left_for_an_editor(): void
    {
        $page = $this->page('om-oss', [
            'title' => 'Om oss',
            'cta_link' => 'https://web.archive.org/web/20250512152946/https://other.example/page/',
            'intro' => 'https://web.archive.org/web/20250512152946/http://default.test/kontakt/',
        ]);
        $findings = $this->findings()->where('subject', 'entry:'.$page->id())->keyBy('path');

        $this->assertFalse($findings['cta_link']['fixable']);
        $this->assertNull($findings['cta_link']['details']['entry_id']);
        $this->assertStringContainsString('https://other.example/page/', $findings['cta_link']['message']);
        $this->assertFalse($findings['intro']['fixable'], 'A text field cannot hold an entry reference.');

        try {
            app(ContentChecks::class)->fix('archived-links', $findings['cta_link']['id'], $this->author());
            $this->fail('A link to another site was fixed.');
        } catch (DraftRefused $e) {
            $this->assertSame('not_fixable', $e->error);
        }
    }

    public function test_an_own_path_with_no_entry_is_left_for_an_editor(): void
    {
        $page = $this->page('om-oss', ['title' => 'Om oss', 'cta_link' => 'https://web.archive.org/web/2025/http://default.test/finns-inte/']);

        $finding = $this->findings()->where('subject', 'entry:'.$page->id())->firstWhere('path', 'cta_link');

        $this->assertFalse($finding['fixable']);
        $this->assertSame('http://default.test/finns-inte/', $finding['details']['original']);
    }

    public function test_an_archived_menu_item_for_an_own_page_is_fixed_in_a_proposal(): void
    {
        $this->nav([
            ['url' => 'https://web.archive.org/web/2025/http://default.test/kontakt', 'title' => 'Kontakt'],
            ['url' => 'https://web.archive.org/web/2025/https://other.example/', 'title' => 'Partner'],
        ]);
        $findings = $this->findings()->where('subject', 'nav:main:default')->keyBy('path');

        $this->assertTrue($findings['0']['fixable']);
        $this->assertFalse($findings['1']['fixable']);

        $result = app(ContentChecks::class)->fix('archived-links', $findings['0']['id'], $this->author());

        $this->assertSame('nav:main:default', $result['target']);
        $this->assertSame('https://web.archive.org/web/2025/http://default.test/kontakt', Nav::find('main')->in('default')->tree()[0]['url'], 'The nav waits for an editor to apply it.');
        $this->assertSame(['nav:main:default'], app(ProposalStore::class)->openTargets());
    }

    public function test_an_archived_own_page_in_a_global_is_fixed_in_a_proposal(): void
    {
        $this->global(['text' => $this->bardLink('https://web.archive.org/web/2025/http://default.test/kontakt'), 'phone' => '123']);
        $finding = $this->findings()->where('subject', 'global:footer:default')->first();

        $this->assertTrue($finding['fixable']);

        $result = app(ContentChecks::class)->fix('archived-links', $finding['id'], $this->author());

        $this->assertSame('global:footer:default', $result['target']);
        $this->assertSame('https://web.archive.org/web/2025/http://default.test/kontakt', GlobalSet::find('footer')->in('default')->get('text')[0]['content'][0]['marks'][0]['attrs']['href'], 'The global waits for an editor to apply it.');
    }

    public function test_a_findings_id_is_stable_across_runs(): void
    {
        $this->page('om-oss', ['title' => 'Om oss', 'cta_link' => 'https://web.archive.org/web/2025/https://other.example/']);
        $this->nav([['url' => 'https://archive.ph/AbCd1', 'title' => 'Arkiv']]);

        $first = $this->findings()->pluck('id')->sort()->values()->all();

        $this->assertCount(2, $first);
        $this->assertSame($first, $this->findings()->pluck('id')->sort()->values()->all());
    }
}
