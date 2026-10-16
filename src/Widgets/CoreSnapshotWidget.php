<?php

namespace Inly\CoreWeb\Widgets;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Statamic\Widgets\Widget;

/**
 * The `core_snapshot` dashboard widget: one snapshot's documents, a page at a
 * time, each linked to its record in Core. Reads the site's own copy, so a
 * dashboard visit asks Core nothing while the copy is cached. Only snapshots
 * that declare a listing (Snapshot::listing()) can be shown.
 *
 *     ['type' => 'core_snapshot', 'snapshot' => 'staff', 'per_page' => 10, 'width' => 50]
 */
class CoreSnapshotWidget extends Widget
{
    public const MAX_PER_PAGE = 100;

    public function html(): View|string
    {
        $type = (string) $this->config('snapshot', '');
        $registry = app(SnapshotRegistry::class);

        if ($type === '' || ! $registry->has($type)) {
            return $this->error("Core snapshot [{$type}] is not registered.");
        }

        $snapshot = $registry->get($type);

        if (! $snapshot->listed()) {
            return $this->error("Core snapshot [{$type}] declares no listing (Snapshot::listing()).");
        }

        $partitions = $this->partitions($snapshot);

        if ($partitions === null) {
            return $this->error("Core snapshot [{$type}] has no partition [{$this->config('partition')}].");
        }

        $store = app(SnapshotStore::class);
        $core = CoreClient::fromConfig();
        $rows = [];
        $stale = false;
        $degraded = false;

        foreach ($partitions as $partition) {
            $read = $store->read($snapshot, $partition);
            $stale = $stale || $read->stale;
            $degraded = $degraded || $read->degraded;

            foreach ($read->documents as $id => $document) {
                $rows[] = $snapshot->listingRow((array) $document, (string) $id, $core) + ['partition' => $partition];
            }
        }

        usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']) ?: strcmp($a['id'], $b['id']));

        $perPage = max(1, min(self::MAX_PER_PAGE, (int) $this->config('per_page', 10)));
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $param = $this->pageParameter($type);
        $page = max(1, min($pages, (int) request()->query($param, 1)));

        return view('core-web::widgets.snapshot', [
            'title' => (string) $this->config('title', Str::headline($type)),
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => count($rows),
            'page' => $page,
            'pages' => $pages,
            'previousUrl' => $page > 1 ? request()->fullUrlWithQuery([$param => $page - 1]) : null,
            'nextUrl' => $page < $pages ? request()->fullUrlWithQuery([$param => $page + 1]) : null,
            'showPartition' => count($partitions) > 1,
            'pinned' => $store->pinned($snapshot) !== null,
            'stale' => $stale,
            'degraded' => $degraded,
        ]);
    }

    /** One query parameter per snapshot, so two of these widgets on one dashboard page independently. */
    public function pageParameter(string $type): string
    {
        return 'core_'.Str::snake(str_replace('-', '_', $type)).'_page';
    }

    /**
     * The partitions to read: the configured one, every partition of a
     * partitioned snapshot, or the single unpartitioned copy. Null when the
     * configured partition does not exist.
     *
     * @return list<?string>|null
     */
    private function partitions(Snapshot $snapshot): ?array
    {
        $all = $snapshot->partitions();
        $configured = $this->config('partition');

        if ($configured !== null && $configured !== '') {
            return in_array((string) $configured, $all, true) ? [(string) $configured] : null;
        }

        return $all;
    }

    private function error(string $message): string
    {
        return '<ui-widget><div class="px-4 py-3 text-sm text-red-600" v-pre>'.e($message).'</div></ui-widget>';
    }
}
