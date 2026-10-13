# inly/core-web

The website half of an Inly Core integration, for Statamic sites that show data
an Inly Core backoffice owns. Core stays the source of truth; the site keeps a
**snapshot** of the objects it shows in its own cache, refreshes it on a
schedule and whenever Core **pings** a change, and never pulls from Core while
a visitor waits (unless the cache is completely cold).

What the package gives a site:

| Piece | Class | What it does |
|---|---|---|
| Snapshot definition | `Inly\CoreWeb\Snapshots\Snapshot` | Where a snapshot lives in the cache, its TTLs, how to pull it whole and one object, partitions (per site), empty guard, change listeners, sitemap |
| Snapshot store | `Inly\CoreWeb\Snapshots\SnapshotStore` | Three cache layers (fresh marker, last good, backoff), cold-pull lock, write lock, single-object patching, no-op writes skipped, change fingerprints and change sets |
| Registry | `Inly\CoreWeb\Snapshots\SnapshotRegistry` | Every snapshot by type, which ping types refresh which, idle ping types, custom warmers and jobs |
| `core:warm` | `Inly\CoreWeb\Snapshots\WarmSnapshotsCommand` | Pulls every registered snapshot, or one; scheduled nightly by default, as the safety net under Core's pings |
| Refresh job | `Inly\CoreWeb\Snapshots\RefreshSnapshotJob` | What a ping queues: patches one object, or warms the type |
| Degraded guard | `Inly\CoreWeb\Snapshots\UncacheableWhileDegraded` | Keeps a page built on an empty degraded read out of Statamic's static cache |
| Core client | `Inly\CoreWeb\Client\CoreClient` | Passport client_credentials client for Core's Objects API (JSON:API) |
| Ping endpoint | `Inly\CoreWeb\Ping\PingController`, `PingSignature` | `POST api/core/objects`, HMAC-signed by Core |
| Snapshot sitemaps | `Inly\CoreWeb\Sitemaps\*` | `/sitemap-core-{type}.xml` per site host, and the `{{ core_sitemaps }}` tag |
| Static cache helpers | `Inly\CoreWeb\StaticCaching\SiteCache`, `SiteScopedInvalidator` | Flush the pages a snapshot change touches (or the whole site when they are not known); scope the `all` invalidation rule to the saved item's site |
| Rich text | `Inly\CoreWeb\Support\CoreHtml` | Narrows Core's `*_html` fields to prose markup |
| Test helpers | `Inly\CoreWeb\Testing\FakesCore` | A fake Core over `Http::fake()` and pings signed as Core signs them |

What stays in the site: the domain snapshots (which Core types, what a stored
document looks like, how the pages read it), the page routes and views, the TTL
choices, and anything that is not the snapshot pipeline (CMS single sign-on,
AI chat, …).

## Installation

```bash
composer require inly/core-web
```

The service provider is auto-discovered and the defaults read the env names
below. Requirements: PHP 8.3+, Laravel 12 or 13, Statamic 6.31+.

## Features

Installed, the package does nothing: every feature is off until the site
switches it on, and a feature that is off registers no route, schedule,
middleware, command, tag or cache store. That is what lets every new site
install it whether or not it shows Core data yet.

| Feature | Switch | What it registers |
|---|---|---|
| Core snapshots | `features.snapshots` | The `snapshots` cache store, `core:warm` and its schedule, `core:pull`, the degraded guard, the ping route (`ping.enabled`) and the snapshot sitemaps with their `{{ core_sitemaps }}` tag (`sitemaps.enabled`) |

The Core client (`CoreClient`, `client.*`) needs no switch: it is config and a
container binding, and registers nothing.

A site switches a feature on in its own `config/core-web.php`. That file needs
only the keys it changes, since every top-level key it leaves out keeps the
package's default:

```php
<?php

return [
    'features' => ['snapshots' => true],
];
```

Publish the whole file (`--tag=core-web-config`) only to change more than
that. A published file overrides each top-level key whole, so one copied from
an earlier version still needs a `features` key of its own.

