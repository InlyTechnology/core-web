<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Tests\TestCase;

/** A ping's read-patch-write holds the snapshot, so a full pull's store cannot land in between and be lost. */
class SnapshotWriteLockTest extends TestCase
{
    public function test_a_patch_holds_the_write_lock_while_it_reads_and_writes(): void
    {
        $held = null;
        $snapshot = null;
        $snapshot = Snapshot::make('things')
            ->pull(fn (): array => ['a' => ['id' => 'a'], 'b' => ['id' => 'b']])
            ->pullOne(function (string $id) use (&$held, &$snapshot): array {
                $held = ! Cache::lock($snapshot->key('writing'), 5)->get();

                return [$id => ['id' => $id]];
            });
        $store = app(SnapshotStore::class);
        $store->refresh($snapshot);

        $this->assertSame(1, $store->refreshOne($snapshot, 'c', 'c'));

        $this->assertTrue($held, 'the single read ran under the write lock');
        $this->assertSame(['a', 'b', 'c'], array_keys($store->documents($snapshot)));
        $this->assertTrue(Cache::lock($snapshot->key('writing'), 5)->get(), 'released afterwards');
    }
}
