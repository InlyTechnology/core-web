<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Request;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\TestCase;
use Inly\CoreWeb\Widgets\CoreSnapshotWidget;
use RuntimeException;
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

        $html = $this->render(['snapshot' => 'staff', 'title' => 'Our staff']);

        $this->assertStringContainsString('title="Our staff"', $html);
        $this->assertStringContainsString('3 items', $html);
        $this->assertStringContainsString('href="https://core.test/open/employee/7"', $html);
        $this->assertStringContainsString('href="https://core.test/open/employee/12"', $html);
        $this->assertSame(2, substr_count($html, 'Open in Core'));
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

    public function test_it_says_when_the_copy_cannot_be_trusted(): void
    {
        $this->people([]);
        app(SnapshotRegistry::class)->register(Snapshot::make('broken')
            ->pull(fn () => throw new RuntimeException('Core is down'))
            ->listing(title: fn (array $doc) => $doc['name']));

        $this->assertStringContainsString('Nothing in this snapshot', $this->render(['snapshot' => 'staff']));
        $this->assertStringContainsString('Core unreachable', $this->render(['snapshot' => 'broken']));
    }

    public function test_it_reads_every_partition_unless_one_is_named(): void
    {
        app(SnapshotRegistry::class)->register(Snapshot::make('places')
            ->pull(fn () => ['a' => ['name' => 'Alpha', 'site' => 'default'], 'b' => ['name' => 'Beta', 'site' => 'other']])
            ->partitionBy(fn (array $doc) => $doc['site'], ['default', 'other'])
            ->listing(title: fn (array $doc) => $doc['name'], coreType: 'facility'));

        $all = $this->render(['snapshot' => 'places']);
        $this->assertStringContainsString('Alpha', $all);
        $this->assertStringContainsString('Beta', $all);
        $this->assertStringContainsString('href="https://core.test/open/facility/a"', $all);

        $other = $this->render(['snapshot' => 'places', 'partition' => 'other']);
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
