<?php

namespace Inly\CoreWeb\Preview;

use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Statamic\Facades\Site;

/**
 * The Core records the site serves a page for (Statamic connector API v1.5
 * `object-pages`): every document of a snapshot that has pages() and names
 * its Core object with coreObject() or listing(), by Core's type and id,
 * with its page on each site. Read from the stored copy, so a read never
 * waits on Core unless the store is cold.
 */
final class ObjectPages
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly SnapshotRegistry $registry,
        private readonly SnapshotStore $store,
    ) {}

    /**
     * @return array{data: list<array{type: string, id: string, pages: list<array{site: string, site_name: string, url: string}>}>, next_cursor: ?string}
     */
    public function page(?string $cursor = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $after = $cursor === null ? null : self::decode($cursor);
        $records = $this->records();

        if ($after !== null) {
            $records = array_values(array_filter($records, fn (array $record): bool => strcmp($record['type']."\0".$record['id'], $after[0]."\0".$after[1]) > 0));
        }

        $page = array_slice($records, 0, $limit);
        $last = end($page);

        return [
            'data' => $page,
            'next_cursor' => count($records) > $limit && $last !== false ? self::encode($last['type'], $last['id']) : null,
        ];
    }

    /** @return array{type: string, id: string, pages: list<array{site: string, site_name: string, url: string}>}|null */
    public function find(string $type, string $id): ?array
    {
        $snapshot = $this->snapshotFor($type);

        if ($snapshot === null) {
            return null;
        }

        $pages = [];

        foreach ($this->siteDocuments($snapshot) as [$site, $documentId, $document]) {
            if ($snapshot->coreIdOf($document, $documentId) === $id) {
                $pages[$site] ??= $this->address($snapshot, $document, $site);
            }
        }

        $pages = array_values(array_filter($pages));

        return $pages === [] ? null : ['type' => (string) $snapshot->coreName(), 'id' => $id, 'pages' => $pages];
    }

    /** The snapshot whose documents are Core's `$type`, when one has pages. */
    public function snapshotFor(string $type): ?Snapshot
    {
        foreach ($this->snapshots() as $snapshot) {
            if ($snapshot->namesCoreObject($type)) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * The stored document with a page on the site that is Core's record `$id`, keyed by the document's id.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function document(Snapshot $snapshot, string $site, string $id): ?array
    {
        $partition = $snapshot->partitionFor($site);

        if ($partition === false) {
            return null;
        }

        foreach ($this->store->read($snapshot, $partition)->documents as $documentId => $document) {
            if ($snapshot->coreIdOf($document, (string) $documentId) === $id && $snapshot->pagePath($document, $site) !== null) {
                return [(string) $documentId, $document];
            }
        }

        return null;
    }

    /** @return list<array{type: string, id: string, pages: list<array{site: string, site_name: string, url: string}>}> */
    private function records(): array
    {
        $records = [];

        foreach ($this->snapshots() as $snapshot) {
            $type = (string) $snapshot->coreName();

            foreach ($this->siteDocuments($snapshot) as [$site, $documentId, $document]) {
                $id = $snapshot->coreIdOf($document, $documentId);
                $address = $id === null ? null : $this->address($snapshot, $document, $site);

                if ($address !== null) {
                    $records["{$type}\0{$id}"]['type'] = $type;
                    $records["{$type}\0{$id}"]['id'] = $id;
                    $records["{$type}\0{$id}"]['pages'][$site] ??= $address;
                }
            }
        }

        ksort($records, SORT_STRING);

        return array_values(array_map(fn (array $record): array => [...$record, 'pages' => array_values($record['pages'])], $records));
    }

    /** @return iterable<array{0: string, 1: string, 2: array<string, mixed>}> */
    private function siteDocuments(Snapshot $snapshot): iterable
    {
        foreach (Site::all() as $site) {
            $partition = $snapshot->partitionFor($site->handle());

            if ($partition === false) {
                continue;
            }

            $read = $this->store->read($snapshot, $partition);

            // Nothing could be read: an empty answer would tell Core every page is gone.
            abort_if($read->degraded, 503, headers: ['Retry-After' => '300']);

            foreach ($read->documents as $documentId => $document) {
                yield [$site->handle(), (string) $documentId, $document];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{site: string, site_name: string, url: string}|null
     */
    private function address(Snapshot $snapshot, array $document, string $site): ?array
    {
        $path = $snapshot->pagePath($document, $site);
        $statamicSite = Site::get($site);

        if ($path === null || $statamicSite === null) {
            return null;
        }

        return [
            'site' => $site,
            'site_name' => (string) $statamicSite->name(),
            'url' => rtrim($statamicSite->absoluteUrl(), '/').self::encodePath($path),
        ];
    }

    /** @return list<Snapshot> */
    private function snapshots(): array
    {
        return array_values(array_filter(
            array_map(fn (string $type): Snapshot => $this->registry->get($type), $this->registry->types()),
            fn (Snapshot $snapshot): bool => $snapshot->hasPages() && $snapshot->coreType() !== null,
        ));
    }

    public static function encodePath(string $path): string
    {
        return implode('/', array_map(fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path)));
    }

    public static function isCursor(string $cursor): bool
    {
        return self::decode($cursor) !== null;
    }

    private static function encode(string $type, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode([$type, $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string}|null */
    private static function decode(string $cursor): ?array
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($decoded) || ! is_string($decoded[0] ?? null) || ! is_string($decoded[1] ?? null)) {
            return null;
        }

        return [$decoded[0], $decoded[1]];
    }
}
