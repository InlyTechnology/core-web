<?php

namespace Inly\CoreWeb\Snapshots;

use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls the snapshots from another Core (production, as a rule) into this
 * machine's store and pins them, so a local site renders real data without a
 * local Core. Runs every snapshot's own pull, or its warmer, on the
 * core-web.pull client in place of core-web.client.
 */
class PullSnapshotsCommand extends Command
{
    protected $signature = 'core:pull
        {type? : One snapshot type; every registered type when omitted}
        {--release : Unpin instead, so core:warm pulls from CORE_URL again}';

    protected $description = 'Pull the Core snapshots from another Core (CORE_PULL_URL) and pin them locally';

    public function handle(SnapshotRegistry $registry, SnapshotStore $store): int
    {
        $type = $this->argument('type');

        if ($type !== null && ! $registry->has($type)) {
            $this->error("No snapshot [{$type}] is registered. Known: ".implode(', ', $registry->types()).'.');

            return self::FAILURE;
        }

        $types = $type === null ? $registry->types() : [$type];

        if ($this->option('release')) {
            foreach ($types as $name) {
                $this->info($store->release($registry->get($name)) ? "{$name}: released." : "{$name}: not pinned.");
            }

            return self::SUCCESS;
        }

        // A pinned copy is never replaced by a warm or a ping: on a live site it would stop the site following Core.
        if (app()->environment('production')) {
            $this->error('core:pull pins another Core\'s data on this machine, so it never runs in production.');

            return self::FAILURE;
        }

        $source = (array) config('core-web.pull');

        if (blank($source['base_url'] ?? null) || blank($source['client_id'] ?? null) || blank($source['client_secret'] ?? null)) {
            $this->error('Set CORE_PULL_URL, CORE_PULL_CLIENT_ID and CORE_PULL_CLIENT_SECRET: the Core to pull from and a client that may only read (README, Pulling production data).');

            return self::FAILURE;
        }

        $host = (string) (parse_url((string) $source['base_url'], PHP_URL_HOST) ?: $source['base_url']);
        $client = config('core-web.client');

        // The pulls build their client from core-web.client when they run (CoreClient::fromConfig()), so the source stands in for it.
        config(['core-web.client' => array_replace((array) $client, [
            'base_url' => $source['base_url'],
            'client_id' => $source['client_id'],
            'client_secret' => $source['client_secret'],
            'ca_bundle' => $source['ca_bundle'] ?? '',
            'token_cache_key' => $source['token_cache_key'] ?? 'core_web_pull_access_token',
        ])]);

        try {
            $exit = self::SUCCESS;

            foreach ($types as $name) {
                $exit = max($exit, $this->pull($registry, $store, $name, $host));
            }

            return $exit;
        } finally {
            config(['core-web.client' => $client]);
        }
    }

    private function pull(SnapshotRegistry $registry, SnapshotStore $store, string $type, string $host): int
    {
        $snapshot = $registry->get($type);

        try {
            $count = $store->pinning($host, fn () => ($warm = $registry->warmer($type)) ? $warm($this) : $store->refresh($snapshot));
        } catch (Throwable $e) {
            report($e);
            $this->error("{$type}: pull failed ({$e->getMessage()}).");

            return self::FAILURE;
        } finally {
            $store->forget($snapshot);
        }

        if (! $store->pinnedNow($snapshot)) {
            $this->warn("{$type}: nothing pinned from {$host}; the pull failed or came back empty.");

            return self::FAILURE;
        }

        $this->info($registry->warmer($type) ? "{$type}: pinned from {$host}." : "{$type}: {$count} documents pinned from {$host}.");

        return self::SUCCESS;
    }
}
