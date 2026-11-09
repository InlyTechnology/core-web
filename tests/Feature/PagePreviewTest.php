<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View as ViewFactory;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** Statamic connector API v1.7: Core's preview frame of one of the site's own pages, as it is in the CMS. */
class PagePreviewTest extends TestCase
{
    use FakesCore;
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['content', 'preview'];

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        ViewFactory::addLocation(__DIR__.'/../fixtures/views');
        $this->fakeCore();
        $this->scratch = sys_get_temp_dir().'/core-web-page-preview-'.uniqid();
        // Multisite, where Statamic's Live Preview allows framing by the site's own hosts alone.
        config(['statamic.system.multisite' => true, 'statamic.revisions.enabled' => true, 'statamic.revisions.path' => $this->scratch.'/revisions']);

        Collection::make('pages')->sites(['default', 'other'])->revisionsEnabled(true)->routes('/sidor/{slug}')->template('page-preview')->save();
        Collection::make('snippets')->sites(['default'])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    private function page(string $slug, string $title, bool $published = true, string $site = 'default'): EntryContract
    {
        $entry = Entry::make()->collection('pages')->locale($site)->slug($slug)->published($published)->data(['title' => $title]);
        $entry->save();

        return $entry;
    }

    /** The frame Core opens: the link the site hands Core, without its host. */
    private function frameOf(EntryContract $entry): string
    {
        $url = (string) $this->callSealed('GET', '/api/core/v1/page-preview/'.$entry->id(), 'website.read')->assertOk()->json('data.url');
        $parts = parse_url($url);

        return $parts['path'].'?'.$parts['query'];
    }

    public function test_core_frames_a_live_page_by_a_signed_link_on_its_own_site(): void
    {
        $entry = $this->page('om-oss', 'Om oss');

        $url = $this->callSealed('GET', '/api/core/v1/page-preview/'.$entry->id(), 'website.read')
            ->assertOk()
            ->assertJsonPath('data.entry_id', $entry->id())
            ->json('data.url');
        $this->assertStringStartsWith('http://default.test/core-preview/page/'.$entry->id().'?expires=', $url);
        $this->assertStringContainsString('&signature=', $url);

        $response = $this->get($this->frameOf($entry))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', 'frame-ancestors https://core.test')
            ->assertHeaderMissing('X-Frame-Options');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('<h1>Om oss</h1>', $html);
        $this->assertStringContainsString('<p>PREVIEW</p>', $html);
        $this->assertStringContainsString('core-preview:focus', $html);

        $public = (string) $this->get('http://default.test/sidor/om-oss')->assertOk()->getContent();
        $this->assertStringNotContainsString('PREVIEW', $public);
        $this->assertStringNotContainsString('core-preview:', $public);
    }

    public function test_a_page_on_another_site_is_linked_on_that_site(): void
    {
        $entry = $this->page('om', 'Om', site: 'other');

        $this->assertStringStartsWith('http://other.test/core-preview/page/'.$entry->id().'?', (string) $this->callSealed('GET', '/api/core/v1/page-preview/'.$entry->id(), 'website.read')->json('data.url'));
        $this->get($this->frameOf($entry))->assertOk()->assertSee('<h1>Om</h1>', false);
    }

    public function test_the_frame_shows_the_working_copy_of_a_live_page(): void
    {
        $entry = $this->page('om-oss', 'Om oss');
        (clone $entry)->data(['title' => 'Om oss, ändrad'])->makeWorkingCopy()->save();

        // Before the frame: Statamic keeps Live Preview's stand-in for the rest of the process, which a test shares.
        $this->get('http://default.test/sidor/om-oss')->assertOk()->assertSee('<h1>Om oss</h1>', false);

        $html = (string) $this->get($this->frameOf(Entry::find($entry->id())))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', 'frame-ancestors https://core.test')
            ->getContent();

        $this->assertStringContainsString('<h1>Om oss, ändrad</h1>', $html);
        $this->assertStringContainsString('<p>PREVIEW</p>', $html);
    }

