<?php

namespace Inly\CoreWeb\Snapshots;

use Closure;
use InvalidArgumentException;

/**
 * The definition of one cached copy of Core objects: where it lives in the
 * cache, how long each layer lasts, how to pull it and when a pull is too
 * empty to trust. It holds no documents itself; SnapshotStore does the
 * reading and writing.
 *
 * A partitioned snapshot is pulled once and stored per partition (per site,
 * say), so a render only ever loads the documents its own partition holds.
 */
final class Snapshot
{
    private string $prefix;

    private int $freshTtl = 900;

    private int $staleTtl = 86400;

    private int $retryTtl = 60;

    /** @var Closure(): iterable<string, array<string, mixed>> */
    private Closure $pull;

    /** @var (Closure(string): iterable<string, array<string, mixed>>)|null */
    private ?Closure $pullOne = null;

    /** @var Closure(array<string, array<string, mixed>>): bool */
    private Closure $empty;

    /** @var (Closure(array<string, mixed>, string): ?string)|null */
    private ?Closure $partitionOf = null;

    /** @var list<string> */
    private array $partitions = [];

    /** @var Closure(array<string, array<string, mixed>>): string */
    private Closure $fingerprint;

    /** @var list<Closure(?string, array<string, array<string, mixed>>, ?SnapshotChange): void> */
    private array $listeners = [];

    /** @var (Closure(array<string, mixed>, string, ?string): ?iterable<string>)|null */
    private ?Closure $dependents = null;

    /** @var (Closure(array<string, mixed>, string): iterable<array{loc: string, lastmod?: mixed, images?: list<string>}>)|null */
    private ?Closure $sitemap = null;

    /** @var list<string>|null */
    private ?array $sitemapSites = null;

    /** @var (Closure(string, array<string, array<string, mixed>>): ?array{loc: string, lastmod?: mixed})|null */
    private ?Closure $sitemapIndex = null;

    private function __construct(public readonly string $type)
    {
        $this->prefix = "core:{$type}:v1:";
        $this->pull = fn (): array => [];
        $this->empty = fn (array $documents): bool => $documents === [];
        $this->fingerprint = function (array $documents): string {
            ksort($documents);

            // A non-cryptographic hash: it only has to notice a change, and runs over the whole pull.
            return hash('xxh128', serialize($documents));
        };
    }

    public static function make(string $type): self
    {
        return new self($type);
    }

