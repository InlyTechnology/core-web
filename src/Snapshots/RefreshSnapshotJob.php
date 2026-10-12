<?php

namespace Inly\CoreWeb\Snapshots;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Core pinged a change. A ping naming one object patches that object into
 * the snapshot (SnapshotStore::refreshOne()); a bulk ping (`all`), or one
 * for a snapshot that cannot read a single object, warms it whole. One waiting refresh per object, or per type for a
 * bulk ping, absorbs a burst of pings.
 */
class RefreshSnapshotJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    /** @param  ?string  $key  the document the object is stored under (the ping's public id) */
    public function __construct(public string $type, public string $id = 'all', public string $action = 'saved', public ?string $key = null) {}

    public function uniqueId(): string
    {
        return $this->id === 'all' ? $this->type : "{$this->type}:{$this->id}:{$this->action}";
    }

    public function handle(SnapshotRegistry $registry, SnapshotStore $store): void
    {
        $snapshot = $registry->get($this->type);

        // A snapshot that cannot read one object is warmed whole, through its own warmer if it has one.
        if ($this->id === 'all' || ! $snapshot->pullsOne()) {
            if (Artisan::call('core:warm', ['type' => $this->type]) !== 0) {
                throw new RuntimeException("core:warm {$this->type} failed: ".Artisan::output());
            }

            return;
        }

        $key = $this->key === null || $this->key === '' ? null : strtolower($this->key);

        if ($store->refreshOne($snapshot, $this->id, $key, $this->action === 'deleted') === null) {
            throw new RuntimeException("Refreshing {$this->type} {$this->id} from Core failed.");
        }
    }
}
