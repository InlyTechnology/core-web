<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFactory;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotPage;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Tests\TestCase;
use LogicException;
use RuntimeException;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Cascade;
use Statamic\Facades\Site;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SnapshotPageTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $documents = [
        'a' => ['id' => 'a', 'slug' => 'first', 'site' => 'default', 'title' => 'First'],
        'b' => ['id' => 'b', 'slug' => 'second', 'site' => 'other', 'title' => 'Second'],
        'c' => ['id' => 'c', 'slug' => 'draft', 'site' => 'default', 'title' => 'Draft', 'hidden' => true],
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
    }

    private function things(): Snapshot
    {
        $snapshot = Snapshot::make('things')
            ->pull(fn () => $this->documents)
            ->partitionBy(fn (array $doc) => $doc['site'], ['default', 'other'])
            ->pages(fn (array $doc, string $site) => ($doc['hidden'] ?? false) ? null : '/things/'.$doc['slug']);

        app(SnapshotRegistry::class)->register($snapshot);

        return $snapshot;
    }

    private function route(): void
    {
        Route::get('things/{slug}', function (Request $request, string $slug) {
            $pages = SnapshotPage::for('things');
            $thing = $pages->find(fn (array $doc) => $doc['slug'] === $slug || ($doc['old_slug'] ?? null) === $slug);

            return $pages->canonical($request, $thing) ?? $pages->render('snapshot-page', ['title' => $thing['title']]);
        })->middleware('web');
    }

    public function test_finds_a_document_by_id_or_by_a_closure(): void
    {
        $this->things();
        $pages = SnapshotPage::for('things');

        $this->assertSame('First', $pages->find('a')['title']);
        $this->assertSame('First', $pages->find(fn (array $doc, string $id) => $id === 'a')['title']);
        $this->assertSame('/things/first', $pages->path($this->documents['a']));
        $this->assertSame('Second', SnapshotPage::for('things', 'other')->find('b')['title']);
    }

    public function test_a_closure_only_picks_among_documents_with_a_page(): void
    {
        $this->documents['c']['slug'] = 'first';
        $this->documents = ['c' => $this->documents['c']] + $this->documents;
        $this->things();

        $this->assertSame('First', SnapshotPage::for('things')->find(fn (array $doc) => $doc['slug'] === 'first')['title']);
    }

    public function test_a_non_ascii_page_path_is_percent_encoded_in_the_redirect(): void
    {
        $this->documents['a']['slug'] = 'göteborg';
        $this->things();

        $request = Request::create('http://default.test/things/old');

        $this->assertSame(
            'http://default.test/things/g%C3%B6teborg',
            SnapshotPage::for('things')->canonical($request, $this->documents['a'])->getTargetUrl(),
        );
        $this->assertNull(SnapshotPage::for('things')->canonical(Request::create('http://default.test/things/g%C3%B6teborg'), $this->documents['a']));
    }

    public function test_a_document_missing_from_the_sites_partition_or_without_a_page_is_statamics_404(): void
    {
        $this->things();

        foreach (['nope', 'b', 'c'] as $id) {
            try {
                SnapshotPage::for('things')->find($id);
                $this->fail("[{$id}] was found");
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_site_the_snapshot_has_no_partition_for_is_a_404(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'third' => ['name' => 'Third', 'url' => 'http://third.test/', 'locale' => 'sv_SE'],
        ]);
        $this->things();

        $this->expectException(NotFoundHttpException::class);

        SnapshotPage::for('things', 'third')->find('a');
    }

    public function test_a_missing_document_is_a_503_while_nothing_can_be_read(): void
    {
        app(SnapshotRegistry::class)->register(Snapshot::make('down')
            ->pull(fn () => throw new RuntimeException('down'))
            ->pages(fn (array $doc) => '/down/'.$doc['id']));

        try {
            SnapshotPage::for('down')->find('a');
            $this->fail('a degraded read was a 404');
        } catch (HttpException $e) {
            $this->assertNotInstanceOf(NotFoundHttpException::class, $e);
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame((string) SnapshotPage::RETRY_AFTER, $e->getHeaders()['Retry-After'] ?? null);
        }
    }

    public function test_a_request_at_another_path_is_sent_to_the_documents_own_page(): void
    {
        $this->documents['a']['old_slug'] = 'renamed';
        $this->things();
        $this->route();

        $this->get('http://default.test/things/renamed?utm_source=mail')
            ->assertStatus(301)
            ->assertRedirect('http://default.test/things/first?utm_source=mail');

        $request = Request::create('http://default.test/things/renamed?utm_source=mail');
        $this->assertSame(
            'http://default.test/things/first',
            SnapshotPage::for('things')->canonical($request, $this->documents['a'], keepQuery: false)->getTargetUrl(),
        );

        $this->assertNull(SnapshotPage::for('things')->canonical(Request::create('http://default.test/things/first'), $this->documents['a']));
    }

    public function test_a_trailing_slash_is_sent_to_the_page_without_one(): void
    {
        $this->things();

        $this->assertSame(
            'http://default.test/things/first?utm_source=mail',
            SnapshotPage::for('things')->canonical(Request::create('http://default.test/things/first/?utm_source=mail'), $this->documents['a'])->getTargetUrl(),
        );
    }

    public function test_a_gone_page_renders_with_a_410_and_an_error_cascade(): void
    {
        $this->things();
        $pages = SnapshotPage::for('things');

        $response = $pages->gone('snapshot-page', ['title' => 'Sold']);

        $this->assertSame(410, $response->getStatusCode());
        $this->assertStringContainsString('|Sold|default', $response->getContent());
        $this->assertSame(410, Cascade::instance()->get('response_code'));

        // The 410 is for that one render: the next page is a 200 again.
        $pages->render('snapshot-page', ['title' => 'Live'])->render();
        $this->assertSame(200, Cascade::instance()->get('response_code'));
    }

    public function test_a_page_at_the_root_of_a_site_under_a_path_is_not_redirected_to_itself(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://default.test/other/', 'locale' => 'sv_SE'],
        ]);
        $snapshot = Snapshot::make('roots')
            ->pull(fn () => ['r' => ['id' => 'r', 'site' => 'other']])
            ->partitionBy(fn (array $doc) => $doc['site'], ['default', 'other'])
            ->pages(fn () => '/');
        app(SnapshotRegistry::class)->register($snapshot);

        $this->assertNull(SnapshotPage::for('roots', 'other')->canonical(Request::create('http://default.test/other/'), ['id' => 'r', 'site' => 'other']));
    }

    public function test_the_canonical_path_is_on_a_site_under_a_path_of_a_shared_host(): void
    {
        Site::setSites([
            'default' => ['name' => 'Default', 'url' => 'http://default.test/', 'locale' => 'sv_SE'],
            'other' => ['name' => 'Other', 'url' => 'http://default.test/other/', 'locale' => 'sv_SE'],
        ]);
        $this->things();
        $pages = SnapshotPage::for('things', 'other');

        $this->assertNull($pages->canonical(Request::create('http://default.test/other/things/second'), $this->documents['b']));
        $this->assertSame(
            'http://default.test/other/things/second',
            $pages->canonical(Request::create('http://default.test/other/things/old'), $this->documents['b'])->getTargetUrl(),
        );
    }

    public function test_a_page_renders_through_statamic_with_the_cascade(): void
    {
        $this->things();
        $this->route();

        $this->get('http://default.test/things/first')->assertOk()->assertSee('|First|default');

        $view = SnapshotPage::for('things')->render('snapshot-page', ['title' => 'First'], collect(['eyebrow' => 'From the entry', 'title' => 'Entry title']));

        $this->assertSame('From the entry|First|default', trim($view->render()));
    }

    public function test_a_snapshot_without_pages_cannot_be_used_as_one(): void
    {
        app(SnapshotRegistry::class)->register(Snapshot::make('plain')->pull(fn () => ['a' => ['id' => 'a']]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Snapshot [plain] has no pages');

        SnapshotPage::for('plain');
    }
}
