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
| Chat relay | `Inly\CoreWeb\Chat\*` | A site's visitors talking to Core's chat agents: the transport (`CoreChat`), the relay routes, the per-agent `ChatContext`, a headless Alpine factory and `<x-core-web::chat>` |
| Website content | `Inly\CoreWeb\Content\*` | Opens the envelopes Core seals with its own key for a site's content endpoints (`core-web.envelope:<purpose>`), reads Core's published keys, and pings Core when a page changes |
| Test helpers | `Inly\CoreWeb\Testing\FakesCore`, `CoreEnvelopeSealer` | A fake Core over `Http::fake()`, pings signed as Core signs them, and content calls sealed with a test key published as Core's |

What stays in the site: the domain snapshots (which Core types, what a stored
document looks like, how the pages read it), the page routes and views, the TTL
choices, the chat's markup, context and lead handling, and anything else that
is not plumbing (CMS single sign-on, …).

## Installation

```bash
composer require inly/core-web
```

The service provider is auto-discovered and the defaults read the env names
below. Requirements: PHP 8.3+ with `ext-sodium`, Laravel 12 or 13, Statamic 6.31+.

## Features

Installed, the package does nothing: every feature is off until the site
switches it on, and a feature that is off registers no route, schedule,
middleware, command, tag or cache store. That is what lets every new site
install it whether or not it shows Core data yet. The one exception is the
`core-web.envelope` middleware alias, registered whatever the switches so a
site's own routes can name it; while `features.content` is off it opens
nothing.

