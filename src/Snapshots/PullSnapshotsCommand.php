<?php

namespace Inly\CoreWeb\Snapshots;

use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls the snapshots from another Core (production, as a rule) into this
 * machine's store and pins them, so a local site renders real data without a
 * local Core. Runs every snapshot's own pull, or its warmer, on the
 * core-web.remote client in place of core-web.client.
 */
class PullSnapshotsCommand extends Command
{
    protected $signature = 'core:pull
        {type? : One snapshot type; every registered type when omitted}
        {--release : Unpin and forget the pulled copies, so the site reads CORE_URL again}';

    protected $description = 'Pull the Core snapshots from the remote Core (core-web.remote) and pin them locally';

    public function handle(SnapshotRegistry $registry, SnapshotStore $store): int
    {
        $type = $this->argument('type');

        if ($type !== null && ! $registry->has($type)) {
            $this->error("No snapshot [{$type}] is registered. Known: ".implode(', ', $registry->types()).'.');

            return self::FAILURE;
        }

        $types = $type === null ? $registry->types() : [$type];

        if ($this->option('release')) {
            return $this->release($registry, $store, $types);
        }

        // A pinned copy is never replaced by a warm or a ping: on a server it would stop the site following its Core.
        if (! app()->environment(['local', 'testing'])) {
            $this->error('core:pull pins another Core\'s data on this machine, so it runs only where APP_ENV is local.');

            return self::FAILURE;
        }

        $source = (array) config('core-web.remote');

        if (blank($source['base_url'] ?? null) || blank($source['client_id'] ?? null) || blank($source['client_secret'] ?? null)) {
            $this->error('Set CORE_REMOTE_CLIENT_SECRET, and remote.base_url and remote.client_id in config/core-web.php: the Core to pull from and a client that may only read (README, Pulling production data).');

            return self::FAILURE;
        }

        $host = (string) (parse_url((string) $source['base_url'], PHP_URL_HOST) ?: $source['base_url']);
        $client = config('core-web.client');

        // The pulls build their client from core-web.client when they run (CoreClient::fromConfig()), so the source stands in for it.
        config(['core-web.client' => array_replace((array) $client, [
            'base_url' => $source['base_url'],
            'client_id' => $source['client_id'],
            'client_secret' => $source['client_secret'],
            'ca_bundle' => '',
            'token_cache_key' => $source['token_cache_key'] ?? 'core_web_remote_access_token',
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
        $warm = $registry->warmer($type);
        $failure = null;

        try {
            $result = $store->pinning($host, fn () => $warm ? $warm($this) : $store->refresh($snapshot));

            if ($warm && $result !== self::SUCCESS) {
                $failure = "its warmer exited with {$result}";
            }
        } catch (Throwable $e) {
            report($e);
            $failure = $e->getMessage();
        } finally {
            $store->forget($snapshot);
        }

        // A warmer can pin its snapshot and fail afterwards, and core:warm skips a pinned snapshot, so both are said.
        if (! $store->pinnedNow($snapshot)) {
            $this->warn("{$type}: nothing pinned from {$host} (".($failure ?? 'the pull failed or came back too empty to trust').').');

            return self::FAILURE;
        }

        $pinned = $warm ? "{$type}: pinned from {$host}" : "{$type}: {$result} documents pinned from {$host}";

        if ($failure !== null) {
            $this->warn("{$pinned}, but {$failure}.");

            return self::FAILURE;
        }

        $this->info("{$pinned}.");

        return self::SUCCESS;
    }

    /** @param  list<string>  $types */
    private function release(SnapshotRegistry $registry, SnapshotStore $store, array $types): int
    {
        $exit = self::SUCCESS;

        foreach ($types as $type) {
            $snapshot = $registry->get($type);

            if ($store->pinned($snapshot) === null) {
                $this->info("{$type}: not pinned.");
            } elseif ($store->release($snapshot)) {
                $this->info("{$type}: released.");
            } else {
                $this->error("{$type}: busy with another write; still pinned, try again.");
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