    /** Bump the version when the stored document shape changes, so no reader meets the old one. */
    public function version(int $version): self
    {
        $this->prefix = "core:{$this->type}:v{$version}:";

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * @param  int  $fresh  how long a pull is served before the next one is due
     * @param  int  $stale  how long the last good pull is served while Core is unreachable
     * @param  int  $retry  how long a failed or empty pull waits before a render may pull again
     */
    public function ttl(int $fresh = 900, int $stale = 86400, int $retry = 60): self
    {
        $this->freshTtl = $fresh;
        $this->staleTtl = $stale;
        $this->retryTtl = $retry;

        return $this;
    }

    /** @param  Closure(): iterable<string, array<string, mixed>>  $pull  every document, keyed by id */
    public function pull(Closure $pull): self
    {
        $this->pull = $pull;

        return $this;
    }

    /**
     * How one object is read again when Core pings it: given Core's id, the
     * documents it now is, keyed by id — none when Core answers 404 or the
     * object is no longer one to show. Without it a ping pulls everything.
     *
     * @param  Closure(string): iterable<string, array<string, mixed>>  $pullOne
     */
    public function pullOne(Closure $pullOne): self
    {
        $this->pullOne = $pullOne;

        return $this;
    }

    public function pullsOne(): bool
    {
        return $this->pullOne !== null;
    }

    /** @return array<string, array<string, mixed>> */
    public function pullDocument(string $id): array
    {
        if ($this->pullOne === null) {
            throw new InvalidArgumentException("Snapshot [{$this->type}] cannot pull one object.");
        }

        $documents = ($this->pullOne)($id);

        return is_array($documents) ? $documents : iterator_to_array($documents);
    }

    /**
     * A pull this closure calls empty never replaces a last good copy: it is
     * an access or sync problem upstream, not a reason to empty the site.
     *
     * @param  Closure(array<string, array<string, mixed>>): bool  $empty
     */
    public function emptyWhen(Closure $empty): self
    {
        $this->empty = $empty;

        return $this;
    }

    /**
     * @param  Closure(array<string, mixed>, string): ?string  $partitionOf  a document's partition, null to leave it out
     * @param  list<string>  $partitions  every partition a reader may ask for
     */
    public function partitionBy(Closure $partitionOf, array $partitions): self
    {
        $this->partitionOf = $partitionOf;
        $this->partitions = array_values($partitions);

        return $this;
    }

    /** @param  Closure(array<string, array<string, mixed>>): string  $fingerprint  what counts as a change */
    public function fingerprintWith(Closure $fingerprint): self
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    /**
     * Called per partition whose fingerprint moved, with the partition, its
     * documents and what changed: the documents that differ from the copy
     * the listeners were last told about, or null when that copy is not
     * known (the first pull, or a listener that failed since).
     *
     * @param  Closure(?string, array<string, array<string, mixed>>, ?SnapshotChange): void  $listener
     */
    public function onChange(Closure $listener): self
    {
        $this->listeners[] = $listener;

        return $this;
    }

    /**
     * The pages that show a document, as paths on the partition's site
     * ('/campaigns', '/campaigns/{slug}'; a trailing `*` takes every path
     * under a prefix, '/brands/*'), so a change to it forgets those pages
     * only (SiteCache::flushOnChange()). Null when they cannot be named:
     * the change then counts as one to every page of the site. The closure
     * is given the document as it was and as it is, one at a time, so a
     * page that only the old version appeared on is named too.
     *
     * @param  Closure(array<string, mixed>, string, ?string): ?iterable<string>  $paths  given the document, its id and the partition
     */
    public function dependents(Closure $paths): self
    {
        $this->dependents = $paths;

        return $this;
    }

    public function declaresDependents(): bool
    {
        return $this->dependents !== null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return iterable<string>|null
     */
    public function dependentsOf(array $document, string $id, ?string $partition): ?iterable
    {
        return $this->dependents === null ? null : ($this->dependents)($document, $id, $partition);
    }

    /**
     * The pages this snapshot's documents have, for its own sitemap
     * (/sitemap-core-{type}.xml): given a document and a site handle, zero
     * or more entries. `loc` is a path on that site or an absolute URL,
     * `lastmod` the document's own change time, `images` absolute URLs.
     * The closure decides what is live; a document it returns nothing for
     * is left out. A partitioned snapshot has a sitemap on each partition's
     * site; `$sites` names them for one that is not partitioned. `$index`
     * adds the page listing them (given the site and its documents; null
     * when something else already lists it), only while there is a document.
     *
     * @param  Closure(array<string, mixed>, string): iterable<array{loc: string, lastmod?: mixed, images?: list<string>}>  $urls
     * @param  list<string>|null  $sites  null for every site
     * @param  (Closure(string, array<string, array<string, mixed>>): ?array{loc: string, lastmod?: mixed})|null  $index
     */
    public function sitemap(Closure $urls, ?array $sites = null, ?Closure $index = null): self
    {
        $this->sitemap = $urls;
        $this->sitemapSites = $sites === null ? null : array_values($sites);
        $this->sitemapIndex = $index;

        return $this;
    }

    /** Whether the snapshot has a sitemap on the site, before reading anything. */
    public function hasSitemap(string $site): bool
    {
        return $this->sitemap !== null
            && ($this->sitemapSites === null || in_array($site, $this->sitemapSites, true))
            && $this->partitionFor($site) !== false;
    }

    /**
     * Which partition holds a site's documents: the site itself for a
     * partitioned snapshot (false when it has none for that site), the one
     * unpartitioned copy otherwise.
     */
    public function partitionFor(string $site): string|false|null
    {
        if ($this->partitionOf === null) {
            return null;
        }

        return in_array($site, $this->partitions, true) ? $site : false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $documents
     * @return list<array{loc: string, lastmod: mixed, images: list<string>}>
     */
    public function sitemapEntries(array $documents, string $site): array
    {
        return iterator_to_array($this->sitemapStream($documents, $site), false);
    }

    /**
     * Whether the site's sitemap lists anything, stopping at the first entry
     * (robots.txt and the index ask this without building the whole list).
     *
     * @param  array<string, array<string, mixed>>  $documents
     */
    public function hasSitemapEntries(array $documents, string $site): bool
    {
        return $this->sitemapStream($documents, $site)->valid();
    }

    /**
     * @param  array<string, array<string, mixed>>  $documents
     * @return \Generator<int, array{loc: string, lastmod: mixed, images: list<string>}>
     */
    private function sitemapStream(array $documents, string $site): \Generator
    {
        if ($this->sitemap === null || $documents === []) {
            return;
        }

        $index = $this->sitemapIndex === null ? null : ($this->sitemapIndex)($site, $documents);

        if (is_array($index)) {
            yield from self::sitemapEntry($index);
        }

        foreach ($documents as $document) {
            foreach (($this->sitemap)($document, $site) as $entry) {
                yield from self::sitemapEntry($entry);
            }
        }
    }

    /** @return list<array{loc: string, lastmod: mixed, images: list<string>}> the entry when it is valid */
    private static function sitemapEntry(mixed $entry): array
    {
        if (! is_array($entry) || ! is_string($entry['loc'] ?? null) || $entry['loc'] === '') {
            return [];
        }

        return [[
            'loc' => $entry['loc'],
            'lastmod' => $entry['lastmod'] ?? null,
            'images' => array_values(array_filter((array) ($entry['images'] ?? []), fn ($url) => is_string($url) && $url !== '')),
        ]];
    }

    public function key(string $name, ?string $partition = null): string
    {
        return $this->prefix.($partition === null ? '' : "{$partition}:").$name;
    }

    public function freshTtl(): int
    {
        return $this->freshTtl;
    }

    public function staleTtl(): int
    {
        return $this->staleTtl;
    }

    public function retryTtl(): int
    {
        return $this->retryTtl;
    }

    /** @return array<string, array<string, mixed>> */
    public function pullDocuments(): array
    {
        $documents = ($this->pull)();

        return is_array($documents) ? $documents : iterator_to_array($documents);
    }

    /** @param  array<string, array<string, mixed>>  $documents */
    public function isEmpty(array $documents): bool
    {
        return ($this->empty)($documents);
    }

    /** @return list<?string> null alone when the snapshot is not partitioned */
    public function partitions(): array
    {
        return $this->partitionOf === null ? [null] : $this->partitions;
    }

    public function assertPartition(?string $partition): void
    {
        if (! in_array($partition, $this->partitions(), true)) {
            throw new InvalidArgumentException("Snapshot [{$this->type}] has no partition [".($partition ?? 'null').'].');
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $documents
     * @return array<string, array<string, mixed>>
     */
    public function documentsIn(array $documents, ?string $partition): array
    {
        if ($this->partitionOf === null) {
            return $documents;
        }

        return array_filter(
            $documents,
            fn (array $document, string|int $id) => ($this->partitionOf)($document, (string) $id) === $partition,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @param  array<string, array<string, mixed>>  $documents */
    public function fingerprint(array $documents): string
    {
        return ($this->fingerprint)($documents);
    }

    public function watched(): bool
    {
        return $this->listeners !== [];
    }

    /** @param  array<string, array<string, mixed>>  $documents */
    public function changed(?string $partition, array $documents, ?SnapshotChange $change = null): void
    {
        foreach ($this->listeners as $listener) {
            $listener($partition, $documents, $change);
        }
    }
}
