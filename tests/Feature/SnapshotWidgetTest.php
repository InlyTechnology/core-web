<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Inly\CoreWeb\Snapshots\RefreshSnapshotJob;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\TestCase;
use Inly\CoreWeb\Widgets\CoreSnapshotWidget;
use Statamic\Widgets\Loader;

/** The `core_snapshot` dashboard widget: a snapshot's documents, a page at a time, linked to Core. */
class SnapshotWidgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['core-web.client.base_url' => 'https://core.test']);
    }

    /** @param  array<string, array<string, mixed>>  $documents */
    private function people(array $documents): Snapshot
    {
        $snapshot = Snapshot::make('staff')
            ->pull(fn () => $documents)
            ->listing(
                title: fn (array $doc) => $doc['name'] ?? null,
                coreType: 'employee',
                coreId: fn (array $doc) => $doc['core_id'] ?? null,
            );

        app(SnapshotRegistry::class)->register($snapshot);
        app(SnapshotStore::class)->refresh($snapshot);

        return $snapshot;
    }

    /** @param  array<string, mixed>  $config */
    private function render(array $config): string
    {
        return (string) app(Loader::class)->load('core_snapshot', ['type' => 'core_snapshot', ...$config])->html();
    }

    public function test_it_is_registered_with_the_snapshots_feature(): void
    {
        $this->assertSame(CoreSnapshotWidget::class, app('statamic.widgets')->get('core_snapshot'));
    }

    public function test_it_lists_documents_by_title_with_links_to_core(): void
    {
        $this->people([
            'p-2' => ['name' => 'Örjan', 'core_id' => 12],
            'p-1' => ['name' => 'Anna', 'core_id' => 7],
            'p-3' => ['name' => 'No Core id'],
        ]);

        $html = $this->render(['snapshot' => 'staff', 'title' => 'Our staff', 'icon' => 'users']);

        $this->assertStringContainsString('title="Our staff" icon="users"', $html);
        $this->assertStringContainsString('3 items', $html);
        $this->assertStringContainsString('href="https://core.test/open/employee/7"', $html);
        $this->assertStringContainsString('href="https://core.test/open/employee/12"', $html);
        $this->assertSame(2, substr_count($html, 'text="View"'));
        $this->assertLessThan(strpos($html, 'No Core id'), strpos($html, 'Anna'));
        $this->assertLessThan(strpos($html, 'Örjan'), strpos($html, 'No Core id'));
        $this->assertStringNotContainsString('Page 1 of', $html);
    }

    public function test_it_pages_with_its_own_query_parameter(): void
    {
        $documents = [];
        foreach (range(1, 25) as $n) {
            $documents["p-{$n}"] = ['name' => sprintf('Person %02d', $n), 'core_id' => $n];
        }
        $this->people($documents);

        $this->app->instance('request', Request::create('http://default.test/cp/dashboard?other=1'));
        $first = $this->render(['snapshot' => 'staff', 'per_page' => 10]);

        $this->assertStringContainsString('Page 1 of 3', $first);
        $this->assertStringContainsString('Person 10', $first);
        $this->assertStringNotContainsString('Person 11', $first);
        $this->assertStringContainsString('core_staff_page=2', $first);
        $this->assertStringContainsString('other=1', $first);

        request()->query->set('core_staff_page', '2');
        $middle = $this->render(['snapshot' => 'staff', 'per_page' => 10]);
        $this->assertStringContainsString('core_staff_page=1', $middle);
        $this->assertStringContainsString('core_staff_page=3', $middle);
        $this->assertStringContainsString('Person 11', $middle);

        request()->query->set('core_staff_page', '3');
        $last = $this->render(['snapshot' => 'staff', 'per_page' => 10]);

        $this->assertStringContainsString('Page 3 of 3', $last);
        $this->assertStringContainsString('Person 25', $last);
        $this->assertStringNotContainsString('Person 20', $last);
        $this->assertStringContainsString('core_staff_page=2', $last);
        $this->assertStringNotContainsString('core_staff_page=4', $last);

        request()->query->set('core_staff_page', '99');
        $this->assertStringContainsString('Page 3 of 3', $this->render(['snapshot' => 'staff', 'per_page' => 10]));
    }

    public function test_core_text_cannot_become_vue_template_or_html(): void
    {
        $this->people(['p-1' => ['name' => '{{ alert(1) }} <b>bold</b>', 'core_id' => 1]]);

        $html = $this->render(['snapshot' => 'staff']);

        $this->assertMatchesRegularExpression('~<span[^>]*v-pre>\{\{ alert\(1\) \}\} &lt;b&gt;bold&lt;/b&gt;</span>~', $html);
    }

    public function test_its_labels_are_translated(): void
    {
        $this->people(['a' => ['name' => 'Anna', 'core_id' => 1], 'b' => ['name' => 'Bo', 'core_id' => 2]]);
        app()->forgetScopedInstances();
        app()->setLocale('sv');

        $html = $this->render(['snapshot' => 'staff', 'per_page' => 1]);

        foreach (['2 poster', 'text="Visa"', 'Sida 1 av 2', 'Föregående sida', 'Nästa sida'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_its_empty_state_is_translated(): void
    {
        $this->people([]);
        app()->forgetScopedInstances();
        app()->setLocale('sv');

        // Load the package's sv lines first: added lines would otherwise mark the locale loaded and hide them.
        __('No results');
        app('translator')->addLines(['*.Staff from Core' => 'Personal från Core'], 'sv');

        $html = $this->render(['snapshot' => 'staff', 'title' => 'Staff from Core']);

        $this->assertStringContainsString('Inga resultat', $html);
        $this->assertStringContainsString('title="Personal från Core"', $html);
    }

    public function test_it_says_when_the_copy_cannot_be_trusted(): void
    {
        $snapshot = $this->people(['p-1' => ['name' => 'Anna', 'core_id' => 1]]);
        $store = app(SnapshotStore::class);
        $store->refresh($snapshot);
        app()->forgetScopedInstances();

        $this->assertStringNotContainsString('May be out of date', $this->render(['snapshot' => 'staff']));

        SnapshotStore::repository()->forget($snapshot->key('fresh'));
        app()->forgetScopedInstances();
        $this->assertStringContainsString('May be out of date', $this->render(['snapshot' => 'staff']));

        SnapshotStore::repository()->forever($snapshot->key('pinned'), ['source' => 'https://prod.test', 'at' => '2026-10-16T10:00:00Z']);
        app()->forgetScopedInstances();
        $this->assertStringContainsString('Local copy', $this->render(['snapshot' => 'staff']));
    }

    public function test_a_cold_snapshot_is_never_pulled_by_the_dashboard_but_queued(): void
    {
        Queue::fake();
        $pulls = 0;
        app(SnapshotRegistry::class)->register(Snapshot::make('cold')
            ->pull(function () use (&$pulls) {
                $pulls++;

                return ['a' => ['name' => 'Alpha']];
            })
            ->listing(title: fn (array $doc) => $doc['name']));

        $html = $this->render(['snapshot' => 'cold']);

        $this->assertSame(0, $pulls);
        $this->assertStringContainsString('text="Loading"', $html);
        $this->assertStringNotContainsString('items"', $html);
        Queue::assertPushed(RefreshSnapshotJob::class, fn (RefreshSnapshotJob $job) => $job->type === 'cold' && $job->id === 'all');
    }

    public function test_a_document_the_listing_cannot_read_shows_its_id(): void
    {
        $snapshot = Snapshot::make('odd')
            ->pull(fn () => ['a' => ['name' => 'Alpha'], 'b' => []])
            ->listing(title: fn (array $doc) => $doc['name'], coreType: 'thing');
        app(SnapshotRegistry::class)->register($snapshot);
        app(SnapshotStore::class)->refresh($snapshot);

        $html = $this->render(['snapshot' => 'odd']);

        $this->assertStringContainsString('v-pre>Alpha</span>', $html);
        $this->assertStringContainsString('v-pre>b</span>', $html);
        $this->assertSame(1, substr_count($html, 'text="View"'));
    }

    public function test_it_reads_every_partition_unless_one_is_named(): void
    {
        $places = Snapshot::make('places')
            ->pull(fn () => ['a' => ['name' => 'Alpha', 'site' => 'default'], 'b' => ['name' => 'Beta', 'site' => '{{ x }}']])
            ->partitionBy(fn (array $doc) => $doc['site'], ['default', '{{ x }}'])
            ->listing(title: fn (array $doc) => $doc['name'], coreType: 'facility');
        app(SnapshotRegistry::class)->register($places);
        app(SnapshotStore::class)->refresh($places);

        $all = $this->render(['snapshot' => 'places']);
        $this->assertMatchesRegularExpression('~<td[^>]*v-pre>\{\{ x \}\}</td>~', $all);
        $this->assertStringContainsString('Alpha', $all);
        $this->assertStringContainsString('Beta', $all);
        $this->assertStringContainsString('href="https://core.test/open/facility/a"', $all);

        $other = $this->render(['snapshot' => 'places', 'partition' => '{{ x }}']);
        $this->assertStringNotContainsString('Alpha', $other);
        $this->assertStringContainsString('Beta', $other);

        $this->assertStringContainsString('has no partition [nope]', $this->render(['snapshot' => 'places', 'partition' => 'nope']));
    }

    public function test_it_refuses_a_snapshot_it_cannot_list(): void
    {
        app(SnapshotRegistry::class)->register(Snapshot::make('quiet')->pull(fn () => []));

        $this->assertStringContainsString('declares no listing', $this->render(['snapshot' => 'quiet']));
        $this->assertStringContainsString('is not registered', $this->render(['snapshot' => 'missing']));
    }

    public function test_each_snapshot_and_partition_pages_on_its_own_parameter(): void
    {
        $widget = new CoreSnapshotWidget;

        $this->assertSame('core_staff_page', $widget->pageParameter('staff'));
        $this->assertSame('core_marine_catalog_page', $widget->pageParameter('marine-catalog'));
        $this->assertSame('core_places_other_page', $widget->pageParameter('places', 'other'));
        $this->assertSame('core_a_b_page', $widget->pageParameter('a.b'));
    }

    public function test_titles_sort_as_the_site_s_language_does(): void
    {
        $this->people(['1' => ['name' => 'Örjan'], '2' => ['name' => 'ärla'], '3' => ['name' => 'Åsa'], '4' => ['name' => 'Zack']]);

        $html = $this->render(['snapshot' => 'staff']);
        preg_match_all('~v-pre>([^<]+)</span>~', $html, $titles);

        $this->assertSame(['Zack', 'Åsa', 'ärla', 'Örjan'], $titles[1]);
    }

    public function test_a_document_without_a_title_shows_its_id(): void
    {
        $this->people(['p-1' => ['core_id' => 1]]);

        $this->assertStringContainsString('v-pre>p-1</span>', $this->render(['snapshot' => 'staff']));
    }

    public function test_it_reads_the_site_s_copy_without_asking_core_again(): void
    {
        $pulls = 0;
        app(SnapshotRegistry::class)->register(Snapshot::make('counted')
            ->pull(function () use (&$pulls) {
                $pulls++;

                return ['a' => ['name' => 'Alpha']];
            })
            ->listing(title: fn (array $doc) => $doc['name']));

        app(SnapshotStore::class)->refresh(app(SnapshotRegistry::class)->get('counted'));
        app()->forgetScopedInstances();
        $this->render(['snapshot' => 'counted']);

        $this->assertSame(1, $pulls);
    }
}