## Configuration

The environment, one family named after the product, plus the secrets a site
shares with the backoffice under the backoffice's own names:

| Env | Config key | Meaning |
|---|---|---|
| `CORE_URL` | `client.base_url` | The Core install's URL |
| `CORE_CLIENT_ID` / `CORE_CLIENT_SECRET` | `client.client_id` / `client.client_secret` | The site's Passport client_credentials client |
| `CORE_CA_BUNDLE` | `client.ca_bundle` | Local only: a CA bundle for Core's self-signed certificate |
| `CORE_PULL_URL` / `CORE_PULL_CLIENT_ID` / `CORE_PULL_CLIENT_SECRET` | `pull.base_url` / `pull.client_id` / `pull.client_secret` | Local only: the Core `core:pull` reads from and its read-only client (see [Pulling production data](#pulling-production-data)) |
| `WEBSITE_SYNC_SECRET` | `ping.secret` | The HMAC secret Core signs pings with (the backoffice's `WEBSITE_SYNC_SECRET`). **Empty rejects every ping**; there is no `APP_KEY` fallback |
| `CORE_SNAPSHOT_STORE` | `snapshots.store_driver` | `file` (default) or `database`: what the `snapshots` store is (see [Where snapshots live](#where-snapshots-live)) |

A site's own Core settings sit in the same family: `CORE_CMS_SITE` for CMS
single sign-on, with the backoffice's `WEBSITE_HANDOFF_SECRET` as its secret.

`config/core-web.php`:

| Key | Default | Meaning |
|---|---|---|
| `features.snapshots` | `false` | Switch on Core snapshots, pings and snapshot sitemaps (see [Features](#features)) |
| `client.token_cache_key` | `core_web_access_token` | Where the access token is cached (default cache store) |
| `pull.token_cache_key` | `core_web_pull_access_token` | Where `core:pull`'s access token is cached, apart from the site's own |
| `ping.enabled` | `true` | Register the ping route |
| `ping.path` / `ping.name` | `api/core/objects` / `api.core.objects` | The endpoint Core posts to |
| `ping.middleware` | `['throttle:120,1']` | Outside the `web` group, so no CSRF exemption is needed |
| `snapshots.store` | `snapshots` | The cache store snapshots live in. `snapshots` is added to `cache.stores` unless the app defines one by that name. `null` is the default store (tests) |
| `snapshots.store_driver` | `env('CORE_SNAPSHOT_STORE', 'file')` | `file` or `database` |
| `snapshots.database.connection` | `null` | The `database` store's connection (null: the default connection) |
| `snapshots.database.table` / `.lock_table` | `core_snapshots_cache` / `core_snapshots_cache_locks` | The `database` store's own tables |
| `snapshots.warm_schedule` | `15 3 * * *` | When `core:warm` runs; `null` schedules nothing. See [Schedule](#schedule) |
| `snapshots.targeted_flush.enabled` | `true` | Flush only the pages a change names (see [Static cache](#static-cache)); `false` flushes the whole site on every change |
| `snapshots.targeted_flush.max_documents` | `20` | A change to more documents than this at once flushes the whole site |
| `snapshots.uncacheable_while_degraded` | `true` | Push `UncacheableWhileDegraded` onto `statamic.web` |
| `sitemaps.enabled` / `sitemaps.name` | `true` / `core.sitemap` | Register `/sitemap-core-{type}.xml` |
| `sitemaps.middleware` | `['web', StaticCache::class]` | Statically cached like the site's pages |

## Where snapshots live

Snapshots are what the site serves while Core is down, so they live apart from
the default cache store: a deploy's `cache:clear` (and `optimize:clear`) must
not throw them away.

- **`file`** (default): a file store in `storage/framework/snapshots`. Right
  for a host whose `storage/` outlives a deploy (Forge, a VPS).
- **`database`**: a database store on its own tables, for a host whose disk
  does not outlive a deploy, such as **Laravel Cloud**. Set
  `CORE_SNAPSHOT_STORE=database`, then publish and run the migration:

  ```bash
  php artisan vendor:publish --tag=core-web-migrations
  php artisan migrate
  ```

  It creates `core_snapshots_cache` and `core_snapshots_cache_locks`, apart
  from the default `cache` / `cache_locks` tables, so a `cache:clear` of a
  database default store leaves them alone.

Not Redis on the default cache database: `cache:clear` on a Redis store runs
`FLUSHDB`, which empties the whole database the store sits on, snapshots
included, whatever their prefix. A Redis store would need a database of its own
(a separate `database` index on its own connection); on Cloud, the `database`
store is the simpler choice.

An app that defines `cache.stores.snapshots` itself keeps its own definition.

## Registering snapshots

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
plus `backoff`, `pulling` and `writing` per type.

### Writes

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

### Change listeners

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

## Reading Core

`CoreClient::objects($type, $pageSize, $query)` walks a listing page by page
and `object($type, $id, $query)` reads one record; `$query` carries the
Objects API parameters (`filter[…]`, `include`, `fields[…]`).

Both ask for **compact row meta** (`meta=compact`, Core 9.0+): each row keeps
`attributes`, `relationships`, `links` and its identity meta (`id`,
`lookup_key`, `title`, `created_at`, `updated_at`, `deleted`) plus
`meta.formatted`, the display string per field whose raw value does not carry
it. It leaves out the per-row `meta.schema` and `meta.object`, which repeat
every property's definition on every row and made up most of a listing. What
compact also drops is each field's per-row `data` beside its value (a money
field's currency, a related record's object data beyond its title); a
snapshot that reads those passes `'meta' => 'full'` in its query, which wins.
A Core before 9.0 ignores the parameter and answers full rows, so the same
pull reads the same documents from either. `get()` sends only what it is given.

## Schedule

Core pings every change it makes to an object a site shows, and a pull that
changed nothing writes nothing, so `core:warm` is not what keeps a site
current: it is the safety net under a ping that was lost (the site was down,
the job failed three times). It runs nightly by default
(`snapshots.warm_schedule`, a cron expression; `null` to schedule it
yourself), and the deploy should run it too.

Choose each snapshot's TTLs around it:

- **fresh** longer than the schedule's interval (36 hours for a nightly run),
  or a read falls back to the stale copy between runs and a ping for one
  object pulls the whole snapshot instead of patching it;
- **stale** longer than fresh: it is how long the last good copy outlives
  Core. The fresh marker never outlives it, whatever the TTLs say.

A snapshot whose data moves without a ping (something time alone changes on
Core's side) needs a schedule of its own, or its own ping.

## Pulling production data

A site's content references Core's records, and on a developer's machine the
local Core (if there is one) holds other records, or none. `core:pull` fills
the local snapshots from another Core, production as a rule, so the site
renders what the live site renders without a local Core:

```bash
php artisan core:pull            # every registered snapshot
php artisan core:pull staff      # one
php artisan core:pull --release  # unpin and forget: the site reads CORE_URL again
```

It runs each snapshot's own pull (or its warmer) on the `pull.*` client in
place of `client.*`, so the documents are exactly what the site's
projections make of that Core's data. What it stores is **pinned**: kept
without expiry, and no `core:warm`, ping or render replaces it until the next
`core:pull` or `--release`. A pull that fails, or comes back too empty to
trust (`emptyWhen()`), pins nothing and leaves an earlier pin alone. A
warmer that pins and then fails is reported as both. `--release` forgets the
pulled copies, since another Core's data is no fallback for this one, so the
next read or warm pulls from `CORE_URL` as from a cold cache.

- **Local only.** A pinned copy stops a site following its Core, so the
  command runs only where `APP_ENV` is `local` (`--release` runs anywhere).
- **A client that may only read.** In the source Core, create an app client
  for local pulls (Settings → Apps, client credentials) holding the same read
  permissions as the site's own client and nothing else, and share its secret
  the way the team shares other secrets. It reads what the public site reads,
  never more; revoking it in Core stops every pull.
- **Stays on the machine.** The copies live in the `snapshots` store
  (`storage/framework/snapshots`), which is not committed.
- **Only what goes through the store.** A warmer that also writes cache keys
  of its own, or reads Core during a render, keeps doing so against
  `CORE_URL`; those keys are not pinned.
- Production's pings do not reach a local machine: pull again for fresher
  data.

## Ping contract

Core posts to `POST /api/core/objects`:

```json
{ "payload": "<base64 of the JSON below>", "signature": "<hex hmac_sha256(payload, secret)>" }
```

```json
{ "type": "employee", "id": "42", "public_id": "0b6f3c9e-…", "action": "saved", "expires_at": 1893456000, "nonce": "…" }
```

- `type`: a registered or idle ping type, else 422.
- `id`: Core's id of the object, or `all` (or `0`) for a bulk change.
- `public_id` (optional): the document key a deletion or a 404 removes.
- `action`: `saved` or `deleted`.
- `expires_at`: unix time; an expired envelope is a 403.
- `nonce`: makes each signature unique; not checked for replay (a replayed ping
  only re-runs a pull the site deduplicates itself).

A missing field is a 422, a bad signature, an empty secret or an expired
envelope a 403, an accepted ping a `202 {"status":"accepted"}`. The object
itself never travels in the ping: the job reads it back from Core.

Test vector (shared with the sender's tests): secret
`core-web-test-vector-secret`, data
`{"type":"employee","id":"42","public_id":"0b6f3c9e-6d1a-4c5e-9f2b-3a7d8e1c4b50","action":"saved","expires_at":1893456000,"nonce":"00112233445566778899aabbccddeeff"}`
gives

```
payload   eyJ0eXBlIjoiZW1wbG95ZWUiLCJpZCI6IjQyIiwicHVibGljX2lkIjoiMGI2ZjNjOWUtNmQxYS00YzVlLTlmMmItM2E3ZDhlMWM0YjUwIiwiYWN0aW9uIjoic2F2ZWQiLCJleHBpcmVzX2F0IjoxODkzNDU2MDAwLCJub25jZSI6IjAwMTEyMjMzNDQ1NTY2Nzc4ODk5YWFiYmNjZGRlZWZmIn0=
signature 81df17fceeac581bb3163d4d0c7b80a7f547533b823db233859e74caaf0674b6
```

(`json_encode` with PHP's default flags, keys in that order.)

## Sitemaps

A snapshot that declares `->sitemap()` gets `/sitemap-core-{type}.xml` at every
site's address it has URLs on: 404 when it lists nothing there, 503 while
nothing at all can be read (an empty sitemap would read as "every URL is
gone"). A site under a path of a host it shares with other sites
(`https://example.com/second`) has its sitemaps under that path
(`/second/sitemap-core-{type}.xml`, route `core.sitemap.site`), and a `loc`
the snapshot gives as a path is on the site, so it lands under that path too.
List them in robots.txt and the sitemap index with the tag (`site="handle"`
for another site than the current one, as a shared host's one robots.txt
needs):

```antlers
{{ core_sitemaps }}
Sitemap: {{ url }}
{{ /core_sitemaps }}
```

## Static cache

A snapshot moving saves no entry, so Statamic's invalidation never hears of
it. `SiteCache::flushOnChange()` is the change listener that forgets the
pages showing it; `SiteCache::flushEverySiteOnChange()` forgets every site's
pages, for data every page shows.

By default a change flushes every page of the partition's site (the default
site for an unpartitioned snapshot). A snapshot that names the pages its
documents appear on flushes only those:

```php
Snapshot::make('campaigns')
    ->dependents(fn (array $doc, string $id, ?string $partition) => [
        $doc['url_path'],             // its own page
        '/campaigns',                 // the listing
        '/brands/'.$doc['brand'],     // its brand's page
        ...$pickedBy($id),            // pages whose content picks it
    ])
    ->onChange(SiteCache::flushOnChange());
```

`dependents()` returns paths on the partition's site (`/`, `/campaigns`; a
trailing `*` takes every page under a prefix, `/brands/*`), and is called for
each changed document as it was and as it is, so a moved or removed
document's old page goes too. The snapshot's own sitemap
(`/sitemap-core-{type}.xml`) is added when it has one. A cached page goes with
every query string it was cached under.

The whole site is flushed instead whenever the pages are not known for sure:

- the listener has no `SnapshotChange` (the first pull, or a listener failed
  since it was last told);
- the snapshot declares no `dependents()`, or they return `null` for one of
  the documents;
- more than `targeted_flush.max_documents` documents changed at once;
- naming the pages throws (the exception is reported).

Correctness beats savings: return `null` from `dependents()` for a document
whose pages you cannot name.

`SiteCache::flush()` forgets the pages cached for one site: on its host, and on
a shared host the pages under its path (the longest site path a page lies
under decides whose it is). `SiteCache::flushPaths()` forgets some of them, by
path on the site.

## Testing a site

```php
use Inly\CoreWeb\Testing\FakesCore;

class FacilityTest extends TestCase
{
    use FakesCore;

    public function test_a_ping_refreshes_the_facility(): void
    {
        $this->fakeCore([
            'core.test/api/v1/objects/facility/*' => self::coreObjectById('facility', $resources),
            'core.test/api/v1/objects/facility*' => self::coreList($resources),
        ]);

        $this->withPingSecret()->receivePing(['type' => 'facility', 'id' => '1', 'public_id' => $uuid])->assertStatus(202);
    }
}
```

In a site's base `TestCase`, `config(['core-web.snapshots.store' => null])`
keeps snapshots in the test's array store.

### Sparse fieldsets

Every field Core sends costs its value in `attributes`, and under full row
meta its description in `meta.schema` as well, so a pull should name the
fields its documents read
(`fields[_]=title,slug`, and `fields[{table}]` for an included type). To keep
that list honest, run a pull against the fake twice, once whole and once
narrowed as Core narrows it, and compare the documents:

```php
$whole = FacilitySnapshot::pull(CoreClient::fromConfig());
$this->answerSparseFieldsets(['manufacturer' => 'manufacturers']); // included type => table
$this->assertSame($whole, FacilitySnapshot::pull(CoreClient::fromConfig()));
```

`answerSparseFieldsets()` narrows every later Objects API answer to the
fieldsets its request names (a request naming none gets the whole answer), and
`requestedFieldsets($request)` reads them off a recorded request. A builder
that starts reading a field its pull does not ask for then fails the test, as
long as the fixture carries that field.

### Compact row meta

`answerRowMeta()` gives every later Objects API row the meta Core answers: a
read asking for `meta=compact` gets the row's identity meta alone, any other a
`meta.schema` entry per attribute and `meta.object` besides it. Set
`$this->fakeCoreCompactsMeta = false` to answer full meta whatever is asked, as
a Core before 9.0 does. A pull that builds the same documents from both reads
nothing compact leaves out:

```php
$this->answerRowMeta();
$this->fakeCoreCompactsMeta = false;
$full = FacilitySnapshot::pull(CoreClient::fromConfig());
$this->fakeCoreCompactsMeta = true;
$this->assertSame($full, FacilitySnapshot::pull(CoreClient::fromConfig()));
```

`requestedRowMeta($request)` reads `compact` or `full` off a recorded request.
Core's display strings (`meta.formatted`) are not faked.

## Developing

```bash
composer install
composer test     # PHPUnit
composer format   # Pint
```

CI runs the suite and `pint --test` on every push and pull request.

## Releasing

Releases are git tags (`v0.3.0`); Packagist picks each one up. Add the
release's notes to `CHANGELOG.md` under its version before tagging.

## License

MIT. See [LICENSE](LICENSE).
