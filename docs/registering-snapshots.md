# Registering snapshots

A site describes each snapshot and registers it by extending the registry in a
service provider:

```php
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\StaticCaching\SiteCache;

$this->app->extend(SnapshotRegistry::class, fn (SnapshotRegistry $registry) => $registry
    ->register(
        Snapshot::make('locations')
            ->version(3)                                   // bump when the stored document shape changes
            ->ttl(fresh: 3600, stale: 2592000, retry: 60)
            ->pull(fn () => LocationSnapshot::pull(CoreClient::fromConfig()))          // every document, keyed by id
            ->pullOne(fn (string $id) => LocationSnapshot::pullOne(CoreClient::fromConfig(), $id)) // one object, [] when gone
            ->partitionBy(fn (array $doc) => $doc['site'] ?? null, ['default', 'second'])
            ->emptyWhen(fn (array $documents) => $documents === [])
            ->onChange(SiteCache::flushOnChange())         // flush the partition's site
            ->sitemap(fn (array $doc, string $site) => [['loc' => '/locations/'.$doc['slug'], 'lastmod' => $doc['updated_at']]]),
        pings: ['location', 'locations'],                 // Core's ping types that refresh it
    )
    ->register($catalog, pings: ['product', 'products'], wholePings: ['product-category'])
    ->register($stock, pings: ['stock'], warm: fn (Command $command) => 0, job: fn (string $id, string $action) => new RefreshStockJob($id, $action))
    ->idle(['product-categories']));                      // accepted, acted on by nothing
```

`register()` arguments:

- `pings`: ping types that refresh the snapshot. A ping naming one object
  patches it in through `pullOne()`; `id` = `all` (or `0`) warms it whole.
- `wholePings`: ping types that always warm it whole (an object of another type
  than the one `pullOne()` reads, such as a category of a catalog of models).
- `warm`: replaces the plain pull when warming needs more (returns an exit code).
- `job`: the job a ping queues, given the id, action and public id (a subclass
  of `RefreshSnapshotJob`).

`idle()` names ping types the site takes (202) without acting on, so Core does
not retry them; any other unknown type is a 422.

Reading a snapshot:

```php
$read = app(SnapshotStore::class)->read($snapshot, 'second');
$read->documents;  // keyed by id
$read->stale;      // the last good copy, served because no fresh one is cached
$read->degraded;   // nothing cached and no pull succeeded: documents are empty
```

The store is scoped, so a read is kept for the rest of the request or job.
Cache keys are `core:{type}:v{version}:[{partition}:]{fresh|last_good|stored|fingerprint}`,
plus `backoff`, `pulling` and `writing` per type, and `details:{id}` per
document of a snapshot with [details](#details).

## Writes

The documents are stored once per partition, in `last_good`. `fresh` is a
small marker (the copy's hash) that expires when the next pull is due; while
it lives, a read serves `last_good` as fresh, and once it is gone, as stale. A
brief empty pull on a cold cache is the one copy kept inline under `fresh`.

A write that would store what is stored already writes nothing but the
marker:

- a full pull (`core:warm`, a bulk ping) whose partition hashes the same as
  the stored one (`stored` holds the hash) renews `fresh` only. Once the
  stored copy is half way through its stale lifetime it is written again, so
  it never expires under a live marker;
- a ping for one object whose document reads as stored, or for an object the
  snapshot's `pullOne()` keeps nothing of and never stored (another site's
  model, say), writes nothing and returns `0`.

Neither calls the change listeners, unless a listener failed since the last
write: then it is called again.

## Change listeners

`onChange()` listeners are called per partition whose fingerprint moved, with
the partition, its documents and a `SnapshotChange`: the documents added,
changed or removed since the copy the listeners were last told about, each
as it was (`before($id)`) and as it is (`after($id)`). It is null when that is
not known — the first pull, or a listener that failed since it was last told —
so a listener must then treat everything as changed.

```php
->onChange(function (?string $partition, array $documents, ?SnapshotChange $change): void {
    foreach ($change?->ids() ?? array_keys($documents) as $id) { /* … */ }
})
```

## Details

Fields too large to keep on every row (a car's images and equipment lines)
are the snapshot's details. They are left out of the stored copy and read
for one document at a time, on the page that shows it:

```php
Snapshot::make('vehicles')->fromObject('vehicle')->details(['images', 'equipment']);

app(SnapshotStore::class)->document($snapshot, $id);  // the row with its details, null when there is none
```

- Only a field one document's page shows is a detail. A field a listing,
  card, search, filter, sort, facet or sitemap uses stays on the row (a car
  card's cover image beside the full set of images): `read()`,
  `documents()` and a local search see the rows without details, and every
  closure the snapshot is given must work on the row alone. A search that
  filters, sorts or facets on a detail is never answered from the snapshot.
- A snapshot made with `fromObject()` pulls every field Core's `/schema`
  lists in `fields_available` except the details (the description is kept
  like a search's, `search.schema_ttl`), and reads a document's details from
  the object with only those fields, with a 10-second timeout per request
  and no retry but a new token on a 401. A description that lists no fields
  fails the pull, so the last good copy stays. Any other snapshot names how
  with `details($fields, read: fn (string $id, array $row) => ...)`, which
  returns the details, or null for a document Core no longer has, and bounds
  how long its own reads take.
- `document()` and `SnapshotPage::find()` add the details. They are kept
  under `details:{id}` for the stale TTL with the row they were read for,
  and served whatever their age while that row is the one stored; a page
  reads Core only when no copy was read for its row. A document Core no
  longer has keeps an empty copy, so a page does not ask for it again under
  the same row.
- A ping that patches an object's row into the copy reads that object's
  details again too. `core:warm` reads the documents without a copy for
  their row, then the copies older than the fresh TTL, oldest first: at most
  100 reads in 30 seconds, stopping after 3 failures in a row. Details that
  change while the row does not are announced to `onChange()` as a change
  to that one document, the row the same before and after; a listener that
  fails then is logged, not retried.
- A read that fails serves the last copy. Core out of reach or refusing the
  site (no connection, the token refused, 401, 403, 429, 502, 503 or 504)
  backs off the whole snapshot for the retry TTL (`backoff`), and the warm
  stops; any other failure, a read that times out once connected included,
  backs off only that document (`details:{id}:backoff`), and the warm goes
  on with the next. With no copy the document comes without its details,
  and the request counts as degraded, as a read with nothing cached does.
- `core:pull` carries the details the live site has cached, in pages of at
  most 20 documents. A pinned copy never reads its details from this site's
  Core; a document pulled without them is served without them.
- An [object preview](object-preview.md) with a state reads the record with
  its details, the state applied; when that read of the details fails, they
  come from the stored copy. A previewed document of unsaved values that
  comes without them takes the stored document's cached copy, and never
  reads Core or writes the copy.
