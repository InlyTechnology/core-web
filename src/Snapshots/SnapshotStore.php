<?php

namespace Inly\CoreWeb\Snapshots;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and refreshes snapshots through three cache layers: a fresh marker
 * that expires when the next pull is due, the last good copy that outlives a
 * Core outage, and a short backoff after a failed or empty pull. The
 * documents are stored once, in the last good copy; the fresh marker only
 * says they are current, so a pull that changed nothing renews it without
 * writing the documents again.
 *
 * The pull is paged and slow, so it runs from the warm job and Core's ping;
 * a render only pulls when nothing at all is cached, and then only one render
 * at a time (the others serve a degraded read). A failed or suspiciously
 * empty pull keeps serving the last good copy. Each read is kept for the rest
 * of the request (bind the store scoped).
 *
 * A copy `core:pull` stored is pinned: kept without expiry, and no warm,
 * ping or render replaces it until it is pulled again or released.
 */
final class SnapshotStore
{
    /** Outlasts a slow paged pull; a crashed render frees it on expiry. */
    private const PULL_LOCK_SECONDS = 300;

    /** A write (a full pull's store, a ping's read-patch-write) holds the snapshot this long at most. */
    private const WRITE_LOCK_SECONDS = 30;

    /** How long a write waits for another to finish before giving up. */
    private const WRITE_WAIT_SECONDS = 10;

    /**
     * An unchanged pull rewrites the documents anyway once they are this far
     * into their stale lifetime, so the last good copy never expires under a
     * fresh marker that still points at it.
     */
    private const RESTORE_AFTER = 0.5;

    /** @var array<string, SnapshotRead> */
    private array $reads = [];

    /** Where the pull running under pinning() reads from; null outside one. */
    private ?string $pinningFrom = null;

    /** @var array<string, true> the snapshot types the last pinning() pinned */
    private array $pinnedNow = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /** The cache store snapshots live in (core-web.snapshots.store; null for the default store). */
    public static function repository(): Repository
    {
        $store = config('core-web.snapshots.store');

        // The `snapshots` store is only added while the feature is on; say so instead of Laravel's bare "not defined".
        if ($store === 'snapshots' && ! config()->has('cache.stores.snapshots')) {
            throw new LogicException('Core snapshots are switched off: set core-web.features.snapshots to true (inly/core-web README, Features).');
        }

        return Cache::store($store);
    }

    /** @return array<string, array<string, mixed>> */
    public function documents(Snapshot $snapshot, ?string $partition = null): array
    {
        return $this->read($snapshot, $partition)->documents;
    }

    public function read(Snapshot $snapshot, ?string $partition = null): SnapshotRead
    {
        $snapshot->assertPartition($partition);
        $memo = self::memo($snapshot, $partition);

        if (isset($this->reads[$memo])) {
            return $this->reads[$memo];
        }

        $fresh = $this->cache->get($snapshot->key('fresh', $partition));

        // A brief empty pull (and a copy written before the marker) is kept inline.
        if (is_array($fresh)) {
            return $this->reads[$memo] = new SnapshotRead($fresh);
        }

        $lastGood = $this->cache->get($snapshot->key('last_good', $partition));

        if ($fresh !== null && is_array($lastGood)) {
            return $this->reads[$memo] = new SnapshotRead($lastGood);
        }

        // A marker whose copy is gone: the next write must store the documents, not trust the marker.
        if ($fresh !== null) {
            $this->cache->forget($snapshot->key('stored', $partition));
        }

        // Only a completely cold cache pulls during a request; otherwise the
        // warm job is already on its way and the last good copy is served.
        if (is_array($lastGood) || $this->cache->has($snapshot->key('backoff'))) {
            return $this->fallback($snapshot, $partition, $lastGood);
        }

        return $this->coldPull($snapshot, $partition);
    }

    /**
     * What the store already holds, without pulling: null while nothing is
     * cached. For a reader that must not wait on Core, such as the control
     * panel's widget.
     */
    public function cached(Snapshot $snapshot, ?string $partition = null): ?SnapshotRead
    {
        $snapshot->assertPartition($partition);

        if (isset($this->reads[$memo = self::memo($snapshot, $partition)])) {
            return $this->reads[$memo];
        }

        $fresh = $this->cache->get($snapshot->key('fresh', $partition));

        if (is_array($fresh)) {
            return new SnapshotRead($fresh);
        }

        $lastGood = $this->cache->get($snapshot->key('last_good', $partition));

        if (! is_array($lastGood)) {
            return null;
        }

        return new SnapshotRead($lastGood, stale: $fresh === null);
    }

