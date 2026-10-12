<?php

namespace Inly\CoreWeb\Snapshots;

/** What one read of a snapshot found: the documents and how far they can be trusted. */
final class SnapshotRead
{
    /**
     * @param  array<string, array<string, mixed>>  $documents  keyed by id
     * @param  bool  $stale  the last good copy, served because no fresh one is cached
     * @param  bool  $degraded  nothing cached and no pull succeeded: the documents are empty
     */
    public function __construct(
        public readonly array $documents,
        public readonly bool $stale = false,
        public readonly bool $degraded = false,
    ) {}
}
