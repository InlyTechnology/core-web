<?php

namespace Inly\CoreWeb\Snapshots;

use Illuminate\Console\Command;
use Throwable;

/** Pulls every registered snapshot (or one) from Core, so no visitor pays for a cold read. */
class WarmSnapshotsCommand extends Command
{
    protected $signature = 'core:warm {type? : One snapshot type; every registered type when omitted}';

    protected $description = 'Pull the Core snapshots into the cache';

    public function handle(SnapshotRegistry $registry, SnapshotStore $store): int
    {
        $type = $this->argument('type');

        if ($type !== null && ! $registry->has($type)) {
            $this->error("No snapshot [{$type}] is registered. Known: ".implode(', ', $registry->types()).'.');

            return self::FAILURE;
        }

        $exit = self::SUCCESS;

        foreach ($type === null ? $registry->types() : [$type] as $name) {
            try {
                $result = $this->warm($registry, $store, $name);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$name}: warming failed ({$e->getMessage()}).");
                $result = self::FAILURE;
            }

            $exit = max($exit, $result);
        }

        return $exit;
    }

    private function warm(SnapshotRegistry $registry, SnapshotStore $store, string $type): int
    {
        if ($warm = $registry->warmer($type)) {
            return $warm($this);
        }

        $snapshot = $registry->get($type);
        $count = $store->refresh($snapshot);
        $store->forget($snapshot);

        if ($count === null) {
            $this->warn("{$type}: pull failed; serving the last good snapshot.");

            return self::FAILURE;
        }

        $this->info("{$type}: {$count} documents.");

        return self::SUCCESS;
    }
}
