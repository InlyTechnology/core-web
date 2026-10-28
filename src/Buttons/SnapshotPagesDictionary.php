<?php

namespace Inly\CoreWeb\Buttons;

use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Statamic\Dictionaries\BasicDictionary;
use Statamic\Facades\Site;
use Statamic\Statamic;
use Throwable;

/**
 * The documents of one snapshot that have a page on the selected site, as a
 * field's options (`dictionary: {type: core_snapshot_pages, snapshot: brands}`),
 * stored by document id and labelled by the snapshot's listing title. A
 * button's Core link picks from it without a Dictionary of the site's own.
 */
class SnapshotPagesDictionary extends BasicDictionary
{
    public const HANDLE = 'core_snapshot_pages';

    protected static $handle = self::HANDLE;

    protected array $searchable = ['label', 'value'];

    protected array $keywords = ['core', 'snapshot', 'page'];

    protected function fieldItems()
    {
        return [
            'snapshot' => [
                'type' => 'text',
                'display' => 'Snapshot',
                'instructions' => 'The type of a registered snapshot that declares pages.',
            ],
        ];
    }

    protected function getItems(): array
    {
        $type = (string) ($this->config['snapshot'] ?? '');
        $registry = app(SnapshotRegistry::class);

        if ($type === '' || ! $registry->has($type) || ! $registry->get($type)->hasPages()) {
            return [];
        }

        $snapshot = $registry->get($type);
        // Site::selected() is the control panel's pick and falls back to the default site, so the front end asks for the site it serves.
        $site = Statamic::isCpRoute() ? Site::selected()->handle() : Site::current()->handle();
        $partition = $snapshot->partitionFor($site);

        if ($partition === false) {
            return [];
        }

        try {
            $documents = app(SnapshotStore::class)->read($snapshot, $partition)->documents;
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $items = [];

        foreach ($documents as $id => $document) {
            $id = (string) $id;

            if (! is_array($document) || $snapshot->pagePath($document, $site) === null) {
                continue;
            }

            $items[] = ['value' => $id, 'label' => $snapshot->listed() ? $snapshot->listingTitle($document, $id) : self::documentTitle($document, $id)];
        }

        usort($items, fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $items;
    }

    /** @param  array<string, mixed>  $document */
    private static function documentTitle(array $document, string $id): string
    {
        foreach (['title', 'name', 'label'] as $key) {
            if (is_scalar($document[$key] ?? null) && trim((string) $document[$key]) !== '') {
                return trim((string) $document[$key]);
            }
        }

        return $id;
    }
}
