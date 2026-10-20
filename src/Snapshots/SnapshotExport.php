<?php

namespace Inly\CoreWeb\Snapshots;

/**
 * A snapshot as this site holds it, paged for a checkout's `core:pull`: the
 * copy the site renders from, or, when nothing is cached yet, one pull from
 * its own Core through the store, cached as a render's would be.
 */
final class SnapshotExport
{
    public function __construct(private SnapshotStore $store) {}

    /**
     * One page of the snapshot's documents, or null while the site has none
     * to serve (its Core unreachable and nothing cached). `version` changes
     * with the documents, so a reader can tell its pages came from one copy.
     *
     * @return array{type: string, version: string, stale: bool, total: int, documents: list<array{id: string, document: array<string, mixed>}>, next_cursor: ?int}|null
     */
    public function page(Snapshot $snapshot, int $cursor, int $size): ?array
    {
        $documents = [];
        $versions = [];
        $stale = false;

        foreach ($snapshot->partitions() as $partition) {
            // Taken before the read, so a copy replaced in between shows as a change on the next page rather than hiding one.
            $version = $this->store->version($snapshot, $partition);
            $read = $this->store->read($snapshot, $partition);

            if ($read->degraded) {
                return null;
            }

            $versions[] = $version ?? $this->store->version($snapshot, $partition) ?? hash('xxh128', serialize($read->documents));
            $documents += $read->documents;
            $stale = $stale || $read->stale;
        }

        $page = array_slice($documents, $cursor, $size, true);
        $next = $cursor + $size;

        return [
            'type' => $snapshot->type,
            'version' => hash('xxh128', implode('|', $versions)),
            'stale' => $stale,
            'total' => count($documents),
            // A list of pairs: ids that read as numbers would turn a JSON object into a list.
            'documents' => array_map(fn (string|int $id) => ['id' => (string) $id, 'document' => $page[$id]], array_keys($page)),
            'next_cursor' => $next < count($documents) ? $next : null,
        ];
    }
}
