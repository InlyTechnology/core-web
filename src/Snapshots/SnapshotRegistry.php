<?php

namespace Inly\CoreWeb\Snapshots;

use Closure;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Every snapshot the site keeps, by type: what `core:warm` warms and which of
 * Core's ping types refresh which snapshots. One ping type may refresh
 * several (one site's models and another's, say); each re-reads the object
 * and keeps it only if it is its own. Idle ping types are accepted and left
 * alone, so Core does not retry a type nothing here reads yet.
 */
final class SnapshotRegistry
{
    /** @var array<string, Snapshot> */
    private array $snapshots = [];

    /** @var array<string, list<string>> ping type → snapshot types */
    private array $pings = [];

    /** @var array<string, list<string>> snapshot type → ping types that refresh it whole */
    private array $wholePings = [];

    /** @var array<string, Closure(Command): int> */
    private array $warmers = [];

    /** @var array<string, Closure(string, string, ?string): RefreshSnapshotJob> */
    private array $jobs = [];

    /** @var list<string> */
    private array $idle = [];

    /**
     * @param  list<string>  $pings  the ping types from Core that refresh this snapshot
     * @param  (Closure(Command): int)|null  $warm  replaces the plain refresh when warming needs more than the pull; returns an exit code
     * @param  (Closure(string, string, ?string): RefreshSnapshotJob)|null  $job  the job a ping queues, given the ping's id, action and public id
     * @param  list<string>  $wholePings  ping types that refresh the snapshot whole even when they name one object: an object of another Core type than the one pullOne() reads (a category of a catalog of models)
     */
    public function register(Snapshot $snapshot, array $pings = [], ?Closure $warm = null, ?Closure $job = null, array $wholePings = []): self
    {
        $this->snapshots[$snapshot->type] = $snapshot;

        foreach ([...$pings, ...$wholePings] as $ping) {
            $this->pings[$ping] = array_values(array_unique([...$this->pings[$ping] ?? [], $snapshot->type]));
        }

        $this->wholePings[$snapshot->type] = array_values($wholePings);

        if ($warm !== null) {
            $this->warmers[$snapshot->type] = $warm;
        }

        if ($job !== null) {
            $this->jobs[$snapshot->type] = $job;
        }

        return $this;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->snapshots);
    }

    public function has(string $type): bool
    {
        return isset($this->snapshots[$type]);
    }

    public function get(string $type): Snapshot
    {
        return $this->snapshots[$type] ?? throw new InvalidArgumentException("No snapshot [{$type}] is registered.");
    }

    /**
     * Ping types Core sends that the site takes without acting on, unless a
     * snapshot registers them too.
     *
     * @param  list<string>  $pings
     */
    public function idle(array $pings): self
    {
        $this->idle = array_values(array_unique([...$this->idle, ...$pings]));

        return $this;
    }

    /** @return list<string> the ping types that refresh a snapshot */
    public function pingTypes(): array
    {
        return array_keys($this->pings);
    }

    /** @return list<string> the idle ping types no snapshot registers */
    public function idleTypes(): array
    {
        return array_values(array_diff($this->idle, $this->pingTypes()));
    }

    /** @return list<string> the snapshot types a ping type refreshes */
    public function forPing(string $ping): array
    {
        return $this->pings[$ping] ?? [];
    }

    /** Whether a ping of this type refreshes the snapshot whole, whatever object it names. */
    public function refreshesWhole(string $ping, string $type): bool
    {
        return in_array($ping, $this->wholePings[$type] ?? [], true);
    }

    /** @return (Closure(Command): int)|null */
    public function warmer(string $type): ?Closure
    {
        return $this->warmers[$type] ?? null;
    }

    public function job(string $type, string $id = 'all', string $action = 'saved', ?string $key = null): RefreshSnapshotJob
    {
        $this->get($type);

        return isset($this->jobs[$type])
            ? ($this->jobs[$type])($id, $action, $key)
            : new RefreshSnapshotJob($type, $id, $action, $key);
    }
}