| Feature | Switch | What it registers |
|---|---|---|
| Core snapshots | `features.snapshots` | The `snapshots` cache store, `core:warm` and its schedule, `core:pull`, the degraded guard, the ping route (`ping.enabled`) and the snapshot sitemaps with their `{{ core_sitemaps }}` tag (`sitemaps.enabled`), and the `core_snapshot` dashboard widget (see [Control panel widget](#control-panel-widget)) |
| Chat | `features.chat` | The relay routes under `/!/core-web/chat/{agent}`, the `core-web` view namespace with `<x-core-web::chat>`, and the hint cookies' exemption from cookie encryption (see [Chat](#chat)) |
| Live reads | `features.live` | The route `/!/core-web/live/{name}` for the reads named in `live.reads` (see [Live reads](#live-reads)) |
| Control panel link | `features.cp_link` | A link to Core right under Dashboard in the control panel (see [Control panel link](#control-panel-link)) |
| Website content | `features.content` | Lets the `core-web.envelope` route middleware open envelopes; switched off, it refuses every call. The site registers its own content routes behind it (see [Website content](#website-content)) |

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
| `CORE_REMOTE_CLIENT_SECRET` | `remote.client_secret` | Local only: the secret of the remote Core's read-only client (see [Pulling production data](#pulling-production-data)) |
| `CORE_REMOTE_URL` / `CORE_REMOTE_CLIENT_ID` | `remote.base_url` / `remote.client_id` | Optional overrides of the defaults the site commits |
| `WEBSITE_SYNC_SECRET` | `ping.secret` | The HMAC secret a Core still on the shared secret signs pings with (the backoffice's `WEBSITE_SYNC_SECRET`). A ping sealed with Core's own key (purpose `website.objects`, audience `CORE_CLIENT_ID`) needs none, so blank it once Core seals every ping. **Empty rejects every shared-secret ping**; there is no `APP_KEY` fallback |
| `CORE_CONTENT_ENVIRONMENT` | `content.environment` | The environment a draft or publish must be sealed for, when the site runs in another `APP_ENV` than its Core (a live site on `APP_ENV=staging` paired with production Core sets `production`). Empty is `app()->environment()` |
| `CORE_CONTENT_CONNECTOR_ID` | `content.connector_id` | The id of the Core connector that holds the site's pairing; content pings go to `{core}/webhooks/connectors/{id}`. Empty sends none |
| `CORE_SNAPSHOT_STORE` | `snapshots.store_driver` | `file` (default) or `database`: what the `snapshots` store is (see [Where snapshots live](#where-snapshots-live)) |

CMS single sign-on is the site's own: Core seals its hand-offs with its own
key, which `CoreEnvelope::open()` opens with no secret.

`config/core-web.php`:

| Key | Default | Meaning |
|---|---|---|
| `features.snapshots` | `false` | Switch on Core snapshots, pings and snapshot sitemaps (see [Features](#features)) |
| `client.token_cache_key` | `core_web_access_token` | Where the access token is cached (default cache store) |
| `remote.token_cache_key` | `core_web_remote_access_token` | Where the remote Core's access token is cached, apart from the site's own |
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
| `features.chat` | `false` | Switch on the chat relay (see [Chat](#chat)) |
| `chat.agents` | `[]` | Core agent key (a lowercase slug) => the site's `ChatContext` class, or `null` for an agent that takes no context. Any other agent is a 404 |
| `chat.path` | `api/app/chat` | Core's stock chat route |
| `chat.timeouts` | `connect` 5, `read` 8, `turn` 60 | Seconds. `turn` covers a message and the handoff summary, `read` opening and reading a conversation |
| `chat.name` / `chat.middleware` | `core-web.chat` / `['web', 'throttle:60,1']` | The relay routes (`core-web.chat.show`, `.messages`, `.handoff`, `.forget`). `web` gives them the session and CSRF |
| `chat.turn_rate_limit` | `10` | Messages and handoffs per minute, per session and address |
| `chat.turn_rate_limit_per_address` | `30` | Messages and handoffs per minute from one address, whatever sessions it holds |
| `chat.max_message_length` | `10000` | The longest message the relay passes on; longer is `invalid` without calling Core. Core's default cap per agent; an agent with a lower one still refuses past it (`invalid`) |
| `chat.max_conversations` | `5` | Conversations a session holds per agent; a new subject past it is `limited` |
| `features.content` | `false` | Switch on the website content middleware (see [Website content](#website-content)) |
| `content.environment` | `CORE_CONTENT_ENVIRONMENT` | The environment a draft or publish must be sealed for; empty is `app()->environment()` |
| `content.store` | `null` | The cache store holding Core's keys and the spent nonces; `null` is the default store. Shared by every server behind the site, and best one a deploy's `cache:clear` and Statamic's cache clearing leave alone: clearing the nonces lets an envelope captured in the last five minutes be replayed once |
| `content.key_cache_key` | `core_web_signing_keys` | Where Core's key document is cached |
| `chat.session_key` / `chat.cookie` | `core_web_chat` / `core_chat` | Where conversations live in the session; the hint cookie's prefix (`core_chat_{agent}`) |

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

The Core and the client are the same on every machine, so the site commits
them as defaults in its own `config/core-web.php`, overridable from env, and a
developer sets only the secret (`CORE_REMOTE_CLIENT_SECRET`). A `remote` key
there replaces the package's whole, so it names all three:

```php
'remote' => [
    'base_url' => env('CORE_REMOTE_URL', 'https://core.example.com'),
    'client_id' => env('CORE_REMOTE_CLIENT_ID', '…'),
    'client_secret' => env('CORE_REMOTE_CLIENT_SECRET', ''),
],
```

The key was called `pull` before. A site config still naming it keeps
working, and so do the `CORE_PULL_*` variables while the site leaves the key
to the package; a site that writes its own `remote` block reads only the
variables it names, so it nests `env('CORE_PULL_CLIENT_SECRET')` as the
default to keep developers' `.env` files working.

The client is an app client in that Core (Settings → Apps, client
credentials grant): Core shows its id and secret when it is created.

It runs each snapshot's own pull (or its warmer) on the `remote.*` client in
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
  (`storage/framework/snapshots` with the `file` driver, its own tables with
  `database`), never in the repository.
- **A certificate the machine trusts.** The pull ignores `CORE_CA_BUNDLE`,
  which is for a local Core, so the source needs a public certificate.
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

### Sealed with Core's key

A Core that seals pings with its own key posts the data itself as the JSON
body (`{ "type", "id", "public_id"?, "action" }`) with the `X-Core-*` headers,
purpose `website.objects`, audience `CORE_CLIENT_ID`. The endpoint takes this
whenever those headers are present, whatever `features.content` says.

- **Post the bytes you sealed.** The envelope binds `sha256` of the body, so
  the sender seals a JSON string and posts that string (`withBody($json)`, or a
  Saloon request that seals its final body). Sealing a string and posting the
  array re-encodes it, and a value with `/` or non-ASCII is then refused
  `body`.
- **The path must arrive as sealed.** The `path` claim is the URL Core posts
  to, compared with the request's raw path, so a proxy that adds or strips a
  prefix refuses every sealed ping.
- Each opens once: its nonce is spent in `content.store`.
- A refusal is JSON, `{ "error": "envelope_refused" }`, with a `reason` once
  the signature verified; a body that is no object is a 422 `invalid_ping`.
- With no `CORE_CLIENT_ID` every sealed ping is refused; blank
  `WEBSITE_SYNC_SECRET` once Core seals every ping, to refuse shared-secret
  ones.

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

## Control panel widget

A snapshot that declares `->listing()` can be shown on the control panel
dashboard by the `core_snapshot` widget: its documents, a page at a time, each
with a link to the record in Core. The widget only reads what the store already
holds (`SnapshotStore::cached()`), so a dashboard visit never waits on Core. A
snapshot with nothing cached yet shows "Loading" and queues its warm job
(one at a time per snapshot).

```php
Snapshot::make('staff')
    // ...
    ->listing(
        title: fn (array $doc, string $id) => $doc['name'],
        coreType: 'employee',                         // Core's lookup key; no link without it
        coreId: fn (array $doc, string $id) => $doc['core_id'] ?? null, // Core's primary key; defaults to the document id
    );
```

```php
// config/statamic/cp.php
'widgets' => [
    ['type' => 'core_snapshot', 'snapshot' => 'staff', 'per_page' => 10, 'width' => 50],
],
```

Options: `snapshot` (required), `title` (the type as a headline by default),
`icon` (a Statamic CP icon name, `link` by default), `per_page` (10, at most
100), `partition` (one partition; every partition by default), and Statamic's
own `width`, `can` and `sites`. Rows are sorted by
title in the selected site's language (with intl). Each snapshot and partition
pages on its own query parameter (`?core_{type}_page=2`,
`?core_{type}_{partition}_page=2`), so several widgets sit on one dashboard. A
badge says when the copy may be out of date or is a local `core:pull` copy. A
document the `title` closure fails on is listed by its id, unlinked, and logged.
Every label, the configured `title` included, goes through `__()`; core-web
ships Swedish in `lang/sv.json`, and a site adds its titles and any other
locale in its own `lang/<locale>.json`.

The link is Core's `/open/{object}/{id}` (`CoreClient::openUrl()`), which sends
a signed-in person to the record's page in Core, whatever its route; it needs
a Core release with that route (inly-core, `GET /open/{object}/{id}`); until
then the links answer 404. It opens in a new tab with the person's own Core session, so
CMS editors who sign in through Core are already signed in there. Store Core's
primary key in the document when the snapshot is keyed by something else (a
public id, say): the API answers with it as each resource's `id`. An id with a
`/` gets no link.

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

## Chat

A site's visitors talk to Core's chat agents through the site: the browser
calls the site's relay, and the relay calls Core with the visitor's
conversation. What the package gives:

- **The transport**, `Inly\CoreWeb\Chat\CoreChat`: `start($agent, $context)`,
  `read()`, `postMessage()` and `handoff()` against
  `{CORE_URL}/api/app/chat/{agent}/conversations`. Every call authenticates
  as the site, with its client token (the `CoreClient` credentials) as
  `Authorization: Bearer`, fetched afresh and retried once on a 401. A call on
  a conversation also sends the conversation's own access token, which Core
  returns once, in `X-Core-Conversation-Token`; Core serves a conversation
  only to the client that opened it, so another site's client gets a 404, and
  revoking the client closes its conversations. Every call names the visitor in
  `X-Core-Client-Id` (a random value kept in the session, so it outlives a
  regenerated session id; never the session id or an IP), which Core's
  per-visitor limits key on. A failure is a `ChatException` with Core's
  status (`busy()` 409, `limited()` 429, `invalid()` 422, `gone()` 404; 0
  when Core never answered), `retryAfter` when Core's rate limiter said how
  long to wait, and `exhausted` when a 429 means the conversation spent its
  budget, which no wait mends.
- **The transcript**, `ChatTranscript`: `{state, messages}`, each message
  with `id`, `role` (`user` or `assistant`; any other role is left out),
  `content`, `html`, `results` and `created_at`, plus `intent` on assistant
  messages. An assistant `content` of `{"reply": …, "intent": …}` (an agent
  with structured output) is unwrapped: `content` is the reply, `intent` the
  render mode; prose is `answer`. A result card keeps `title`, `subtitle`,
  `url` and `image` when they are strings (a `url` or `image` only as a web
  address or a path) and `fields` as a flat map of strings.
- **The relay**, `/!/core-web/chat/{agent}` under Statamic's action prefix,
  so it is never statically cached: `GET` (the transcript, or
  `{state: null, messages: []}`), `POST /messages` `{message, subject?}`,
  `POST /handoff` (`{summary, subject, contact: {name, email, phone}}`, every
  value null when Core could not say: a blank form is a normal outcome) and
  `DELETE` (start over). The first message opens the conversation and keeps
  its id and token in the session, by agent and conversation key. A
  conversation Core no longer knows (expired, cleared) is forgotten and the
  next message opens a fresh one. A `core_chat_{agent}` cookie (readable by
  scripts) says the session holds a conversation for the agent, so an idle
  page fetches nothing.
- **Limits of the site's own**: messages and handoffs get
  `chat.turn_rate_limit` per minute (10) per session and address, and
  `chat.turn_rate_limit_per_address` (30) per address however many sessions
  it opens, besides the routes' general `throttle:60,1`; a session holds at most
  `chat.max_conversations` (5) per agent, and a new subject past that is
  refused as `limited` without calling Core.

Errors are `{error: …}`:

| Error | Status | When |
|---|---|---|
| `busy` | 409 | A turn is already running in the conversation |
| `limited` | 429 | A rate limit, Core's or the relay's, or the conversation cap: wait. `Retry-After` is passed through when Core's limiter sent one |
| `exhausted` | 429 | The conversation spent its budget at Core. The relay has forgotten it, so offer to start over: the next message opens a fresh one |
| `invalid` | 422 | The message or subject was refused (empty, too long, screened by Core) |
| `unavailable` | 503 | Core failed or is unreachable, and when Core's rules for the agent's context refuse what the site's `ChatContext` built (a site bug); both are reported |

Core says a conversation is used up with a 429 like its rate limits; the
relay tells them apart by the answer's `code` (`conversation_exhausted`).

The access token never reaches the browser **as long as the session lives
on the server** (`file`, `database`, `redis`, …). With the `cookie` session
driver the session, tokens included, is the encrypted cookie itself, and at
around ten conversations it outgrows a browser's 4 KB cookie limit: use a
server-side driver with chat.
- **A headless front end**: an Alpine data factory and a Blade component that
  outputs only the wrapper.

Switch it on and name the agents:

```php
return [
    'features' => ['chat' => true],
    'chat' => ['agents' => ['assistant' => App\Chat\AssistantContext::class]],
];
```

Every `chat` key the site leaves out keeps its default, so a site's file
needs only `agents`.

### The context

What an agent is told about the page comes from one small class per agent,
built server side from the `subject` the browser names. The browser never
supplies the context itself: whatever it sent would reach the agent's prompt.

```php
use Illuminate\Http\Request;
use Inly\CoreWeb\Chat\ChatContext;

class AssistantContext implements ChatContext
{
    public function context(?string $subject, Request $request): array
    {
        $record = $this->record($subject);

        return $record === null ? [] : ['record_id' => $record->core_id, 'page' => $record->url()];
    }

    // A different key starts a fresh conversation: one per record.
    public function key(?string $subject, Request $request): string
    {
        return (string) ($this->record($subject)?->getKey() ?? 'general');
    }

    // Only a record that exists names a conversation, so a visitor cannot mint subjects.
    private function record(?string $subject): ?Record
    {
        return $subject === null ? null : (Record::find($subject) ?? abort(404));
    }
}
```

`key()` runs on every call and `context()` only when a conversation opens;
both must refuse a subject the site does not know (`abort(404)`), or every
made-up subject would be a conversation of its own. The agent decides which
context keys it accepts.

Agent keys are Core's: lowercase slugs (`a-z`, `0-9`, single hyphens). A
site naming any other key in `chat.agents` fails at boot.

### The front end

```js
import Alpine from 'alpinejs'
import coreChat from '../../vendor/inly/core-web/resources/js/core-chat.js'

Alpine.data('coreChat', coreChat)
```

```blade
<x-core-web::chat agent="assistant" :subject="$record->id" class="chat">
    <template x-for="message in messages" :key="message.id">
        <div :class="message.role">
            <div x-show="message.role === 'assistant'" x-html="message.html"></div>
            <p x-show="message.role === 'user'" x-text="message.content"></p>
        </div>
    </template>
    <p x-show="error" x-text="error"></p>
    <form x-on:submit.prevent="send($refs.input.value).then((sent) => sent && ($refs.input.value = ''))">
        <textarea x-ref="input" :disabled="pending"></textarea>
        <button :disabled="pending">Send</button>
    </form>
    <button type="button" x-on:click="reset()">Start over</button>
</x-core-web::chat>
```

State: `messages`, `state`, `pending`, `error` (one of the errors above, or
`expired` when the session and its CSRF token did), `retryAfter` (seconds,
when the answer said), `handoff`. Methods: `send(text)` (shows the visitor's
message at once and resolves true or false), `reset()` (on `exhausted`, this
clears the old transcript off the page), `requestHandoff()` (fills `handoff`),
`load()` (called on init only when the hint cookie is there). CSRF comes from
a `csrf-token` meta tag, else the `XSRF-TOKEN` cookie.

Render text with `x-text`. A message's `html` is safe for `x-html`: the server
rendered it with raw HTML escaped and unsafe links dropped. A result card's
values are plain strings, for `x-text` and bound attributes.

What stays in the site: the markup and its styling, the context classes, and
what a handoff becomes (the lead form, where it is sent).

What Core must have: inly-core v29.0.0 or later (the stock chat route), the
agent's key in `chat.agents` matching its `chatRoute()`, and the site's app
client (`CORE_CLIENT_ID`) holding the
`use_public_chat` scope (or the agent's own `chatPermission()`), which every
call needs, not only opening a conversation (without it Core answers 403,
which the relay reports as `unavailable`). A turn runs while Core answers, so
the web server's read timeout (nginx's `fastcgi_read_timeout`) must outlast
`chat.timeouts.turn`.

## Live reads

Snapshots hold what every visitor sees the same way. A live read is for what
a snapshot cannot hold, or does not need to: a calculation on what the
visitor typed, a figure that must be current, a short list fetched and cached
on the server. The site names each read, and nothing else of Core is
reachable: there is no pass-through to Core's API, since the site's client
token reads more than any page should show.

Snapshot or live read? Data that makes pages of its own (a detail page, a
sitemap entry, schema) is a snapshot: Core's pings refresh it the moment it
changes, and it outlives an outage. Data that only fills part of a page is a
live read.

A read is a `LiveRead` class:

```php
use Inly\CoreWeb\Live\LiveRead;
use Inly\CoreWeb\Live\LiveRequest;

class LoanQuote extends LiveRead
{
    public function public(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['item' => ['required', 'alpha_dash', 'max:40'], 'months' => ['required', 'integer', 'min:1', 'max:120']];
    }

    public function request(array $input): ?LiveRequest
    {
        return LiveRequest::post('/api/app/items/'.rawurlencode($input['item']).'/quote', ['months' => (int) $input['months']]);
    }

    public function present(array $json, array $input): ?array
    {
        return ['monthly' => $json['data']['monthly'] ?? null];
    }

    public function ttl(): int
    {
        return 60;
    }
}
```

- `public()` opens the read to pages' scripts at `/!/core-web/live/{name}`.
  It is false by default: a read is for server code only until the site says
  otherwise.
- `rules()` validates the visitor's input; only the keys it names reach
  `request()` (a nested key needs a rule of its own, `x.*`). Query-string
  input arrives as strings, so cast. Bound every rule of a `public()` read
  (`max:`, `in:`, `between:`, `alpha_dash` for what goes in a path): each
  distinct input is a call to Core and an entry in the cache.
- `request()` is the one call to Core, on the site's client token (retried
  once on a 401). `rawurlencode()` every input the path carries: a path with
  a `.` or `..` segment, a raw `?` or `#`, a backslash, a space or a control
  character is refused (`unavailable`), since visitor input builds it. Return null when the site already knows there is nothing to
  read: the answer is `not_found` without calling Core.
- `present()` is what the page gets. The default is Core's `data`; return null
  for an answer the site cannot show (`unavailable`).
- `ttl()` keeps an answer per input, in `live.store`, and lets the visitor's
  browser keep it for what is left of that (`private, max-age`). 0, the
  default, keeps none. `keep($data)` returning false keeps that one answer
  nowhere: a fallback Core gave while a source was down, say.
- `staleFor()` serves the last good answer for that many seconds past
  `ttl()` while Core fails, instead of `unavailable` (`$result->stale`, and
  never kept by the browser; the JSON says `stale: true`). 0, the default,
  serves none.

After Core itself fails a read (502, 503, 504, a timeout, unreachable), the
read answers at once for `live.retry_after` seconds (30): its stale answer,
or `unavailable`, rather than every visitor waiting out the timeouts again. A kept answer has
the shape `present()` gave when it was kept: after a deploy that changes that
shape, the old one is served until its `ttl()` (and `staleFor()`) runs out.

A read answers the same for every visitor: never put anything personal in one.
On a statically cached page, what server code read is frozen into the page
until it is flushed, and live reads send no pings: a figure that must stay
current there belongs in a script calling a `public()` read.

Switch it on and name the reads:

```php
return [
    'features' => ['live' => true],
    'live' => ['reads' => ['loan-quote' => App\Live\LoanQuote::class]],
];
```

Every `live` key the site leaves out keeps its default, so a site's file
needs only `reads`.

The browser calls a public read at `GET /!/core-web/live/loan-quote?item=a1&months=24`
(under Statamic's action prefix, so never statically cached; `throttle:60,1`
by default); any other name is a 404. Server code, a Livewire component
included, skips the route and may call every read:

```php
$result = app(Inly\CoreWeb\Live\CoreLive::class)->read('loan-quote', ['item' => $id, 'months' => $months]);

$result->successful(); // $result->data, or $result->status and $result->errors
```

| Answer | Status | When |
|---|---|---|
| `{data: …}` | 200 | Core answered and `present()` returned the page's data; with `stale: true`, the last good answer while Core fails |
| `{error: "invalid", errors: {field: [...]}}` | 422 | The site's rules refused the input, or Core did (its messages passed on for the fields in `rules()` only) |
| `{error: "not_found"}` | 404 | Core answered 404, `request()` returned null, or the route was asked for a read that is not `public()` |
| `{error: "unavailable"}` | 503 | Core failed, timed out (`live.timeouts`), is not configured, or `present()` returned null; logged as a warning |

## Website content

The site half of Core's website content protocol v1.1 (inly-core
`docs/learn/website-content/protocol-v1.md`): Core reads a site's pages and
writes drafts to it, every call sealed with Core's own Ed25519 key, and the
site pings Core when a page changes. core-web opens the envelopes and sends
the pings; the endpoints, their routes and their JSON are the site's own.

### Routes

With `features.content` on, the `core-web.envelope:<purpose>` middleware opens
the envelope before the controller runs:

```php
Route::middleware('throttle:120,1')->prefix('api/core/v1')->group(function () {
    Route::get('content', [ContentController::class, 'index'])->middleware('core-web.envelope:website.read');
    Route::post('drafts', [DraftsController::class, 'store'])->middleware('core-web.envelope:website.draft');
    Route::post('drafts/{id}/publish', [DraftsController::class, 'publish'])->middleware('core-web.envelope:website.publish');
});
```

Register the routes outside the `web` group, in a routes file of their own
rather than `routes/api.php`, whose `api` prefix would make the path
`/api/api/core/v1`:

```php
// bootstrap/app.php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    then: fn () => Route::group([], base_path('routes/core-api.php')),
)
```

Keep `TrimStrings` and `ConvertEmptyStringsToNull` off them. On Laravel 11 and
later both are global middleware, so exclude the paths there. The envelope
binds the raw query string and body either way, but the controller should
read what Core sent:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/core/v1/*')]);
    $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/core/v1/*')]);
})
```

The protocol has the site answer over https outside a local environment.
core-web does not check the scheme of a request it opens: the site's
endpoints rely on the web server (or the proxy in front of it) to serve them
over https only.

The middleware checks, in the protocol's order:

1. the headers;
2. Core's signature, under a key id from Core's key document;
3. the payload;
4. the audience, which is the site's `client.client_id`;
5. the expiry, which is at most six minutes ahead;
6. the purpose;
7. the method;
8. the raw path;
9. the canonical query;
10. the body's SHA-256;
11. the nonce, spent once per audience until the envelope expires.

A draft or publish must also carry the site's environment (`content.environment`).

Every answer that passes through it, the controller's included, carries
`Cache-Control: no-store` and `X-Statamic-Uncacheable`. The claims are on the
request: `VerifyCoreEnvelope::claims($request)`.

| Answer | When |
|---|---|
| 403 `{"error":"envelope_refused"}` | No envelope, a bad signature or an unreadable payload: nothing is said about why |
| 403 `{"error":"envelope_refused","reason":"…"}` | The signature verified and a later check failed: `audience`, `expired`, `purpose`, `method`, `path`, `query`, `body` or `replayed` |
| 403 `{"error":"wrong_environment","message":"…"}` | A draft or publish sealed for another environment |

Core's key document (`{core}/.well-known/core-signing-keys`) is read over
https only (plain http in `local` and `testing`), from the paired Core alone:
a redirect is not followed. A copy is refreshed once it is four minutes old:
one call takes the refresh and the rest keep opening with the copy, as they do
when the refresh fails, with a retry every 10 seconds. A copy five minutes
old opens nothing, whether or not Core can be read, so a key Core retires
stops opening envelopes within five minutes even while the fetch fails. A
site with no copy refuses until a fetch succeeds, and tries again after 10
seconds, so it picks Core up soon after Core comes back. An envelope naming a
key id a fresh copy lacks refetches at most once a minute, since the key id
is read before anything is verified.

An envelope that arrives with no request to bind it to, such as one handed
over in a URL, is opened with
`app(CoreEnvelope::class)->open($payload, $signature, $keyId, $purpose)`. It
checks steps 1 to 6 and spends the nonce. Use it only for a purpose whose
claims say everything the envelope allows. An envelope that carries a
`method`, `path`, `query` or `body_sha256` claim was sealed to a request and
is refused here with that reason, so a `website.*` call never opens without
the request it binds.

### Pings

`app(ContentPings::class)->send($pageId, 'saved'|'deleted')` posts
`{"type":"page","id":…,"action":…,"nonce":…}` to
`{core}/webhooks/connectors/{content.connector_id}` with the client token.
The nonce is new for every attempt. `enabled()` is false until the client and
the connector id are set. `send()` throws when Core does not accept the ping,
so call it from a queued job and let the queue retry.

Each pairing has a connector of its own in Core, so a staging site sets its
own `content.connector_id` (`CORE_CONTENT_CONNECTOR_ID`). One that keeps the
production site's id pings the production connector with its own client
token, and Core answers every ping with a 401.

### Testing

`fakeCore()` publishes a test key as Core's. `callSealed($method, $uri,
$purpose, $body, $claims)` calls a content route sealed with it, with a JSON
body sent as the exact bytes it sealed. `$claims` replaces sealed claims, and
a `null` value leaves a claim out. `coreEnvelopeHeaders()` gives the headers
alone, and `CoreEnvelopeSealer` seals with a key of your own.

```php
$this->fakeCore();
$this->callSealed('GET', '/api/core/v1/content?limit=10', 'website.read')->assertOk();
$this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', $body, ['environment' => 'staging'])
    ->assertForbidden()->assertJson(['error' => 'wrong_environment']);
```

core-web's own suite runs the protocol's test vectors
(`tests/fixtures/website-content-protocol-v1-test-vectors.json`): those that
use Core's key as given, and the shared-secret ones re-sealed with the test
key, so their method, path, query and body refusals run against Core's key too.

## Control panel link

`features.cp_link` adds a link to Core right under Dashboard in the control
panel, for every CP user, opened in a new tab so unsaved CP work stays open.
It points at `client.base_url` (`CORE_URL`). On a local machine without one it
points at the remote Core (`remote.base_url`), once its client id and secret
are set, since that is the Core the developer's data came from.
With neither there is no link.

The site names the link and may pick its icon, in its own
`config/core-web.php`; the icon is a Statamic icon name or an SVG, and null
keeps Core's mark:

```php
return [
    'features' => ['cp_link' => true],
    'cp_link' => ['label' => 'Acme Core', 'icon' => null],
];
```

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
