<?php

namespace Inly\CoreWeb\Tests\Feature\Content;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Buttons\SnapshotPagesDictionary;
use Inly\CoreWeb\Content\Feed\ContentProjection;
use Inly\CoreWeb\Content\Feed\PageStatus;
use Inly\CoreWeb\Content\Feed\PingPageJob;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntryScheduleReached;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** The content feed: Core's stock importer reads the site's pages, one projection per page and locale. */
class ContentFeedTest extends TestCase
{
    use FakesCore;
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['content', 'drafts', 'snapshots'];

    private string $scratch;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('core-web.content.feed.enabled', true);
        $app['config']->set('core-web.content.feed.dictionaries', ['staff' => 'employee']);
        $app['config']->set('core-web.content.connector_id', 'website');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCore();
        Queue::fake();
        $this->scratch = sys_get_temp_dir().'/core-web-feed-'.uniqid();
        Blueprint::setDirectory($this->scratch.'/blueprints');
        config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => $this->scratch.'/revisions']);
        config(['core-web.drafts.collections' => ['pages' => ['blueprints' => ['page']]]]);

        app(SnapshotRegistry::class)->register(Snapshot::make('brands')
            ->pull(fn () => [])
            ->listing(fn (array $brand): string => (string) ($brand['name'] ?? ''), 'manufacturer'));

        Collection::make('pages')->sites(['default'])->revisionsEnabled(true)->routes('/{slug}')->save();
        Collection::make('notes')->sites(['default'])->routes('/notes/{slug}')->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['tabs' => [
            'main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'lead', 'field' => ['type' => 'textarea']],
                ['handle' => 'link', 'field' => ['type' => 'text']],
                ['handle' => 'page_builder', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                    'brands' => ['fields' => [
                        ['handle' => 'heading', 'field' => ['type' => 'text']],
                        ['handle' => 'brand', 'field' => ['type' => 'dictionary', 'dictionary' => ['type' => SnapshotPagesDictionary::HANDLE, 'snapshot' => 'brands']]],
                    ]],
                    'team' => ['fields' => [
                        ['handle' => 'people', 'field' => ['type' => 'dictionary', 'dictionary' => 'staff']],
                    ]],
                ]]]]],
            ]]]],
            'seo' => ['sections' => [['fields' => [
                ['handle' => 'seo_title', 'field' => ['type' => 'text']],
                ['handle' => 'seo_noindex', 'field' => ['type' => 'toggle']],
            ]]]],
        ]])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    private function page(string $slug, array $data = [], ?string $id = null): EntryContract
    {
        $entry = Entry::make()->collection('pages')->blueprint('page')->locale('default')->slug($slug)->data(['title' => ucfirst($slug), ...$data]);

        if ($id !== null) {
            $entry->id($id);
        }

        $entry->save();

        return $entry;
    }

    /** @return list<string> */
    private function feedTitles(string $query = ''): array
    {
        return collect($this->callSealed('GET', '/api/core/v1/content'.$query, 'website.read')->assertOk()->json('data'))->pluck('title')->all();
    }

    public function test_a_page_is_projected_with_its_text_blocks_picks_and_seo(): void
    {
        $page = $this->page('about', [
            'lead' => 'We repair   cars.',
            'link' => '/contact',
            'seo_title' => 'About us | Site',
            'seo_noindex' => true,
            'page_builder' => [
                ['type' => 'brands', 'heading' => 'Our brands', 'brand' => 'b-1'],
                ['type' => 'team', 'people' => ['anna|e-7', 'e-8']],
                ['type' => 'brands', 'enabled' => false, 'heading' => 'Hidden', 'brand' => 'b-2'],
            ],
        ]);

        $data = $this->callSealed('GET', "/api/core/v1/content/{$page->id()}", 'website.read')->assertOk()->json('data');

        $this->assertSame('About', $data['title']);
        $this->assertSame('published', $data['status']);
        $this->assertSame('We repair cars. Our brands', $data['text']);
        $this->assertSame([['type' => 'brands', 'count' => 1], ['type' => 'team', 'count' => 1]], $data['blocks']);
        $this->assertSame([
            ['type' => 'manufacturer', 'id' => 'b-1', 'field' => 'page_builder.0.brand'],
            ['type' => 'employee', 'id' => 'e-7', 'field' => 'page_builder.1.people'],
            ['type' => 'employee', 'id' => 'e-8', 'field' => 'page_builder.1.people'],
        ], $data['shows']);
        $this->assertSame('About us | Site', $data['seo']['title']);
        $this->assertFalse($data['seo']['indexable']);
        $this->assertSame('pages', $data['collection']);
        $this->assertFalse($data['has_draft']);
        $this->assertSame(ContentProjection::hash($data), $data['content_hash']);
    }

    public function test_the_hash_ignores_the_change_time(): void
    {
        $page = $this->page('about');
        $first = app(ContentProjection::class)->project($page);

        $page->set('updated_at', now()->addHour()->timestamp)->save();

        $this->assertSame($first['content_hash'], app(ContentProjection::class)->project(Entry::find($page->id()))['content_hash']);
    }

    public function test_the_feed_pages_by_cursor_and_serves_only_its_collections(): void
    {
        $this->page('one');
        $this->page('two');
        $this->page('three');
        Entry::make()->collection('notes')->locale('default')->slug('note')->data(['title' => 'Note'])->save();

        $first = $this->callSealed('GET', '/api/core/v1/content?limit=2', 'website.read')->assertOk();
        $this->assertCount(2, $first->json('data'));
        $this->assertNotNull($cursor = $first->json('next_cursor'));

        $second = $this->callSealed('GET', '/api/core/v1/content?limit=2&cursor='.$cursor, 'website.read')->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertNull($second->json('next_cursor'));

        $titles = collect([...$first->json('data'), ...$second->json('data')])->pluck('title')->sort()->values()->all();
        $this->assertSame(['One', 'Three', 'Two'], $titles);
    }

    public function test_a_page_outside_the_feed_is_not_found_and_a_bad_cursor_is_refused(): void
    {
        $note = Entry::make()->collection('notes')->locale('default')->slug('note')->data(['title' => 'Note']);
        $note->save();

        $this->callSealed('GET', "/api/core/v1/content/{$note->id()}", 'website.read')->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->callSealed('GET', '/api/core/v1/content?cursor=nope', 'website.read')->assertUnprocessable();
    }

    public function test_the_feed_is_sealed_for_reading(): void
    {
        $this->assertTrue(Route::has('core-web.content.feed.index'));
        $this->getJson('/api/core/v1/content')->assertForbidden();
    }

    public function test_a_site_binds_its_own_page_status(): void
    {
        $this->app->bind(PageStatus::class, fn () => new class implements PageStatus
        {
            public function of(EntryContract $entry): string
            {
                return 'expired';
            }
        });

        $page = $this->page('campaign');

        $this->callSealed('GET', "/api/core/v1/content/{$page->id()}", 'website.read')->assertJsonPath('data.status', 'expired');
    }

    public function test_saving_or_deleting_a_served_page_pings_core(): void
    {
        $page = $this->page('about');
        $page->delete();
        Entry::make()->collection('notes')->locale('default')->slug('note')->data(['title' => 'Note'])->save();

        Queue::assertPushed(PingPageJob::class, fn (PingPageJob $job): bool => $job->id === $page->id() && $job->action === 'saved');
        Queue::assertPushed(PingPageJob::class, fn (PingPageJob $job): bool => $job->id === $page->id() && $job->action === 'deleted');
        Queue::assertPushed(PingPageJob::class, 2);
    }

    public function test_the_feed_is_ordered_by_change_time_then_id_byte_by_byte(): void
    {
        $at = now()->subDay()->timestamp;
        $this->page('late', ['updated_at' => $at + 10], 'a');
        $this->page('ten', ['updated_at' => $at], '10');
        $this->page('nine', ['updated_at' => $at], '9');

        $this->assertSame(['Ten', 'Nine', 'Late'], $this->feedTitles());
        $this->assertSame(['Ten'], $this->feedTitles('?limit=1'));
        $this->assertSame(['Late'], $this->feedTitles('?updated_since='.urlencode(now()->setTimestamp($at + 10)->toIso8601String())));
    }

    public function test_a_page_saved_between_two_reads_moves_to_the_end(): void
    {
        $at = now()->subDay()->timestamp;
        $this->page('one', ['updated_at' => $at], 'a');
        $this->page('two', ['updated_at' => $at + 1], 'b');
        $this->page('three', ['updated_at' => $at + 2], 'c');

        $first = $this->callSealed('GET', '/api/core/v1/content?limit=1', 'website.read')->assertOk();
        Entry::find('a')->set('updated_at', $at + 5)->save();
        $rest = $this->callSealed('GET', '/api/core/v1/content?cursor='.$first->json('next_cursor'), 'website.read')->assertOk();

        $this->assertSame(['One'], collect($first->json('data'))->pluck('title')->all());
        $this->assertSame(['Two', 'Three', 'One'], collect($rest->json('data'))->pluck('title')->all());
    }

    public function test_the_feed_collections_override_the_drafted_ones(): void
    {
        config(['core-web.content.feed.collections' => ['notes']]);
        $this->page('about');
        Entry::make()->collection('notes')->locale('default')->slug('note')->data(['title' => 'Note'])->save();

        $this->assertSame(['Note'], $this->feedTitles());
    }

    public function test_a_working_copy_is_a_draft_and_its_save_pings_core(): void
    {
        $page = $this->page('about', ['lead' => 'Live text']);
        Queue::fake();

        $page->fromWorkingCopy()->set('lead', 'Draft text')->makeWorkingCopy()->user(null)->save();

        $data = $this->callSealed('GET', "/api/core/v1/content/{$page->id()}", 'website.read')->assertOk()->json('data');
        $this->assertTrue($data['has_draft']);
        $this->assertNotNull($data['working_copy']);
        $this->assertSame('Draft text', $data['fields']['lead']);
        $this->assertSame('Live text', Entry::find($page->id())->get('lead'));
        Queue::assertPushed(PingPageJob::class, fn (PingPageJob $job): bool => $job->id === $page->id() && $job->action === 'saved');
    }

    public function test_a_scheduled_page_going_live_pings_core(): void
    {
        $page = $this->page('campaign');
        Queue::fake();

        EntryScheduleReached::dispatch($page);

        Queue::assertPushed(PingPageJob::class, fn (PingPageJob $job): bool => $job->id === $page->id() && $job->action === 'saved');
    }

    public function test_a_site_without_a_connector_sends_no_ping(): void
    {
        config(['core-web.content.connector_id' => '']);

        $this->page('about');

        Queue::assertNotPushed(PingPageJob::class);
    }
}
