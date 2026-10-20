<?php

namespace Inly\CoreWeb\Snapshots;

use Illuminate\Console\Command;
use Inly\CoreWeb\ContentSync\RemoteLoginNeeded;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\RemoteSiteError;
use Throwable;

/**
 * Pulls production's snapshots into this machine's store and pins them, so a
 * local site renders real data without a local Core: through the live site,
 * signed in with `content:login`, or, with a remote client secret set, from
 * another Core directly, running every snapshot's own pull or its warmer on
 * the core-web.remote client in place of core-web.client.
 */
class PullSnapshotsCommand extends Command
{
    protected $signature = 'core:pull
        {type? : One snapshot type; every registered type when omitted}
        {--release : Unpin and forget the pulled copies, so the site reads CORE_URL again}';

    protected $description = 'Pull the Core snapshots through the live site (content:login), or from the remote Core (core-web.remote), and pin them locally';

    private const MAX_PAGES = 10000;

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

        if (blank($source['client_secret'] ?? null)) {
            return $this->throughSite($registry, $store, $types);
        }

        if (blank($source['base_url'] ?? null) || blank($source['client_id'] ?? null)) {
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
    private function throughSite(SnapshotRegistry $registry, SnapshotStore $store, array $types): int
    {
        $site = RemoteSite::configuredUrl() === '' ? null : RemoteSite::fromStorage();

        if ($site === null) {
            $this->error(RemoteSite::configuredUrl() === ''
                ? 'Nothing to pull from: set core-web.content_sync.remote_url and sign this machine in to the live site with php artisan content:login, or set CORE_REMOTE_CLIENT_SECRET to pull from Core directly (README, Pulling production data).'
                : 'This machine is not signed in to '.RemoteSite::configuredUrl().': run php artisan content:login (or set CORE_REMOTE_CLIENT_SECRET to pull from Core directly).');

            return self::FAILURE;
        }

        $host = (string) (parse_url($site->url(), PHP_URL_HOST) ?: $site->url());
        $exit = self::SUCCESS;

        foreach ($types as $type) {
            try {
                [$documents, $stale] = $this->fetch($site, $type);
            } catch (RemoteLoginNeeded) {
                $this->error('The live site refused this machine\'s login: run php artisan content:login.');

                return self::FAILURE;
            } catch (RemoteSiteError $e) {
                $this->warn("{$type}: nothing pinned from {$host} ({$e->getMessage()}).");
                $exit = self::FAILURE;

                continue;
            }

            $snapshot = $registry->get($type);
            $count = $store->pin($snapshot, $host, $documents);
            $store->forget($snapshot);

            if (! $store->pinnedNow($snapshot)) {
                $this->warn("{$type}: nothing pinned from {$host} (the pull came back too empty to trust).");
                $exit = self::FAILURE;

                continue;
            }

            $this->info("{$type}: {$count} documents pinned from {$host}".($stale ? ' (the site\'s own copy is stale: it could not reach its Core lately)' : '').'.');
        }

        return $exit;
    }

    /**
     * Every page of the site's copy, which must stay one copy throughout.
     *
     * @return array{array<string, array<string, mixed>>, bool} the documents by id, and whether the site's copy is stale
     *
     * @throws RemoteLoginNeeded|RemoteSiteError
     */
    private function fetch(RemoteSite $site, string $type): array
    {
        $documents = [];
        $version = null;
        $cursor = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $site->get('snapshots/'.rawurlencode($type), ['cursor' => $cursor]);

            if (! $response->successful()) {
                $message = $response->json('message');

                throw new RemoteSiteError(is_string($message) && $message !== '' ? $message : "HTTP {$response->status()}");
            }

            $body = $response->json();

            if (! is_array($body) || ! is_array($body['documents'] ?? null) || ! is_string($body['version'] ?? null)) {
                throw new RemoteSiteError('the site answered something that is not a snapshot export; is its core-web up to date?');
            }

            if ($version !== null && $version !== $body['version']) {
                throw new RemoteSiteError('the site\'s copy changed while pulling; run core:pull again');
            }

            $version = $body['version'];

            foreach ($body['documents'] as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null) || ! is_array($row['document'] ?? null)) {
                    throw new RemoteSiteError('the site sent a document without an id');
                }

                $documents[$row['id']] = $row['document'];
            }

            $next = $body['next_cursor'] ?? null;

            if (! is_int($next)) {
                return [$documents, (bool) ($body['stale'] ?? false)];
            }

            if ($next <= $cursor) {
                throw new RemoteSiteError('the site\'s pages do not move forward');
            }

            $cursor = $next;
        }

        throw new RemoteSiteError('the export never ended');
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