    /** One render pulls a cold snapshot; every render that arrives meanwhile is served degraded instead of pulling too. */
    private function coldPull(Snapshot $snapshot, ?string $partition): SnapshotRead
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->refreshed($snapshot, $partition);
        }

        $lock = $store->lock($snapshot->key('pulling'), self::PULL_LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->fallback($snapshot, $partition, null);
        }

        try {
            $fresh = $this->freshCopy($snapshot, $partition);

            // The render holding the lock before this one may have just stored it.
            if ($fresh !== null) {
                return $this->reads[self::memo($snapshot, $partition)] = new SnapshotRead($fresh);
            }

            return $this->refreshed($snapshot, $partition);
        } finally {
            $lock->release();
        }
    }

    private function refreshed(Snapshot $snapshot, ?string $partition): SnapshotRead
    {
        $memo = self::memo($snapshot, $partition);

        if ($this->refresh($snapshot) !== null && isset($this->reads[$memo])) {
            return $this->reads[$memo];
        }

        return $this->fallback($snapshot, $partition, $this->cache->get($snapshot->key('last_good', $partition)));
    }

    /** Whether any read this request had nothing to serve: a page built on it must not be kept. */
    public function servedDegraded(): bool
    {
        foreach ($this->reads as $read) {
            if ($read->degraded) {
                return true;
            }
        }

        return false;
    }

    /** Drop the request's reads of a snapshot, so a command warming several types keeps one in memory at a time. */
    public function forget(Snapshot $snapshot): void
    {
        foreach ($snapshot->partitions() as $partition) {
            unset($this->reads[self::memo($snapshot, $partition)]);
        }
    }

    /**
     * Pull every document from Core now and store it. Used by the warm job
     * and Core's ping; returns the number of documents stored, or null when
     * the pull failed or was too empty to trust.
     */
    public function refresh(Snapshot $snapshot): ?int
    {
        $this->forget($snapshot);

        if ($this->pinnedElsewhere($snapshot)) {
            $pinned = $this->freshDocuments($snapshot);

            if ($pinned !== null) {
                return count($pinned);
            }

            // A pin missing a partition (one added since the pull) serves nothing: drop it and pull as usual.
            $this->warn($snapshot, 'broken_pin');
            $this->cache->forget($snapshot->key('pinned'));
        }

        try {
            $documents = $snapshot->pullDocuments();
        } catch (Throwable $e) {
            $this->warn($snapshot, 'exception:'.class_basename($e));
            $this->cache->put($snapshot->key('backoff'), true, $snapshot->retryTtl());

            return null;
        }

        return $this->keep($snapshot, $documents);
    }

    /**
     * Stores documents read elsewhere (another site's copy) pinned, as a
     * pull under pinning() would: null when they are too empty to trust,
     * which pins nothing and leaves an earlier pin alone.
     *
     * @param  array<string, array<string, mixed>>  $documents
     */
    public function pin(Snapshot $snapshot, string $source, array $documents): ?int
    {
        return $this->pinning($source, function () use ($snapshot, $documents): ?int {
            $this->forget($snapshot);

            return $this->keep($snapshot, $documents);
        });
    }

    /** @param  array<string, array<string, mixed>>  $documents */
    private function keep(Snapshot $snapshot, array $documents): ?int
    {
        // Checked again under the lock: a pull that started before core:pull pinned must not overwrite the pin.
        return $this->writing($snapshot, function () use ($snapshot, $documents): ?int {
            if ($this->pinnedElsewhere($snapshot)) {
                return count($this->freshDocuments($snapshot) ?? []);
            }

            if (! $this->store($snapshot, $documents)) {
                return null;
            }

            // Under the same lock as the copy, and only for one stored as the last good copy: a cold empty pull keeps its short TTL.
            if ($this->pinningFrom !== null && ! $snapshot->isEmpty($documents)) {
                $this->cache->forever($snapshot->key('pinned'), ['source' => $this->pinningFrom, 'at' => now()->toIso8601String()]);
                $this->pinnedNow[$snapshot->type] = true;
            }

            return count($documents);
        });
    }

    /**
     * Runs a pull whose every full refresh stores its copy pinned (no
     * expiry, the marker naming `$source`), even over a copy pinned before.
     * A failed refresh, or one too empty to trust, pins nothing and leaves
     * an earlier pin alone.
     *
     * @template T
     *
     * @param  Closure(): T  $pull
     * @return T
     */
    public function pinning(string $source, Closure $pull): mixed
    {
        $this->pinnedNow = [];
        $this->pinningFrom = $source;

        try {
            return $pull();
        } finally {
            $this->pinningFrom = null;
        }
    }

    /** Whether the last pinning() pinned this snapshot. */
    public function pinnedNow(Snapshot $snapshot): bool
    {
        return isset($this->pinnedNow[$snapshot->type]);
    }

    /** Whether a pin stands that only another pinning() may replace. */
    private function pinnedElsewhere(Snapshot $snapshot): bool
    {
        return $this->pinningFrom === null && $this->pinned($snapshot) !== null;
    }

    /** @return array{source: string, at: string}|null where and when `core:pull` pinned the snapshot, or null when it is not pinned */
    public function pinned(Snapshot $snapshot): ?array
    {
        $pinned = $this->cache->get($snapshot->key('pinned'));

        return is_array($pinned) ? $pinned : null;
    }

    /**
     * Unpins a snapshot and forgets the pinned copy, so the next read or
     * warm pulls from the site's own Core as from a cold cache (another
     * Core's data is no last good copy of this one). False when another
     * write held the snapshot too long.
     */
    public function release(Snapshot $snapshot): bool
    {
        $released = $this->writing($snapshot, function () use ($snapshot): bool {
            foreach ($snapshot->partitions() as $partition) {
                foreach (['fresh', 'stored', 'last_good', 'fingerprint'] as $key) {
                    $this->cache->forget($snapshot->key($key, $partition));
                }
            }

            $this->cache->forget($snapshot->key('pinned'));
            $this->cache->forget($snapshot->key('backoff'));

            return true;
        });

        $this->forget($snapshot);

        return $released === true;
    }

    /**
     * Runs a write to the snapshot's cache alone, so a full pull's store and
     * a ping's read-patch-write never interleave and lose one another's
     * change. Null when another write held it too long.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T|null
     */
    private function writing(Snapshot $snapshot, Closure $write): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $write();
        }

        try {
            return $store->lock($snapshot->key('writing'), self::WRITE_LOCK_SECONDS)->block(self::WRITE_WAIT_SECONDS, $write);
        } catch (LockTimeoutException) {
            $this->warn($snapshot, 'write_lock_timeout');

            return null;
        }
    }

    /**
     * Core pinged one object: read it again and patch it into the cached
     * copy, instead of pulling every document. `$key` is the document it is
     * stored under (the ping's public id), which a deletion or a 404 takes
     * out. Anything a patch cannot be trusted with — a cold or stale cache,
     * a snapshot without a single-object read, a removal without a key —
     * pulls everything instead. An object that reads as it is stored, or
     * that is not this snapshot's and never was (its pullOne() keeps
     * nothing of it), writes nothing. Returns the number of documents
     * patched in (0 when nothing changed), or null when the read failed
     * (the cached copy is left alone).
     */
    public function refreshOne(Snapshot $snapshot, string $id, ?string $key = null, bool $deleted = false): ?int
    {
        if ($this->pinnedElsewhere($snapshot)) {
            return 0;
        }

        // A pin is a whole copy: a patch would write without expiry and without the marker.
        if ($this->pinningFrom !== null || ! $snapshot->pullsOne() || ($deleted && $key === null)) {
            return $this->refresh($snapshot);
        }

        $patched = $this->writing($snapshot, fn () => $this->patch($snapshot, $id, $key, $deleted));

        // The patch could not be trusted: a full pull decides (outside the write, which it takes itself).
        return $patched === false ? $this->refresh($snapshot) : $patched;
    }

    /** @return int|false|null the documents patched in, false when a full pull must decide, null when the read failed */
    private function patch(Snapshot $snapshot, string $id, ?string $key, bool $deleted): int|false|null
    {
        if ($this->pinnedElsewhere($snapshot)) {
            return 0;
        }

        $current = $this->freshDocuments($snapshot);

        if ($current === null) {
            return false;
        }

        try {
            $patch = $deleted ? [] : $snapshot->pullDocument($id);
        } catch (Throwable $e) {
            $this->warn($snapshot, 'exception:'.class_basename($e), ['id' => $id]);

            return null;
        }

        if ($patch === [] && $key === null) {
            return false;
        }

        $documents = $current;

        if ($key !== null && ! array_key_exists($key, $patch)) {
            unset($documents[$key]);
        }

        // In place, so a patched document keeps its position and the stored copy stays comparable with a full pull.
        $documents = array_replace($documents, $patch);

        if (self::sameDocuments($current, $documents, [...array_keys($patch), ...($key === null ? [] : [$key])])) {
            return 0;
        }

        // Emptied by a patch: let a full pull decide, under the empty rule.
        if ($snapshot->isEmpty($documents)) {
            return false;
        }

        foreach ($snapshot->partitions() as $partition) {
            unset($this->reads[self::memo($snapshot, $partition)]);
        }

        $this->write($snapshot, $documents, $snapshot->freshTtl(), lastGood: true, previous: $current);

        return count($patch);
    }

    /**
     * Whether the documents a patch touched read the same before and after.
     *
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @param  list<string|int>  $ids
     */
    private static function sameDocuments(array $before, array $after, array $ids): bool
    {
        foreach ($ids as $id) {
            if (($before[$id] ?? null) !== ($after[$id] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every partition's fresh copy in one list, or null when any partition
     * has none: a patch on top of a stale copy would pass it off as fresh.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function freshDocuments(Snapshot $snapshot): ?array
    {
        $documents = [];

        foreach ($snapshot->partitions() as $partition) {
            $fresh = $this->freshCopy($snapshot, $partition);

            if ($fresh === null) {
                return null;
            }

            $documents += $fresh;
        }

        return $documents;
    }

    /**
     * A partition's documents while they are fresh: kept inline under the
     * fresh key, or the last good copy the fresh marker vouches for.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function freshCopy(Snapshot $snapshot, ?string $partition): ?array
    {
        $fresh = $this->cache->get($snapshot->key('fresh', $partition));

        if (is_array($fresh) || $fresh === null) {
            return $fresh;
        }

        $lastGood = $this->cache->get($snapshot->key('last_good', $partition));

        return is_array($lastGood) ? $lastGood : null;
    }

    /** @param  array<string, array<string, mixed>>  $documents */
    private function store(Snapshot $snapshot, array $documents): bool
    {
        if ($snapshot->isEmpty($documents)) {
            if ($this->hasLastGood($snapshot)) {
                $this->warn($snapshot, 'empty_pull', ['documents' => count($documents)]);
                $this->cache->put($snapshot->key('backoff'), true, $snapshot->retryTtl());

                return false;
            }

            // Nothing better to serve: keep the empty pull, but only briefly.
            $this->write($snapshot, $documents, $snapshot->retryTtl(), lastGood: false);

            return true;
        }

        $this->write($snapshot, $documents, $snapshot->freshTtl(), lastGood: true);
        $this->cache->forget($snapshot->key('backoff'));

        return true;
    }

    /**
     * Stores each partition. A partition whose documents are what is stored
     * already renews its fresh marker only; the rest are written whole. The
     * change listeners hear of each partition whose fingerprint moved since
     * they were last told, with what changed when the copy they were told
     * about is the one being replaced.
     *
     * @param  array<string, array<string, mixed>>  $documents
     * @param  array<string, array<string, mixed>>|null  $previous  every partition's documents before this write, when the caller has them
     */
    private function write(Snapshot $snapshot, array $documents, int $ttl, bool $lastGood, ?array $previous = null): void
    {
        foreach ($snapshot->partitions() as $partition) {
            $part = $snapshot->documentsIn($documents, $partition);
            $this->reads[self::memo($snapshot, $partition)] = new SnapshotRead($part);

            // A cold empty pull is kept only briefly, to stop renders pulling; it is not a change to announce.
            if (! $lastGood) {
                $this->cache->put($snapshot->key('fresh', $partition), $part, $ttl);

                continue;
            }

            $hash = self::hash($part);
            // A pinned copy has no expiry to renew, so a pin always writes it whole.
            $unchanged = $this->pinningFrom === null && $this->isStored($snapshot, $partition, $hash);
            $stale = $this->pinningFrom === null ? $snapshot->staleTtl() : null;
            $announce = $this->announcement($snapshot, $partition, $part, $unchanged, $previous);

            if (! $unchanged) {
                $this->cache->put($snapshot->key('last_good', $partition), $part, $stale);
                $this->cache->put($snapshot->key('stored', $partition), ['hash' => $hash, 'at' => now()->getTimestamp()], $stale);
            }

            // Never outliving the copy it vouches for, whatever the TTLs say.
            $this->cache->put($snapshot->key('fresh', $partition), $hash, $stale === null ? null : min($ttl, $stale));

            if ($announce !== null) {
                $this->notify($snapshot, $partition, $part, ...$announce);
            }
        }
    }

    /**
     * Whether the partition's last good copy holds exactly these documents
     * and has long enough to live that renewing the fresh marker will do.
     */
    private function isStored(Snapshot $snapshot, ?string $partition, string $hash): bool
    {
        $stored = $this->cache->get($snapshot->key('stored', $partition));

        return is_array($stored)
            && ($stored['hash'] ?? null) === $hash
            && now()->getTimestamp() - (int) ($stored['at'] ?? 0) < $snapshot->staleTtl() * self::RESTORE_AFTER;
    }

    /**
     * The fingerprint to announce and what changed, or null when the
     * listeners have heard of these documents already. Read before the
     * write, since what changed is measured against the copy it replaces.
     *
     * @param  array<string, array<string, mixed>>  $documents
     * @param  array<string, array<string, mixed>>|null  $previous
     * @return array{string, ?SnapshotChange}|null
     */
    private function announcement(Snapshot $snapshot, ?string $partition, array $documents, bool $unchanged, ?array $previous): ?array
    {
        if (! $snapshot->watched()) {
            return null;
        }

        $fingerprint = $snapshot->fingerprint($documents);
        $announced = $this->cache->get($snapshot->key('fingerprint', $partition));

        if ($announced === $fingerprint) {
            return null;
        }

        $old = match (true) {
            $unchanged => $documents,
            $previous !== null => $snapshot->documentsIn($previous, $partition),
            default => $this->cache->get($snapshot->key('last_good', $partition)),
        };

        // Only a diff against the copy the listeners were told about says what they have not heard.
        $change = is_array($old) && $announced !== null && $announced === $snapshot->fingerprint($old)
            ? SnapshotChange::between($snapshot, $partition, $old, $documents)
            : null;

        return [$fingerprint, $change];
    }

    /**
     * Calls the snapshot's change listeners. A failing listener leaves the
     * old fingerprint in place, so the next pull tries it again (and tells
     * it about every document, since what it missed is not known).
     *
     * @param  array<string, array<string, mixed>>  $documents
     */
    private function notify(Snapshot $snapshot, ?string $partition, array $documents, string $fingerprint, ?SnapshotChange $change): void
    {
        try {
            $snapshot->changed($partition, $documents, $change);
        } catch (Throwable $e) {
            $this->logger->warning('Core snapshot change listener failed', [
                'snapshot' => $snapshot->type,
                'partition' => $partition,
                'reason' => 'exception:'.class_basename($e),
            ]);
            report($e);

            return;
        }

        $this->cache->forever($snapshot->key('fingerprint', $partition), $fingerprint);
    }

    /**
     * Whether two copies of a partition are the same documents in the same
     * order; non-cryptographic, it only has to notice a difference.
     *
     * @param  array<string, array<string, mixed>>  $documents
     */
    private static function hash(array $documents): string
    {
        return hash('xxh128', serialize($documents));
    }

    private function hasLastGood(Snapshot $snapshot): bool
    {
        foreach ($snapshot->partitions() as $partition) {
            $lastGood = $this->cache->get($snapshot->key('last_good', $partition));

            if (is_array($lastGood) && $lastGood !== []) {
                return true;
            }
        }

        return false;
    }

    private function fallback(Snapshot $snapshot, ?string $partition, mixed $lastGood): SnapshotRead
    {
        return $this->reads[self::memo($snapshot, $partition)] = is_array($lastGood)
            ? new SnapshotRead($lastGood, stale: true)
            : new SnapshotRead([], degraded: true);
    }

    /** The reason only — never the token, a query string or a payload. */
    private function warn(Snapshot $snapshot, string $reason, array $context = []): void
    {
        $this->logger->warning('Core snapshot pull failed; serving cached data', [
            'snapshot' => $snapshot->type,
            'reason' => $reason,
            ...$context,
        ]);
    }

    private static function memo(Snapshot $snapshot, ?string $partition): string
    {
        return $snapshot->type.'|'.($partition ?? '');
    }
}