    public function test_the_frame_shows_an_unpublished_page(): void
    {
        $entry = $this->page('kommande', 'Kommande', published: false);

        $this->get('http://default.test/sidor/kommande')->assertNotFound();
        $this->get($this->frameOf($entry))->assertOk()->assertSee('<h1>Kommande</h1>', false);
    }

    public function test_only_core_gets_the_link_and_only_the_link_opens_the_frame(): void
    {
        $entry = $this->page('om-oss', 'Om oss');
        $other = $this->page('kontakt', 'Kontakt');
        $frame = $this->frameOf($entry);

        $this->getJson('/api/core/v1/page-preview/'.$entry->id())->assertForbidden();
        $this->get('/core-preview/page/'.$entry->id())->assertForbidden();
        $this->get(str_replace($entry->id(), $other->id(), $frame))->assertForbidden();
    }

    public function test_an_entry_without_a_page_is_not_found(): void
    {
        $snippet = Entry::make()->collection('snippets')->locale('default')->slug('fot')->data(['title' => 'Fot']);
        $snippet->save();

        $this->callSealed('GET', '/api/core/v1/page-preview/'.$snippet->id(), 'website.read')
            ->assertNotFound()
            ->assertExactJson(['error' => 'not_found', 'message' => 'The site serves no such page.']);
        $this->callSealed('GET', '/api/core/v1/page-preview/nope', 'website.read')->assertNotFound()->assertJsonPath('error', 'not_found');
    }

    public function test_a_page_deleted_after_its_link_was_made_is_not_found(): void
    {
        $entry = $this->page('om-oss', 'Om oss');
        $frame = $this->frameOf($entry);
        $entry->delete();

        $this->get($frame)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_frame_shows_a_scheduled_page(): void
    {
        Collection::make('events')->sites(['default'])->dated(true)->futureDateBehavior('private')->routes('/evenemang/{slug}')->template('page-preview')->save();
        $entry = Entry::make()->collection('events')->locale('default')->slug('mässa')->date(now()->addWeek())->data(['title' => 'Mässa']);
        $entry->save();

        $this->assertSame('scheduled', $entry->status());
        $this->get($this->frameOf($entry))->assertOk()->assertSee('<h1>Mässa</h1>', false);
    }

    public function test_the_link_expires_after_a_day(): void
    {
        $frame = $this->frameOf($this->page('om-oss', 'Om oss'));

        $this->travel(23)->hours();
        $this->get($frame)->assertOk();

        $this->travel(2)->hours();
        $this->get($frame)->assertForbidden();
    }

    public function test_a_redirect_has_no_page_to_frame(): void
    {
        $entry = Entry::make()->collection('pages')->locale('default')->slug('gammal')->data(['title' => 'Gammal', 'redirect' => 'https://example.com/ny']);
        $entry->save();

        $this->callSealed('GET', '/api/core/v1/page-preview/'.$entry->id(), 'website.read')->assertNotFound()->assertJsonPath('error', 'not_found');
    }

    public function test_where_the_site_serves_the_stock_feed_only_its_collections_are_framed(): void
    {
        Collection::make('notes')->sites(['default'])->routes('/anteckningar/{slug}')->template('page-preview')->save();
        $note = Entry::make()->collection('notes')->locale('default')->slug('intern')->data(['title' => 'Intern']);
        $note->save();
        $page = $this->page('om-oss', 'Om oss');
        $frame = $this->frameOf($note);
        config(['core-web.content.feed.enabled' => true, 'core-web.content.feed.collections' => ['pages']]);

        $this->callSealed('GET', '/api/core/v1/page-preview/'.$note->id(), 'website.read')->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->get($frame)->assertNotFound();
        $this->callSealed('GET', '/api/core/v1/page-preview/'.$page->id(), 'website.read')->assertOk();
    }
}
