<?php

namespace Inly\CoreWeb\Widgets;

use Collator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Statamic\Facades\Site;
use Statamic\Widgets\Widget;
use Throwable;

/**
 * The `core_snapshot` dashboard widget: one snapshot's documents, a page at a
 * time, each linked to its record in Core. Reads only the site's cached copy:
 * a dashboard visit never pulls from Core, and a snapshot with nothing cached
 * yet queues its warm job and says so. Only snapshots
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
        $loaded = false;

        foreach ($partitions as $partition) {
            // The cached copy only: a dashboard visit never waits on a pull from Core.
            $read = $store->cached($snapshot, $partition);

            if ($read === null) {
                continue;
            }

            $loaded = true;
            $stale = $stale || $read->stale;

            foreach ($read->documents as $id => $document) {
                $rows[] = $this->row($snapshot, (array) $document, (string) $id, $core) + ['partition' => $partition];
            }
        }

        if (! $loaded) {
            // Unique per snapshot until it runs, so a busy dashboard queues one pull.
            dispatch($registry->job($type));
        }

        $this->sort($rows);

        $perPage = (int) $this->config('per_page', 10);
        $perPage = $perPage < 1 ? 10 : min(self::MAX_PER_PAGE, $perPage);
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $param = $this->pageParameter($type, $this->config('partition'));
        $page = max(1, min($pages, (int) request()->query($param, 1)));

        return view('core-web::widgets.snapshot', [
            'title' => __((string) $this->config('title', Str::headline($type))),
            'icon' => (string) $this->config('icon', 'link'),
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => count($rows),
            'page' => $page,
            'pages' => $pages,
            'previousUrl' => $page > 1 ? request()->fullUrlWithQuery([$param => $page - 1]) : null,
            'nextUrl' => $page < $pages ? request()->fullUrlWithQuery([$param => $page + 1]) : null,
            'showPartition' => count($partitions) > 1,
            'loaded' => $loaded,
            'pinned' => $loaded && $store->pinned($snapshot) !== null,
            'stale' => $stale,
        ]);
    }

    /**
     * A row by its id when the snapshot's listing closures fail on a document
     * (an older cached shape, say): one bad document must not take the whole
     * dashboard down with it.
     *
     * @param  array<string, mixed>  $document
     * @return array{id: string, title: string, core_url: ?string}
     */
    private function row(Snapshot $snapshot, array $document, string $id, CoreClient $core): array
    {
        try {
            return $snapshot->listingRow($document, $id, $core);
        } catch (Throwable $e) {
            Log::warning('Core snapshot listing failed for a document', ['snapshot' => $snapshot->type, 'id' => $id, 'exception' => $e::class]);

            return ['id' => $id, 'title' => $id, 'core_url' => null];
        }
    }

    /**
     * By title in the selected site's locale when intl is there (Å, Ä, Ö sort as Swedish
     * does), byte-wise otherwise; then by id, so the pages are stable.
     *
     * @param  list<array{id: string, title: string}>  $rows
     */
    private function sort(array &$rows): void
    {
        $collator = class_exists(Collator::class) ? new Collator((string) (Site::selected()?->locale() ?? app()->getLocale())) : null;
        $collator?->setStrength(Collator::SECONDARY);
        $collator?->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);

        usort($rows, fn (array $a, array $b): int => ($collator?->compare($a['title'], $b['title']) ?: strnatcasecmp($a['title'], $b['title'])) ?: strcmp($a['id'], $b['id']));
    }

    /** One query parameter per snapshot and partition, so the widgets on one dashboard page independently. */
    public function pageParameter(string $type, mixed $partition = null): string
    {
        $name = is_scalar($partition) && (string) $partition !== '' ? "{$type}_{$partition}" : $type;

        return 'core_'.trim((string) preg_replace('~[^a-z0-9]+~', '_', strtolower($name)), '_').'_page';
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
