<?php

namespace Inly\CoreWeb\Snapshots;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Inly\CoreWeb\ContentSync\RemoteLoginNeeded;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\RemoteSiteBusy;
use Inly\CoreWeb\ContentSync\RemoteSiteError;
use Inly\CoreWeb\ContentSync\ReportsProgress;
use Inly\CoreWeb\ContentSync\SignsInToRemoteSite;

/**
 * Pulls production's snapshots through the live site, on this machine's login
 * to it (signing in first in a terminal), and pins them, so a local site
 * renders real data without a local Core.
 */
class PullSnapshotsCommand extends Command
{
    use ReportsProgress;
    use SignsInToRemoteSite;

    protected $signature = 'core:pull
        {type? : One snapshot type; every registered type when omitted}
        {--release : Unpin and forget the pulled copies, so the site reads CORE_URL again}';

    protected $description = 'Pull the Core snapshots through the live site (signing in when needed) and pin them locally';

    private const MAX_PAGES = 10000;

    /** Pulls of one snapshot started over when its copy changes between pages, after the first. */
    private const RESTARTS = 2;

    /** Waits for a site that asked this machine to (429, 503), per request, each a minute at most. */
    private const WAITS = 3;

    private const MAX_WAIT_SECONDS = 60;

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

        return $this->throughSite($registry, $store, $types);
    }

    /** @param  list<string>  $types */
    private function throughSite(SnapshotRegistry $registry, SnapshotStore $store, array $types): int
    {
        if (RemoteSite::configuredUrl() === '') {
            $this->error('Nothing to pull from: set core-web.content_sync.remote_url to the live site, then run php artisan content:login (README, Pulling production data).');

            return self::FAILURE;
        }

        $fresh = RemoteSite::fromStorage() === null;
        $site = $this->signedInSite();

        if ($site === null) {
            return self::FAILURE;
        }

        $host = (string) (parse_url($site->url(), PHP_URL_HOST) ?: $site->url());
        $exit = self::SUCCESS;

        foreach ($types as $index => $type) {
            try {
                try {
                    [$documents, $stale] = $this->fetch($site, $type);
                } catch (RemoteLoginNeeded $e) {
                    if ($fresh) {
                        throw $e;
                    }

                    if (($site = $this->signInAgain()) === null) {
                        $this->line('Not pulled: '.implode(', ', array_slice($types, $index)).'.');

                        return self::FAILURE;
                    }

                    $fresh = true;
                    [$documents, $stale] = $this->fetch($site, $type);
                }
            } catch (RemoteLoginNeeded) {
                $this->refusedFreshLogin();
                $this->line('Not pulled: '.implode(', ', array_slice($types, $index)).'.');

                return self::FAILURE;
            } catch (RemoteSiteError $e) {
                $this->warn("{$type}: nothing pinned from {$host} ({$e->getMessage()}".(self::timedOut($e) ? '; the live site may still be filling its copy from its Core: try again in a minute' : '').').');
                $exit = self::FAILURE;

                continue;
            }

            $snapshot = $registry->get($type);

            try {
                $count = $store->pin($snapshot, $host, $documents);
            } catch (LockTimeoutException) {
                $this->warn("{$type}: nothing pinned from {$host} (another write held the local copy too long; try again).");
                $exit = self::FAILURE;

                continue;
            } finally {
                $store->forget($snapshot);
            }

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
     * Every page of the site's copy, one copy throughout: a copy that changes
     * between pages starts the pull over, a few times at most.
     *
     * @return array{array<string, array<string, mixed>>, bool} the documents by id, and whether the site's copy is stale
     *
     * @throws RemoteLoginNeeded|RemoteSiteError
     */
    private function fetch(RemoteSite $site, string $type): array
    {
        try {
            for ($attempt = 0; $attempt <= self::RESTARTS; $attempt++) {
                $fetched = $this->fetchOnce($site, $type);

                if ($fetched !== null) {
                    return $fetched;
                }
            }
        } finally {
            $this->endProgress();
        }

        throw new RemoteSiteError('the site\'s copy kept changing while pulling; run core:pull again');
    }

    /**
     * @return array{array<string, array<string, mixed>>, bool}|null null when the copy changed between pages
     *
     * @throws RemoteLoginNeeded|RemoteSiteError
     */
    private function fetchOnce(RemoteSite $site, string $type): ?array
    {
        $documents = [];
        $version = null;
        $cursor = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $this->progress(sprintf('Pulling %s… %s documents so far', $type, number_format(count($documents))));
            $body = $this->page($site, $type, $cursor);

            if ($version !== null && $version !== $body['version']) {
                return null;
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

    /**
     * One page, waiting out a rate limit or a copy the site is still filling as the site asks.
     *
     * @return array{version: string, documents: list<mixed>, next_cursor?: mixed, stale?: mixed}
     *
     * @throws RemoteLoginNeeded|RemoteSiteError
     */
    private function page(RemoteSite $site, string $type, int $cursor): array
    {
        for ($wait = 0; ; $wait++) {
            try {
                $response = $site->get('snapshots/'.rawurlencode($type), ['cursor' => $cursor]);
            } catch (RemoteSiteBusy $e) {
                if ($wait >= self::WAITS) {
                    throw $e;
                }

                $this->sleep($e->retryAfter);

                continue;
            }

            if ($response->status() === 503 && $wait < self::WAITS) {
                $retry = $response->header('Retry-After');
                $this->sleep(ctype_digit($retry) ? (int) $retry : null);

                continue;
            }

            return self::body($response);
        }
    }

    /**
     * @return array{version: string, documents: list<mixed>, next_cursor?: mixed, stale?: mixed}
     *
     * @throws RemoteSiteError
     */
    private static function body(Response $response): array
    {
        $body = $response->json();

        if (! $response->successful()) {
            $message = is_array($body) ? ($body['message'] ?? null) : null;

            if (is_string($message) && $message !== '') {
                throw new RemoteSiteError($message);
            }

            throw new RemoteSiteError($response->status() === 404
                ? 'the site has no snapshot endpoint (HTTP 404); is its core-web up to date?'
                : "HTTP {$response->status()}");
        }

        if (! is_array($body) || ! is_array($body['documents'] ?? null) || ! is_string($body['version'] ?? null)) {
            throw new RemoteSiteError('the site answered something that is not a snapshot export; is its core-web up to date?');
        }

        return $body;
    }

    private function sleep(?int $seconds): void
    {
        $seconds = max(1, min(self::MAX_WAIT_SECONDS, $seconds ?? 10));
        $this->endProgress();
        $this->line("Waiting {$seconds}s for the live site…");
        Sleep::for($seconds)->seconds();
    }

    /** Whether the site took longer than the checkout waits: a first pull from its Core can. */
    private static function timedOut(RemoteSiteError $e): bool
    {
        $previous = $e->getPrevious();

        return $previous instanceof ConnectionException && preg_match('/timed out|cURL error 28/i', $previous->getMessage()) === 1;
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
