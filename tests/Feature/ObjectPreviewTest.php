<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFactory;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotPage;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;

/** Statamic connector API v1.5 and v1.6: the records the site serves a page for, and Core's preview of one. */
class ObjectPreviewTest extends TestCase
{
    use FakesCore;

    protected array $features = ['snapshots', 'content', 'preview'];

    private const STATE = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV';

    /** @var array<string, array<string, mixed>> */
    private array $documents = [
        'c7' => ['core_id' => 7, 'slug' => 'spring', 'site' => 'default', 'title' => 'Spring', 'blocks' => ['One', 'Two']],
        'c8' => ['core_id' => 8, 'slug' => 'summer', 'site' => 'other', 'title' => 'Summer'],
        'c9' => ['core_id' => 9, 'slug' => 'hidden', 'site' => 'default', 'title' => 'Hidden', 'hidden' => true],
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Statamic's catch-all would answer the route this test adds after it.
        $app['config']->set('statamic.routes.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        ViewFactory::addLocation(__DIR__.'/../fixtures/views');
        $this->fakeCore(['core.test/api/v1/objects/campaign/*' => $this->coreAnswer(...)]);

        app(SnapshotRegistry::class)->register(Snapshot::make('campaigns')
            ->pull(fn () => $this->documents)
            ->pullOne(function (string $id): array {
                $row = CoreClient::fromConfig()->object('campaign', $id)['data']['attributes'] ?? null;

                return $row === null ? [] : ['c'.$id => $row];
            })
            ->partitionBy(fn (array $doc) => $doc['site'], ['default', 'other'])
            ->pages(fn (array $doc) => ($doc['hidden'] ?? false) ? null : '/kampanjer/'.$doc['slug'])
            ->coreObject('demo/campaign', fn (array $doc) => $doc['core_id'], alias: 'campaign'));

        Route::get('kampanjer/{slug}', function (Request $request, string $slug) {
            $pages = SnapshotPage::for('campaigns');
            $campaign = $pages->find(fn (array $doc) => $doc['slug'] === $slug);
            $titles = collect(app(SnapshotStore::class)->documents(app(SnapshotRegistry::class)->get('campaigns'), 'default'))->pluck('title')->implode(',');

            return $pages->render('campaign-preview', ['campaign' => $campaign, 'others' => $titles]);
        })->middleware('web');
    }

    /** Core's Objects API: the unsaved values while the read carries a state it applies. */
    private function coreAnswer(HttpRequest $request): mixed
    {
        $applies = $request->header('X-Core-Preview') === [self::STATE];
        $title = $applies ? 'Spring, unsaved' : 'Spring';
        $slug = $applies ? 'spring-sale' : 'spring';

        return Http::response(
            ['data' => ['type' => 'campaign', 'id' => '7', 'attributes' => ['core_id' => 7, 'slug' => $slug, 'site' => 'default', 'title' => $title]]],
            200,
            $request->hasHeader('X-Core-Preview') ? ['X-Core-Preview-Applied' => $applies ? 'true' : 'false'] : [],
        );
    }

    public function test_core_reads_every_record_with_a_page_by_its_alias_and_id(): void
    {
        $this->callSealed('GET', '/api/core/v1/object-pages', 'website.read')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['type' => 'campaign', 'id' => '7', 'pages' => [['site' => 'default', 'site_name' => 'Default', 'url' => 'http://default.test/kampanjer/spring']]],
                ['type' => 'campaign', 'id' => '8', 'pages' => [['site' => 'other', 'site_name' => 'Other', 'url' => 'http://other.test/kampanjer/summer']]],
            ], 'next_cursor' => null]);

        $first = $this->callSealed('GET', '/api/core/v1/object-pages?limit=1', 'website.read')->assertOk();
        $this->assertSame('7', $first->json('data.0.id'));

        $this->callSealed('GET', '/api/core/v1/object-pages?limit=1&cursor='.$first->json('next_cursor'), 'website.read')
            ->assertOk()
            ->assertJsonPath('data.0.id', '8')
            ->assertJsonPath('next_cursor', null);
    }

    public function test_core_reads_one_record_and_a_record_without_a_page_is_not_found(): void
    {
        $this->callSealed('GET', '/api/core/v1/object-page?type=demo/campaign&id=8', 'website.read')
            ->assertOk()
            ->assertJsonPath('data.pages.0.url', 'http://other.test/kampanjer/summer');

        $this->callSealed('GET', '/api/core/v1/object-page?type=campaign&id=9', 'website.read')
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');

        $this->getJson('/api/core/v1/object-pages')->assertForbidden();
        $this->callSealed('GET', '/api/core/v1/object-pages?cursor=nope', 'website.read')->assertUnprocessable();
    }

    public function test_a_saved_record_is_framed_by_core_alone_with_its_markers_and_never_cached(): void
    {
        $response = $this->get('/core-preview/object?type=campaign&id=7&site=default')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', 'frame-ancestors https://core.test');

        $html = (string) $response->getContent();
        $this->assertStringContainsString('<h1 data-core-field="title" data-core-editable>Spring</h1>', $html);
        $this->assertStringContainsString('<section data-core-block="1">Two</section>', $html);
        $this->assertStringContainsString('core-preview:focus', $html);
        $this->assertStringContainsString('"https:\/\/core.test"', $html);
        Http::assertNotSent(fn (HttpRequest $request) => $request->hasHeader('X-Core-Preview'));

        $public = (string) $this->get('/kampanjer/spring')->assertOk()->getContent();
        $this->assertStringContainsString('<h1 >Spring</h1>', $public);
        $this->assertStringNotContainsString('data-core', $public);
        $this->assertStringNotContainsString('core-preview:', $public);
    }

    public function test_an_applied_state_renders_the_unsaved_values_for_that_request_only(): void
    {
        $html = (string) $this->get('/core-preview/object?type=campaign&id=7&site=default&state='.self::STATE)->assertOk()->getContent();

        $this->assertStringContainsString('>Spring, unsaved</h1>', $html);
        $this->assertStringContainsString('<p>Spring, unsaved,Hidden</p>', $html);
        Http::assertSent(fn (HttpRequest $request) => $request->header('X-Core-Preview') === [self::STATE]);

        $this->get('/kampanjer/spring')->assertOk()->assertSee('<h1 >Spring</h1>', false)->assertSee('<p>Spring,Hidden</p>', false);
        $this->get('/kampanjer/spring-sale')->assertNotFound();
    }

    public function test_a_state_core_did_not_apply_shows_only_a_public_page(): void
    {
        $this->get('/core-preview/object?type=campaign&id=7&site=default&state='.str_repeat('z', 48))
            ->assertOk()
            ->assertSee('>Spring</h1>', false);

        $this->get('/core-preview/object?type=campaign&id=9&site=default&state='.str_repeat('z', 48))->assertNotFound();
        $this->get('/core-preview/object?type=campaign&id=9&site=default')->assertNotFound();
    }

    public function test_an_unknown_type_site_or_malformed_state_is_refused(): void
    {
        $this->get('/core-preview/object?type=product&id=7&site=default')->assertNotFound();
        $this->get('/core-preview/object?type=campaign&id=7&site=nowhere')->assertNotFound();
        $this->get('/core-preview/object?type=campaign&id=8&site=default')->assertNotFound();
        $this->getJson('/core-preview/object?type=campaign&id=7&site=default&state=short')->assertUnprocessable();
    }
}
