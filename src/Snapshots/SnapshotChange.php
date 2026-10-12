<?php

namespace Inly\CoreWeb\Snapshots;

use Throwable;

/**
 * What one write changed in one partition of a snapshot: the documents
 * added, changed or removed since the copy the change listeners were last
 * told about, each as it was and as it is now. A listener uses it to find
 * the pages that show those documents (dependents()) instead of treating
 * every change as a change to everything.
 */
final class SnapshotChange
{
    /**
     * @param  array<string, array<string, mixed>|null>  $before  the changed documents as they were, null when added
     * @param  array<string, array<string, mixed>|null>  $after  the changed documents as they are, null when removed
     */
    public function __construct(
        public readonly Snapshot $snapshot,
        public readonly ?string $partition,
        private readonly array $before,
        private readonly array $after,
    ) {}

    /**
     * The documents that differ between two copies of a partition.
     *
     * @param  array<string, array<string, mixed>>  $old
     * @param  array<string, array<string, mixed>>  $new
     */
    public static function between(Snapshot $snapshot, ?string $partition, array $old, array $new): self
    {
        $before = [];
        $after = [];

        foreach ($new as $id => $document) {
            $previous = $old[$id] ?? null;

            if ($previous !== $document) {
                $before[(string) $id] = $previous;
                $after[(string) $id] = $document;
            }
        }

        foreach (array_diff_key($old, $new) as $id => $document) {
            $before[(string) $id] = $document;
            $after[(string) $id] = null;
        }

        return new self($snapshot, $partition, $before, $after);
    }

    /** @return list<string> the ids of the documents added, changed or removed */
    public function ids(): array
    {
        return array_map('strval', array_keys($this->after));
    }

    public function count(): int
    {
        return count($this->after);
    }

    /** @return array<string, mixed>|null the document before the write, null when it is new */
    public function before(string $id): ?array
    {
        return $this->before[$id] ?? null;
    }

    /** @return array<string, mixed>|null the document after the write, null when it is gone */
    public function after(string $id): ?array
    {
        return $this->after[$id] ?? null;
    }

    /**
     * The paths on the partition's site whose pages show what changed: the
     * snapshot's dependents (Snapshot::dependents()) of each document as it
     * was and as it is, so a moved or removed document's old page goes too.
     * Null when that is not known: the
     * snapshot declares no dependents, one document's are unknown, or
     * working them out failed. A listener then treats it as a change to
     * the whole site.
     *
     * @return list<string>|null
     */
    public function dependents(): ?array
    {
        if (! $this->snapshot->declaresDependents()) {
            return null;
        }

        $paths = [];

        try {
            foreach ($this->ids() as $id) {
                foreach ([$this->before($id), $this->after($id)] as $document) {
                    if ($document === null) {
                        continue;
                    }

                    $dependents = $this->snapshot->dependentsOf($document, $id, $this->partition);

                    if ($dependents === null) {
                        return null;
                    }

                    foreach ($dependents as $path) {
                        if (is_string($path) && $path !== '') {
                            $paths['/'.ltrim($path, '/')] = true;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return array_keys($paths);
    }
}
