# inly/core-web

The website half of an Inly Core integration, for Statamic sites that show data
an Inly Core backoffice owns. Core stays the source of truth; the site keeps a
**snapshot** of the objects it shows in its own cache, refreshes it on a
schedule and whenever Core **pings** a change, and never pulls from Core while
a visitor waits (unless the cache is completely cold).

What the package gives a site:

| Piece | Class | What it does |
|---|---|---|
| Snapshot definition | `Inly\CoreWeb\Snapshots\Snapshot` | Where a snapshot lives in the cache, its TTLs, how to pull it whole and one object, partitions (per site), empty guard, change listeners, sitemap, pages |
| Snapshot store | `Inly\CoreWeb\Snapshots\SnapshotStore` | Three cache layers (fresh marker, last good, backoff), cold-pull lock, write lock, single-object patching, no-op writes skipped, change fingerprints and change sets |
| Registry | `Inly\CoreWeb\Snapshots\SnapshotRegistry` | Every snapshot by type, which ping types refresh which, idle ping types, custom warmers and jobs |
| `core:warm` | `Inly\CoreWeb\Snapshots\WarmSnapshotsCommand` | Pulls every registered snapshot, or one; scheduled nightly by default, as the safety net under Core's pings |
| Refresh job | `Inly\CoreWeb\Snapshots\RefreshSnapshotJob` | What a ping queues: patches one object, or warms the type |
| Degraded guard | `Inly\CoreWeb\Snapshots\UncacheableWhileDegraded` | Keeps a page built on an empty degraded read out of Statamic's static cache |
| Core client | `Inly\CoreWeb\Client\CoreClient` | Passport client_credentials client for Core's Objects API (JSON:API) |
| Ping endpoint | `Inly\CoreWeb\Ping\PingController`, `PingSignature` | `POST api/core/objects`, HMAC-signed by Core |
| Snapshot pages | `Inly\CoreWeb\Snapshots\SnapshotPage` | For a snapshot that declares `->pages()`: find a document for the site's own route (503 while degraded, Statamic's 404 when gone), 301 a stray path to its page, render through Statamic's View |
| Snapshot sitemaps | `Inly\CoreWeb\Sitemaps\*` | `/sitemap-core-{type}.xml` per site host, and the `{{ core_sitemaps }}` tag |
| Static cache helpers | `Inly\CoreWeb\StaticCaching\SiteCache`, `SiteScopedInvalidator` | Flush the pages a snapshot change touches (or the whole site when they are not known); scope the `all` invalidation rule to the saved item's site |
| Rich text | `Inly\CoreWeb\Support\CoreHtml` | Narrows Core's `*_html` fields to prose markup |
| Chat relay | `Inly\CoreWeb\Chat\*` | A site's visitors talking to Core's chat agents: the transport (`CoreChat`), the relay routes, the per-agent `ChatContext`, a headless Alpine factory and `<x-core-web::chat>` |
| Own form markup | `Inly\CoreWeb\Forms\*` | A Livewire form with the site's own markup posting to a Core form (`CoreForm`): defaults, validation and missing required fields from Core's definition, and the entry sent on the site's token |
| Website content | `Inly\CoreWeb\Content\*` | Opens the envelopes Core seals with its own key for a site's content endpoints (`core-web.envelope:<purpose>`), reads Core's published keys, and pings Core when a page changes |
| CMS sign-in | `Inly\CoreWeb\CmsSignIn\*` | The control panel signed into from Core alone: the hand-off and offboarding routes, a user provider that admits only the accounts Core provisioned, the CP login redirected to Core, and `core:reconcile-cms-users` |
| Media | `Inly\CoreWeb\Media\*` | `<x-core-img>`, `<x-core-video>`, `<x-core-media>` over the CDN (`CoreCdnScheme`), asset disks on the bucket with a layer outside production (`MediaDisks`, `LayeredAdapter`), `media:sync`, `media:check` |
| Test helpers | `Inly\CoreWeb\Testing\FakesCore`, `CoreEnvelopeSealer` | A fake Core over `Http::fake()`, pings signed as Core signs them, and content calls sealed with a test key published as Core's |

What stays in the site: the domain snapshots (which Core types, what a stored
document looks like, how the pages read it), the page routes and views, the TTL
choices, the chat's markup, context and lead handling, and anything else that
is not plumbing.

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
| Drafts | `features.drafts` | The signed preview route (`drafts.preview.path`), the `core-web::drafts` translations and the listener that keeps a draft's provenance through an editor's save (see [Drafts](#drafts)) |
| Content checks | `features.checks` | `content:check` and its nightly schedule (`checks.schedule`), the `content_checks` dashboard widget (see [Content checks](#content-checks)) |
| Forms | `features.forms` | The `core-web-forms` snapshot on Core's `forms` ping and the `core-web` view namespace (see [Forms](#forms)); needs `features.snapshots` |
| CMS sign-in | `features.cms_sign_in` | The hand-off route (`/admin-sso`) and the offboarding route, the `core-web-sso` user provider on the CP guard, the global middleware that sends the CP login to Core, and `core:reconcile-cms-users` with its schedule (see [CMS sign-in](#cms-sign-in)) |
| Control panel link | `features.cp_link` | A link to Core right under Dashboard in the control panel (see [Control panel link](#control-panel-link)) |
| Laravel Cloud | `features.cloud` | On Laravel Cloud, the config a site needs there; everywhere, `content:import` and `core-web:deploy` (see [Laravel Cloud](#laravel-cloud)) |
| Media | `features.media` | `<x-core-img>`, `<x-core-video>` and `<x-core-media>`, the asset disks in `media.disks` (unless the app defines them), the `core-web-layered` disk driver, `media:sync` and `media:check`; with `features.content_sync` on a `local` or `testing` app, the `core-web-remote-index` driver and the `/!/core-web/media` route `Asset::url()` resolves through (see [Media](#media)) |
| Website content | `features.content` | Lets the `core-web.envelope` route middleware open envelopes; switched off, it refuses every call. The site registers its own content routes behind it (see [Website content](#website-content)) |
| Content pull | `features.content_sync` | On the live site, the sync endpoints under `/!/core-web/sync` (`core-web.sync` middleware), the CP page that approves a machine and the **Tools → Content sync** page that lists and revokes them; on a checkout, `content:login`, `content:logout`, `content:pull` and `content:push` (see [Content pull](#content-pull) and [Content push](#content-push)) |

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
| — | `core.url` / `core.client_id` | Committed, not env: the Core this site belongs to and its app client id. Fill `client.base_url` / `client.client_id` on every server where neither `CORE_URL` nor `CORE_CLIENT_ID` is set; never where `APP_ENV` is `local` or `testing`, so a checkout runs with exactly `APP_ENV=local`. A server meant to have no Core cannot opt out with an empty `CORE_URL`: once `core.url` is committed, every server not on `local` pairs with it |
| `CORE_URL` | `client.base_url` | Override only: a Core other than the committed one (a staging site paired with a staging Core, a local backoffice). A local checkout never points it at a remote Core: the live site stands in for Core there (`content:login`), and `core-web:doctor` fails on one |
| `CORE_CLIENT_ID` / `CORE_CLIENT_SECRET` | `client.client_id` / `client.client_secret` | The site's Passport client_credentials client: `CORE_URL` and `CORE_CLIENT_ID` override `core` together or not at all (one without the other leaves the client incomplete, which the doctor fails); the secret is always env |
| `CORE_CA_BUNDLE` | `client.ca_bundle` | Local only: a CA bundle for Core's self-signed certificate |
| `WEBSITE_SYNC_SECRET` | `ping.secret` | The HMAC secret a Core still on the shared secret signs pings with (the backoffice's `WEBSITE_SYNC_SECRET`). A ping sealed with Core's own key (purpose `website.objects`, audience `CORE_CLIENT_ID`) needs none, so blank it once Core seals every ping. **Empty rejects every shared-secret ping**; there is no `APP_KEY` fallback |
| `CORE_CONTENT_ENVIRONMENT` | `content.environment` | The environment a draft or publish must be sealed for, when the site runs in another `APP_ENV` than its Core (a live site on `APP_ENV=staging` paired with production Core sets `production`). Empty is `app()->environment()` |
| `CORE_CONTENT_CONNECTOR_ID` | `content.connector_id` | The id of the Core connector that holds the site's pairing; content pings go to `{core}/webhooks/connectors/{id}`. Empty sends none |
| `CORE_CMS_SITE` | `cms_sign_in.site` | This site's key among the CMS sites its Core signs into; `production` when unset |
| `CORE_MEDIA_LAYER` | `media.layer.enabled` | `false` on a server set up before the site is live (staging while the content is built): it writes the bucket directly. Remove it at go-live. On a Laravel Cloud bucket the layer is off unless this is `true` |
| `CORE_MEDIA_KEY` / `CORE_MEDIA_SECRET` | `media.bucket.key` / `media.bucket.secret` | The bucket key of a server that serves the asset disks from the bucket. Not needed locally, nor on Laravel Cloud with a bucket attached (see [Media](#media)) |
| `LARAVEL_CLOUD_DISK_CONFIG` | `media.bucket.cloud` | Set by Laravel Cloud, never by hand: its attached buckets, used when no key is set (see [On Laravel Cloud](#on-laravel-cloud)) |
| `CORE_PUBLIC_DISK` | `media.bucket.cloud_disk` | The Cloud disk the asset disks use on Laravel Cloud, when not `media` (the public bucket Core Cloud attaches to a website; see [On Laravel Cloud](#on-laravel-cloud)) |
| `CORE_CDN_BASE_URL` | `media.bucket.cdn_url` | Set by Core Cloud's CDN integration: the CDN in front of the Cloud bucket, the files' URL when the site commits no `media.bucket.url` (see [On Laravel Cloud](#on-laravel-cloud)) |
| `CORE_SNAPSHOT_STORE` | `snapshots.store_driver` | `file` (default) or `database`: what the `snapshots` store is (see [Where snapshots live](#where-snapshots-live)) |

`config/core-web.php`:

| Key | Default | Meaning |
|---|---|---|
| `features.snapshots` | `false` | Switch on Core snapshots, pings and snapshot sitemaps (see [Features](#features)) |
| `client.token_cache_key` | `core_web_access_token` | Where the access token is cached (default cache store) |
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
| `features.media` | `false` | Switch on the media components and disks (see [Media](#media)) |
| `media.cdn.origins` | `['https://cdn.inlycore.com']` | The transforming CDN's base URLs; only URLs under one get transform params. `[]` turns transforms off |
| `media.cdn.scheme` | `CoreCdnScheme` | The `UrlScheme` that builds transform URLs (core-cdn's query params); `PassthroughScheme` builds none |
| `media.bucket.*` | | `endpoint`, `region` (`auto`), `bucket`, `url` (the files' public base, the CDN's), `root` (a folder every disk shares), `acl` (`true`; `false` for a bucket without object ACLs, such as R2), `cache_control` (`public, max-age=2592000`) |
| `media.disks` | `['assets' => 'assets']` | Disk name => its folder, in the bucket and in `public/` |
| `media.layer.enabled` | `CORE_MEDIA_LAYER`, unset | Outside production, keep production's files behind the layer. Unset means on, except on a Laravel Cloud bucket. A server set up before go-live sets `false` (see [The disks](#the-disks)) |
| `media.layer.prefix` / `.local` | `_staging` / `media-layer` | Where the layer is: a prefix in the bucket, or `public/<local>` in the `local` environment |
| `media.images.*` | `format` `auto`, `placeholder` `blur` | `<x-core-img>`'s defaults |
| `media.videos.*` | `width` 1280, `small_width` 720, `poster_time` 0 | `<x-core-video>`'s CDN widths and frame poster (`null`: none) |
| `chat.session_key` / `chat.cookie` | `core_web_chat` / `core_chat` | Where conversations live in the session; the hint cookie's prefix (`core_chat_{agent}`) |
| `features.cms_sign_in` | `false` | Switch on CMS sign-in (see [CMS sign-in](#cms-sign-in)) |
| `cms_sign_in.site` | `CORE_CMS_SITE`, else `production` | This site's key among the CMS sites Core signs into |
| `cms_sign_in.roles` | `[]` | Core CMS grant => Statamic role handle. An unmapped grant is inert; a sign-in with nothing mapped (and not an admin) is refused |
| `cms_sign_in.local_password_sign_in` | `false` | With `APP_ENV=local`, restore Statamic's own login for development without Core |
| `cms_sign_in.path` / `.name` / `.middleware` | `admin-sso` / `core-web.cms-sign-in` / `['web', 'throttle:core-web.cms-sign-in']` | The hand-off route, always in `web` (it signs into a session); `rate_limit` (10) a minute per address |
| `cms_sign_in.revoke.*` | `api/core/cms-users/revoke`, `core-web.cms-users.revoke`, `['throttle:120,1']` | The offboarding route, outside the `web` group |
| `cms_sign_in.reconcile_schedule` | `45 3 * * *` | When `core:reconcile-cms-users` runs; `null` schedules nothing |
| `cms_sign_in.core.*` | `/sso/cms`, `/api/app/cms-sso/client/verify`, `/api/app/cms-users/client/reconcile` | Core's paths |

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
the local snapshots from the live site, so the site renders what the live
site renders without a local Core:

```bash
php artisan core:pull            # every registered snapshot
php artisan core:pull staff      # one
php artisan core:pull --release  # unpin and forget: the site reads CORE_URL again
```

It needs no secret: on a site with [Content pull](#content-pull) switched
on, `core:pull` reads the snapshots from the live site's sync API
(`/!/core-web/sync/snapshots/{type}`, as the developer's CMS user with the
Pull content permission), on the same login as `content:pull`: one login
covers both, and in a terminal either command starts it when the machine has
none yet or the site refused the one it had.
That permission therefore also reads the whole of every snapshot the site
keeps, not only what its pages show, and the approval screen says so.

- The live site serves the copy it renders from; only when it has nothing
  cached yet does it pull once from its own Core with its own client,
  cached as a render's pull would be. That first pull can take longer than
  the checkout waits: a timeout says the site may still be filling its copy,
  and a pull a minute later reads it.
- It serves only the snapshots it registers itself, paged
  (`content_sync.page_size`). A copy that changes between pages starts the
  pull of that snapshot over, twice at most, before it pins nothing.
- A rate limit (429) or a site with no copy to serve yet (503) is waited
  out once its `Retry-After` says, a minute at most, a few times over.
- A copy the live site holds stale (its Core unreachable lately) is pinned
  and said so.
- The live site needs `features.snapshots` and the same snapshots
  registered; a site that lacks one answers 404 for it, and one whose
  core-web predates this endpoint is named as such.
- A snapshot's warmer does not run: its documents are pinned as the live
  site stored them.

Without `content_sync.remote_url` there is nothing to pull from, and a run
with no terminal (`--no-interaction`, CI) and no login stops and says to run
`php artisan content:login`.

What it stores is **pinned**: kept without expiry, and no `core:warm`, ping
or render replaces it until the next `core:pull` or `--release`. A pull that
fails, or comes back too empty to trust (`emptyWhen()`), pins nothing and
leaves an earlier pin alone. `--release` forgets the pulled copies, since
another Core's data is no fallback for this one, so the next read or warm
pulls from `CORE_URL` as from a cold cache.

- **Local only.** A pinned copy stops a site following its Core, so the
  command runs only where `APP_ENV` is `local` (`--release` runs anywhere).
- **Stays on the machine.** The copies live in the `snapshots` store
  (`storage/framework/snapshots` with the `file` driver, its own tables with
  `database`), never in the repository.
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

## Pages

Not every snapshot is a page, so a snapshot opts in with `->pages()`: given a
document and a site handle, the path of its page on that site, or null for a
document without one there. Nothing is routed by the package; the site keeps
its routes and URL shapes, and `SnapshotPage` does the part every such
controller repeats:

```php
Snapshot::make('locations')
    // ...
    ->pages(fn (array $doc, string $site) => '/locations/'.$doc['slug']);

Route::get('locations/{slug}', function (Request $request, string $slug) {
    $pages = SnapshotPage::for('locations');                 // the current site; for('locations', 'second') for another
    $location = $pages->find(fn (array $doc) => $doc['slug'] === $slug || in_array($slug, $doc['old_slugs'], true));

    return $pages->canonical($request, $location)              // 301 to its own path, or null
        ?? $pages->render('locations.show', ['location' => $location, 'title' => $location['name']], Entry::findByUri('/locations'));
});
```

- `find($id)` or `find(fn (array $doc, string $id) => bool)` reads the site's
  partition and returns a document that has a page there (a closure only sees
  documents with one). None is a **503** with `Retry-After`
  while nothing at all can be read (a cold cache and Core unreachable), so an
  outage cannot deindex the pages, and Statamic's `NotFoundHttpException`
  otherwise: `abort(404)` renders Laravel's error view, whose layout lacks the
  cascade and fails.
- `canonical($request, $doc)` answers a request that reached the document at
  another path (an old slug, a renamed brand) with a 301 to its page on the
  site, under the site's own path on a shared host, percent-encoding a
  non-ASCII path. The query string goes
  along (campaign tags, filters); `keepQuery: false` drops it.
- `render($template, $data, $cascade)` is Statamic's View, with `$cascade`
  (an entry, say the listing's) as the cascade's content when the layout
  should read its title or SEO fields.
- `path($doc)` is the document's page path on the site, for links.

`SnapshotPage::for()` on a snapshot without `->pages()` throws a `LogicException`.

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

## Forms

For the rare form Core's embed cannot render: a step flow with photo slots,
price cards, anything that needs the site's own markup. The form is a
Livewire component with that markup, and it still posts to a Core form, so
the entries, the handlers and the admin's field list stay in Core. Every
other form renders Core's embed.

Needs Livewire (`livewire/livewire` ^3.6 or ^4.0, or the Statamic Livewire
addon) and `features.snapshots`. Switch on `features.forms` and name each
form class in `forms.classes`:

```php
// config/core-web.php
return [
    'features' => ['snapshots' => true, 'forms' => true],
    'forms' => ['classes' => [App\Livewire\DamageInspection::class]],
];
```

```php
use Inly\CoreWeb\Forms\CoreForm;

class DamageInspection extends CoreForm
{
    // The key of the Core form it posts to.
    public static function coreForm(): string
    {
        return 'damage-inspection';
    }

    // The Core fields the view renders.
    public static function renders(): array
    {
        return ['plate', 'name', 'email', 'photos', 'damage'];
    }

    protected function fieldsView(): string
    {
        return 'livewire.damage-inspection';
    }
}
```

The view holds the fields only, bound to `values.{field}`, and reads the
definition through `$coreForm` (`FormView`: `form()`, `options()`,
`visible()`); the component wraps it in the `<form>`, followed by any
required field the view leaves out, the form's privacy notice and a submit
button. What the definition carries does the rest:

- **Defaults**: `values` start with each field's default.
- **Missing required fields**: a required field the view does not render
  (`renders()` does not name it) is rendered after the view with default
  markup (`core-web::forms.field`), so an entry still passes Core's checks
  when an admin adds a required field. Conditions count: a field required
  only when another one has a value appears when it does. In `local` a
  warning above them names them. Place them yourself with
  `@include('core-web::forms.missing')`; `CoreForm::missingFrom()` finds
  them in a test.
- **Validation**: `submit` checks the visible fields against the
  definition (required, email, url, number, date, the offered options, file
  counts and sizes) before anything is sent; add rules with `extraRules()`.
  Conditions are read as Core reads them: a field a condition hid reads
  blank to the fields after it, and a choice with no option left is hidden.
- **Hidden fields** with a `source` are filled from the page the form was
  rendered on (`page.url`, `referrer`, `page.lang`, `query.{key}`);
  `pageTitle()` and `context()` supply `page.title` and `context.{key}`.
- **Sending**: the entry goes to `POST {core}/api/forms/{uuid}/submit` on
  the site's client token (files as multipart parts with neutral names),
  which Core takes for a form linked to the site's app client. Core's 422
  errors show on their fields. `$status` is then `sent`, `invalid`,
  `throttled` or `unavailable`; `sent()` runs after Core took an entry.
- **Bots**: Core's honeypot, Turnstile and nonce guard the embed, not an
  entry the site sends on its token, so the site guards its own forms: a
  honeypot field in the wrapper (a filled one is answered `sent` and goes
  nowhere), and `forms.submit_rate_limit` (5) entries per minute per address
  and form. The address is `request()->ip()`: behind a proxy, trust it
  (TrustProxies) or every visitor shares one limit. Add more (Turnstile, a
  question) in the view and `extraRules()` when a form draws spam.
- **Static cache**: the component captures the visitor's page (referrer,
  query string) when it renders, so on a statically cached page render it
  inside `@nocache`, or the first visitor's values go to everyone.
- **Language**: the definition is read in the app's locale when the
  snapshot is pulled, so labels and messages are in the site's default
  language.

Override `submitView()` for another button (null when the view has its own),
and put your own `resources/views/vendor/core-web/forms/*.blade.php` in
place of the default markup.

### Marking the form in Core

Core cannot tell that a site renders a form with its own markup, so tick
**Custom form embed** on the form's General tab in Core. Its Fields tab then
warns admins not to change the fields without asking a developer. A form
the site renders must also take server entries (`accepts_server`); the pull
logs a warning when it does not.

### Keeping up with Core

`tests/fixtures/core-form-definition-contract.json` is a copy of Core's
definition contract (docs/learn/forms/definition-contract.md in inly-core):
one schema that uses every field type and key, and the definition Core
serves for it. `FormDefinitionContractTest` checks the reader against it,
including that every key a field carries is one `FormDefinition::KEYS`
knows. When Core's contract changes, replace the copy and catch up on what
fails. A field type the reader does not know renders as a text input and is
logged.

### Forms on a local checkout

A local checkout without a Core (no `CORE_URL`) sends its Core forms
through the live site, on the machine's `content:login`, so they render and
submit locally with no Core secret. It needs `forms` and `content_sync` on
both sides and `APP_ENV=local` here; a server never relays.

- **Core's embed**: give it `CoreForms::embedBaseUrl()` as its Core address
  and load its script from under it (`/public/forms/embed/v1.js`). That is
  the site's Core when one is set, and otherwise the local relay, which
  forwards the embed's definition, nonce and submit calls to the live site.
  The live site sends them on to its Core as its own origin, so the form's
  origin allowlist needs nothing for local work. `CoreForms::isRelayed()`
  says which.
- **`CoreForm` classes**: their entries go to the live site, which sends them
  with its own client token.
- **Only the live site's own forms**: the live site relays a form only when
  it is in its form list (the `core-web-forms` snapshot), and lets one
  machine send 10 entries a minute.
- **Only this machine**: the local relay answers a loopback peer
  (127.0.0.0/8, `::1`) with no proxy or forwarding header (`X-Forwarded-For`,
  `Via`, …) and is a 404 to anything else, so a LAN visitor or a shared
  tunnel cannot send entries on the developer's login. A page served to
  anyone else gets no relay either: `embedBaseUrl()` is null there. A site
  run in Docker (Sail, DDEV) sees the bridge's address as the peer, so it is
  not covered: run the site on the host (Herd, `php artisan serve`) to use
  the relay.
- **Uploads**: one entry carries at most 10 MB of files through the relay
  (`core-web.forms.relay_max_upload_bytes`); more is a 413.

What a relayed form sends is a real entry. `<x-core-web::forms.relay-notice />`
says so above the form while forms are relayed, and renders nothing
otherwise; a `CoreForm` shows it by itself, an embed needs it placed beside
it. A form whose Core uses Turnstile cannot be relayed: Turnstile binds its
answer to the page's host.

## Media

`features.media` renders a site's pictures and videos through the
transforming CDN (core-cdn), whatever they are: a Statamic Asset, an assets
field (its Value, Collection or query builder), a URL, or a file from Core
(`url`/`original_url`, `alt`, `focus`/`focal_point`, `width`, `height`,
`mime_type`). It also defines the asset disks on the site's bucket.

### The components

```blade
<x-core-img :src="$set['image']" :widths="[480, 960, 1440]" sizes="(min-width: 1024px) 50vw, 100vw" ratio="4/3" class="h-full w-full object-cover" />
<x-core-video :src="$set['video']" class="h-full w-full object-cover" />
<x-core-media :src="$set['media']" ... />
```

- **`<x-core-img>`**: an image under a CDN origin gets `format=auto` (the CDN
  picks AVIF, WebP or the original per browser), the `width`, `height`,
  `quality`, `fit` and `trim` you pass, a srcset from `widths`, and a 24px
  blurred placeholder (`placeholder="none"` turns it off). `ratio` reserves
  the box and bounds each rendition's height. Anything else (a local file, a
  foreign host, an SVG) renders as is, without CDN transforms. A local
  Asset (JPEG, PNG or WebP) still gets a srcset from the site's Glide
  presets named `w<px>` (`w640`, `w1080`, …), those narrower than the
  original plus the original at its own width, and `sizes` defaults to
  `100vw`: pass a real `sizes`. A `w<px>` preset resizes by width only
  (`fit=max`), or its candidates come out the wrong shape. Only presets are
  rendered, so keep
  `statamic.assets.image_manipulation.generate_presets_on_upload` on (files
  uploaded before the presets existed render on their first view); if any
  preset fails to render, the image has no srcset. The editor's focal point (an
  Asset's `focus`, Core's `focalPoint`) becomes `object-position` unless you
  pass a `style`, and `alt` defaults to the file's own.
- **`<x-core-video>`**: a video under a CDN origin plays CDN versions, 1280px
  wide and 720px on phones (`width`, `small-width`), silent when it plays
  muted (`audio=false` makes them smaller), with a frame of the video as its
  poster (`poster-time`, or `poster` for an image of your own) and a 24px
  version of that poster blurred behind it until the poster or the first
  frame arrives (`placeholder="none"` turns it off; `media.images.placeholder`
  sets the default for videos too). Anything else plays the
  file itself. It autoplays muted in a loop by default: `:autoplay`,
  `:muted`, `:loop`, `controls`, `preload`.
- **`<x-core-media>`**: one or the other, by the source, with each getting only
  its own options: for a field an editor fills with either.

`width` and `height` on `<x-core-img>` are the rendition's size, not HTML
attributes: give the box its size in CSS or with `ratio`, or the page shifts
as the image arrives. The video poster is one frame at `width`, for phones too.

The CDN builds a video version on its first request (30 to 60 seconds for a
large file) and keeps it a year, so open each new video once after uploading.
Its limits are Cloudflare's: a source under 100 MB and 10 minutes, and a
version at most 60 seconds long.

The placeholder and the reduced-motion pause need the shipped CSS and script:

```css
@import '../../vendor/inly/core-web/resources/css/core-media.css';
```

```js
import coreMedia from '../../vendor/inly/core-web/resources/js/core-media.js'

coreMedia() // before Alpine.start()
```

### The disks

Each disk in `media.disks` is defined unless the app's
`config/filesystems.php` has one by that name, so **remove the site's own
`assets` disk** (Statamic's skeleton ships one) and any other it lists there;
`media:check` says when the app's definition is the one in use. The bucket
needs `composer require league/flysystem-aws-s3-v3`.

| Where | With `CORE_MEDIA_KEY` | Without |
|---|---|---|
| production | the bucket | the local folder `public/<folder>` |
| staging before go-live (`CORE_MEDIA_LAYER=false`) | the bucket | the local folder |
| Laravel Cloud, with a bucket attached and no key | its bucket (no layer unless `CORE_MEDIA_LAYER=true`) | n/a |
| `local`, pulling content (`features.content_sync` and a `remote_url`) | the bucket, behind a layer | the live site's pulled index, behind a layer |
| anywhere else | the bucket, behind a layer | the local folder |

The layer is what makes the shared bucket safe outside production. Writes,
edits (an asset's `.meta` included) and deletes land in it, reads try it
first, and a file only the bucket holds cannot be deleted or moved: staging
and a laptop see production's files and never change them. On staging the
layer is `_staging/` in the bucket; in the `local` environment it is
`public/media-layer/` (add it to `.gitignore`).

Before go-live there is no production content to protect, and staging is
often where editors build it, so a staging server set up then gets
`CORE_MEDIA_LAYER=false` and writes the bucket directly. It is a server's
setup, not the site's code, so it is env: `main` and `develop` stay alike.
At go-live, remove it from staging's env (the launch checklist's step): the
layer is on from then on, and what staging uploaded before is already in the
bucket at the paths production serves, so nothing is copied. A production
server on a temporary domain writes the bucket anyway.

Refusals are loud: deleting, renaming or moving one of production's files
throws `ProductionFileLocked` whatever the disk's `throw`, so Statamic stops
before it strips the asset from entries or saves its new path. A layer file at
the same path as one of production's hides it, and deleting it shows
production's again.

A file in the layer renders from the layer's URL in the components. Anywhere
else, Statamic's `Asset::url()` points at the bucket, where it is not: use
`Inly\CoreWeb\Media\MediaSource::urlOf($asset)` for a URL of your own (an
og:image, a feed). The control panel's links to it 404; its thumbnails, which
Glide reads through the disk, show.

Without a key a disk is its committed local folder: a fresh clone, CI and a
server missing the key all render from it. The exception is a laptop that
pulls content (`APP_ENV=local`, `features.content_sync` and `features.media`
on, `content_sync.remote_url` set): there the disk reads three layers, first
match wins. On top is `public/media-layer/`, as with a key: uploads, and
edits to a pulled file's alt or focus, land there and survive the next
pull. Below it the committed folder `public/<folder>`, read only. At the
bottom the live site's asset index as `content:pull` last brought it
([Content pull](#content-pull) → Assets), so no key is needed to see and
use production's files: it lists them, serves their `.meta` from the index
and reads their bytes from their public URLs (Glide's CP thumbnails fetch
them over HTTP, a file at a time, never following a redirect). Deleting or
moving a committed or pulled file throws `ProductionFileLocked`. Before the
first pull the disk shows the layer and the committed folder.

In this mode `Asset::url()` is a local route, `/!/core-web/media/<disk>/<path>`
(registered only when `APP_ENV` is `local` or `testing`), that redirects to
whichever layer holds the file: the layer's or the committed folder's URL,
else the live file's public URL from the index, else a 404. Statamic builds
every asset's URL on its container's one URL, so the route is what lets a
local upload and a live file both resolve. The components skip the redirect
and render the file's own URL, so the CDN still transforms live files. So
does the CP: a file only the index holds gets its thumbnail from the CDN at
the CP's thumbnail size (the plain public URL when the CDN does not serve
it; a video a CDN frame, or no thumbnail) and its preview from its public
URL. Glide could read it through the index, but would download every live
file to make one thumbnail the CDN already renders. Uploads and committed
files keep Glide's thumbnails.

Everything but the key and secret is committed in the site's
`config/core-web.php`. Unlike the other top-level keys, a site's `media`
block keeps the package's defaults for what it leaves out, one group deep
(the key and secret from env, the CDN, the layer); `disks` is taken whole:

```php
'media' => [
    'bucket' => [
        'endpoint' => 'https://ams3.digitaloceanspaces.com',
        'region' => 'ams3',
        'bucket' => 'my-site',
        'url' => 'https://cdn.inlycore.com/my-client/my-site',
    ],
    'disks' => ['assets' => 'assets', 'files' => 'files'],
],
```

Leave out disks a Statamic addon hardcodes as local (Peak's `favicons` and
`social_images`). For R2, set `endpoint` to the account's R2 endpoint and
`acl` to `false`: R2 has no object ACLs, so the bucket itself is public.

### Moving a site's own bucket disk onto these

A site with a hand-rolled S3 disk keeps its bucket paths and URLs. With an
old disk of `root: web` and `url: https://cdn.example.com/site/storage/`:

```php
'media' => [
    'bucket' => [
        'endpoint' => 'https://ams3.digitaloceanspaces.com',
        'region' => 'ams3',
        'bucket' => 'site',
        'url' => 'https://cdn.example.com/site/storage',
    ],
    'disks' => ['assets' => 'web'],   // the folder the files already sit in
],
```

The disk's key is `root/<folder>`, as before, and its URLs are
`url/root/<folder>/<path>`. Then point the container at the disk (`disk:
assets` in `content/assets/<container>.yaml`), delete the old disk from
`config/filesystems.php` and its variables from `.env.example`, and set
`CORE_MEDIA_KEY`/`CORE_MEDIA_SECRET` on every server before the deploy that
switches (a server without them falls back to the local folder). A server
that runs a non-production `APP_ENV` while it serves the live site sets
`CORE_MEDIA_LAYER=false` until launch. Developers delete the old variables
from their `.env` and run `content:pull --only=assets`.

### On Laravel Cloud

With no `CORE_MEDIA_KEY`, the disks use the bucket Laravel Cloud attaches to
the environment: the entry in Cloud's `LARAVEL_CLOUD_DISK_CONFIG` whose disk
is `media.bucket.cloud_disk` (`CORE_PUBLIC_DISK` when set, else `media`, the
public bucket Core Cloud attaches to a website), else the first that is not a
scoped disk, gives the key, secret, bucket, endpoint and region. Core Cloud
attaches a private bucket beside it for form uploads, so the name keeps the
asset disks off that one whatever order Cloud lists them in. A key in config still wins. Cloud's
buckets are R2, so `acl` is `false` for them whatever config says. The files'
URL is the committed `media.bucket.url`, else `CORE_CDN_BASE_URL`, which Core
Cloud's CDN integration sets to the CDN in front of the public bucket (used
only when the bucket was found by name, so it is that bucket), else the
bucket's own URL, which serves files untransformed. The first two are CDN
URLs, so the components transform the files. A committed CDN URL must have this Cloud
bucket as its origin, or new uploads land in one bucket while their URLs
point at another.

Cloud attaches a bucket per environment, so a Cloud bucket gets no layer: a
Cloud environment before go-live (on `APP_ENV=staging` for noindex) writes
its bucket directly, as editors building the content need. A Cloud staging
environment that shares production's bucket sets `CORE_MEDIA_LAYER=true`.

- Name the Cloud bucket's disk anything but a disk in `media.disks`
  (`assets`, `files`): Cloud registers its disk before core-web, so a clash
  leaves Cloud's plain disk in place of core-web's, and `media:check` reports
  it as the app's own.
- The first deploy copies the git-tracked images into the bucket with
  `php artisan media:sync push --force`, on the Cloud credentials like any
  other key.

### Commands

- **`media:sync push|pull [--disk=] [--dry-run] [--overwrite]`** copies each
  disk's files, `.meta` folders included, between its committed local folder
  and the bucket, and never deletes. `push` is the move to a bucket: it adds
  the files the bucket lacks and leaves the rest, since a file of a different
  size there is usually an editor's later change (`--overwrite` replaces
  those). It asks first; `--force` skips the question. `pull` refreshes the
  committed folder from the bucket. Neither touches a layer. Dry run first; a
  second dry run after a push finds nothing.
- **`media:check [path] [--disk=assets]`** says what the disk is here and, with
  a key, checks a file (the first image when no path is given): its URL is
  under a CDN origin, the raw file answers with `Cache-Control` and CORS, and a
  CDN rendition answers with an image.

### Another CDN

`media.cdn.scheme` names the `Inly\CoreWeb\Media\UrlScheme` that builds the
transform URLs: `serves()` (which URLs it transforms), `image()`, `video()`
and `frame()`. `CoreCdnScheme` speaks core-cdn's v1 query params and clamps
every value to the ranges the CDN accepts.

## Website content

The site half of Core's Statamic connector API v1.1 (inly-core
`docs/learn/website-content/statamic-connector-api.md`): Core reads a site's
pages and writes drafts to it, every call sealed with Core's own Ed25519 key,
and the site pings Core when a page changes. core-web opens the envelopes and sends
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

The API has the site answer over https outside a local environment.
core-web does not check the scheme of a request it opens: the site's
endpoints rely on the web server (or the proxy in front of it) to serve them
over https only.

The middleware checks, in the API's order:

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

core-web's own suite runs the API's test vectors
(`tests/fixtures/statamic-connector-api-v1-test-vectors.json`): those that
use Core's key as given, and the shared-secret ones re-sealed with the test
key, so their method, path, query and body refusals run against Core's key too.

## Drafts

`Inly\CoreWeb\Drafts\DraftWriter` writes drafts of the site's entries the
way an editor's CP save writes them, for whatever source makes them: Core AI,
a content push. It needs revisions on, and refuses a draft when they are off
rather than change a live entry.

- A new entry is saved unpublished; a published one gets a working copy and
  its live entry is left alone. A structured collection's new entry goes
  under its parent (`parent_id`) in the tree.
- Each draft is saved as its author's CP user, who must be allowed to make
  the change, with the author's provenance on the revision (`via` plus the
  source's own details) and its message. An editor's later CP save keeps the
  provenance.
- A draft never touches a working copy someone else holds, and the author's
  own must restate what it already changed. An update names the hash of the
  entry it was written against (`base_hash`), so it never undoes a later edit.
- Values are validated against the blueprint, values their field conditions
  hide are left out (`FieldConditions`, a port of the CP's), and `dry_run`
  checks without saving.
- `publish()` makes the draft live; `read()` returns it with who holds it.

```php
$author = new DraftAuthor($cpUser, $name, DraftProvenance::make('content-push', ['by' => $email]), 'Pushed from local');

app(DraftWriter::class)->write($author, [
    'collection' => 'articles',
    'entry_id' => $id,            // omit for a new entry
    'fields' => ['title' => '…'],
    'base_hash' => $hash,         // the DraftBase hash read before the change
]);
```

The site names the collections sources may draft in `drafts.collections`
(see the config file). Two bindings shape a source's input:
`DraftValueNormaliser` turns the fields sent into stored values
(`StoredValues` keeps them as they are), and `DraftBase` is the hash an
update names (`FieldsHash` hashes the draft's fields). Refusals throw
`DraftRefused`, which renders as JSON (`error`, `message`, details); the
messages are translations a site rewords in
`lang/vendor/core-web/{locale}/drafts.php`.

### Proposals

Content Statamic keeps no revisions of (a navigation tree, a collection's
page tree, a global's values, a term, an asset's meta) is changed by
proposal: a source stages the target's whole new value, and an editor
applies or discards it under **Content → Proposals** in the CP, which shows
what changes. The entry appears only while an editor has proposals to decide.

```php
$target = ProposalTargets::resolve('nav:main:default'); // tree:{collection}:{site}, global:{handle}:{site}, term:{taxonomy}:{slug}:{site}, asset:{container}::{path}

app(Proposals::class)->propose($author, $target, $newTree, Proposals::base($target));
```

- The proposer and the editor must both be allowed to edit the target.
- A proposal names the fingerprint of the value it was made from; it is
  refused when the target changed since, and never applied over a later
  change.
- One open proposal per target: its author's next one replaces it, anyone
  else's is refused until it is decided.
- Values are validated as the CP validates a save; a page tree proposal
  moves pages and never drops one.

Proposals are kept as JSON on a filesystem disk (`drafts.proposals`); a host
whose disk does not outlive a deploy names a bucket.

## Content checks

Checks find what is wrong in the site's content that a machine can find,
and where the fix is mechanical, fix it as a draft or a proposal an editor
publishes. They run nightly (`checks.schedule`) or with
`php artisan content:check [check…] [--json]`; each check's latest findings
are kept (`checks.disk`, `checks.path`) for the `content_checks` dashboard
widget and for sources (Core AI) to read without running a check.

| Check | Finds | Fix |
|---|---|---|
| `own-url-links` | Links to the site's own pages typed as URLs, in entries and navigation trees: they break when a slug changes | A Bard link (keeping its query and anchor) or a `link` field becomes an entry reference, in a draft; a menu item picks the entry, in a proposal. Other fields are named for an editor |
| `dead-targets` | Entry and asset references to things that no longer exist, and menu items whose entry is gone | None: which page or file takes their place is an editor's call |
| `seo-gaps` | Published pages without a meta description, or with a title or description longer than a result shows (fields and lengths in `checks.seo`, Peak SEO's by default) | None generic; a source can draft the text |

```php
$checks = app(ContentChecks::class);
$checks->latest('own-url-links');                 // the last run's findings
$checks->fix('own-url-links', $findingId, $author); // a draft or a proposal
```

A finding's `id` is stable across runs. A fix finds the finding afresh
first, and fixes everything fixable in the same entry or tree at once. A site
adds its own checks (subclasses of `Inly\CoreWeb\Checks\Check`) to
`checks.checks`, or leaves one out.

### Core reads checks and proposes (API v1.2)

With `features.content` on as well, core-web registers the API's v1.2
endpoints itself, under `content.prefix` (`api/core/v1`), each sealed with
Core's key:

| Route | Purpose | With |
|---|---|---|
| `GET checks` | `website.read` | `checks` |
| `GET checks/{check}/findings?site=&subject=` | `website.read` | `checks` |
| `POST checks/{check}/fix` | `website.draft` | `checks` |
| `GET proposal-target?key=` | `website.read` | `drafts` |
| `POST proposals` (`dry_run` to validate only) | `website.draft` | `drafts` |
| `GET proposals/{id}` | `website.draft` | `drafts` |

A fix or a proposal is made for a person in Core: the body names them in
`actor` (`core_user_id`, `name`, `email`), with an optional `summary`,
`core_request_id` and `core_approval_id`. It is written as their CP account
(the one [CMS sign-in](#cms-sign-in) stamped with their Core user id; a site that keeps
accounts another way binds its own `Inly\CoreWeb\Content\CoreUsers`), marked
`via: core-ai`, and refused with `no_cms_user` when they have none. Nothing
goes live: a fix is a draft, a proposal waits for an editor.

## Control panel link

`features.cp_link` adds a link to Core right under Dashboard in the control
panel, for every CP user, opened in a new tab so unsaved CP work stays open.
It points at `client.base_url` (`CORE_URL`); without one there is no link.

The site names the link and may pick its icon, in its own
`config/core-web.php`; the icon is a Statamic icon name or an SVG, and null
keeps Core's mark:

```php
return [
    'features' => ['cp_link' => true],
    'cp_link' => ['label' => 'Acme Core', 'icon' => null],
];
```

## CMS sign-in

`features.cms_sign_in` makes Core the only way into the control panel. The
CP has no passwords: a person signs in to Core, Core hands them back with an
envelope sealed with its own key, and the site asks Core again before letting
them in, so Core's answer, not the envelope, decides what they get. Every CP
account is created by the hand-off, stamped with `core_user_id`, and holds no
password.

```php
return [
    'features' => ['cms_sign_in' => true],
    'cms_sign_in' => [
        // Core CMS grants => Statamic role handles. Admins become super users.
        'roles' => ['cms_editor' => 'editor'],
    ],
];
```

It needs the site's Core (`core.url` / `core.client_id`, or `CORE_URL` / `CORE_CLIENT_ID`) and `CORE_CLIENT_SECRET`: the site's
app client is the audience of every envelope and the token it calls Core back
with. No secret is shared with Core.

**Moving a site onto it.** A site that already signs its CP in from Core
with its own code removes that code in the same change that switches the
feature on, since both claim the same addresses:

- its hand-off route (usually `/admin-sso`) and controller, and its
  offboarding route (`/api/core/cms-users/revoke`);
- its CP login redirect middleware, its SSO user provider and the
  `auth.providers.*.driver` it set for it (put Statamic's `statamic` back; the
  feature swaps it);
- its reconcile command and that command's schedule entry;
- its own rate limiter and tests for these;
- any shared hand-off secret (`*_HANDOFF_SECRET`): only Core's own seal is
  accepted.

Switch the driver back to `statamic` before turning the feature on: with the
CP guard on any other driver the boot fails, `php artisan` included, so a
cached config holding the old driver has to be cleared by deleting
`bootstrap/cache/config.php`.

Map the roles its code granted in `cms_sign_in.roles`, and if its accounts
are stamped with something other than `core_user_id`, copy that value into
`core_user_id` first: an account without it can no longer sign in.

| Step | Request | Checks |
|---|---|---|
| Sign-in | `/admin/auth/login`, or any CP page a guest opens, redirects to `{core}/sso/cms?site=<key>&next=<path>`; Core hands back `GET /admin-sso?payload=…&signature=…&key_id=…&next=…` | Core's signature, `aud` = `CORE_CLIENT_ID`, expiry, purpose `cms_sso`, a `site` claim naming this site, the nonce spent here |
| Confirm | `GET {core}/api/app/cms-sso/client/verify?site=<key>&payload=…&signature=…&key_id=…` on the client's token | Core re-reads the person's access and answers `{user_id, name, email, is_admin, cms_roles, purpose}`. Only a 403 `{"error": "not_admitted"}` strips the person; any other failure is a 503 that changes nothing |
| Offboarding | Core posts `POST /api/core/cms-users/revoke`, sealed to the request (the `X-Core-*` headers, purpose `cms_revoke`), JSON body `{site, user_id, email}` | The account Core provisioned for that person is deleted, by a queued `ForgetCmsUserJob` |
| Reconcile | `core:reconcile-cms-users` (nightly, `cms_sign_in.reconcile_schedule`) posts `{site, emails}` to `{core}/api/app/cms-users/client/reconcile` on the client's token | Deletes accounts whose person is gone from Core, strips the grant of those without CMS access, takes super from those no longer admins. Every verdict must be complete before anything changes; `--dry-run` reports only |

What the feature holds the CP to:

- Password login, password reset, account activation, registration and
  passkey login are refused, in the CP and on the front end
  (`RedirectCpLoginToCore`, a global middleware).
- An expired CP session never asks for a password: Statamic's CP auth is
  switched off per request, so the session timer sends the browser to the
  login page, and from there through Core back to the page it was on.
- An account without `core_user_id` cannot sign in, and the hand-off never
  adopts one, whatever its address (`SsoUserProvider`, `CmsUsers::canBind`).
  The feature switches the CP guard's provider to the `core-web-sso` driver
  when it is Statamic's own (`statamic`); a site on another driver wires
  `SsoUserProvider` in itself.
- Roles assigned by hand outside `cms_sign_in.roles` are left alone; the
  mapped ones and the super flag are rewritten at every sign-in.
- Keep `users/` out of git, and out of `statamic.git.paths` where Statamic's
  git integration commits CP saves: accounts live on the server that created
  them, and every sign-in saves the account.

**Several sites, one Core.** `cms_sign_in.site` (`CORE_CMS_SITE`) is this
site's key in Core: the login redirect names it, the hand-off must carry it,
and the confirm and reconcile calls pass it. Every site needs an app client
of its own, since the client keeps a hand-off on the right host and the key
keeps it on the site it was minted for.

**Local development.** A local machine signs in through a local Core, set
as `CORE_URL` with a client of its own, exactly as a live site does: the
local Core must know the site under its `cms_sign_in.site` key
(`CORE_CMS_SITE`, `production` when unset) and serve https (`CORE_CA_BUNDLE`
for a self-signed certificate). Without one, a checkout with `content_sync`
on signs in through the live site instead: after `php artisan content:login`,
the CP login asks the live site who the machine's token belongs to (its sync
API's `me`) and signs into a local account mirroring that one's super flag,
roles and groups, stamped `core_web_live_user_id`. It asks on every sign-in,
so a revoked machine or a changed grant lands at the next one, and the live
site must run a core-web that answers `me`. A local account Core
provisioned (`core_user_id`) is never taken over, and the mirrored account
stops resolving once the machine's login is gone (`content:logout`). Offline,
`cms_sign_in.local_password_sign_in` set to `true` restores Statamic's own
login, and only while `APP_ENV` is `local`; with none of these, the CP login
answers 503, naming the ways in when `APP_DEBUG` is on. Data from production comes
through `content:login` and `content:pull` / `core:pull`, not through a
connection to the production Core.

Core serves the other half: `/sso/cms`, the confirm and reconcile routes and
the offboarding notices. Its paths are `cms_sign_in.core.*`.

## Content pull

`features.content_sync` lets a developer's checkout pull the live site's
content into its own `content/` directory, signed in as their own CMS user:
no shared secret, no env var, and no Core needed. The same login lets
`core:pull` read the live site's Core snapshots (see
[Pulling production data](#pulling-production-data)), so one sign-in
covers both. Switch it on in the site's
`config/core-web.php` (the live site serves the content, the checkout pulls
it, and both run the same config) and commit the live site's URL there: it
is the same for every developer, so it is config, not an env var. A
`content_sync` block that names only `remote_url` keeps every other
setting's default:

```php
return [
    'features' => ['content_sync' => true],
    'content_sync' => [
        'remote_url' => 'https://www.example.com',
    ],
];
```

Set the URL the site answers on, not one that redirects there (`example.com`
to `www.example.com`, `http` to `https`): the checkout follows no redirect,
since one would carry its token to another address, and names the URL to
set instead.

```bash
php artisan content:pull      # every type; signs this machine in first when needed
php artisan content:pull --only=entries:pages,globals --site=default
php artisan content:pull --only=assets   # only the asset index (assets:<container> for one)
php artisan content:pull --dry-run   # what would change; writes nothing
php artisan content:pull --force     # overwrite (and delete) files changed here too
php artisan core:pull         # the Core snapshots, on the same login
php artisan content:login     # optional: sign in ahead of time, or as another user
php artisan content:logout    # revoke this machine's token
```

**Signing in.** There is no separate step: a `content:pull` or `core:pull`
run in a terminal signs the machine in when it has no login, or when the
site refused the one it had, and then carries on. A site that refuses even
the login just made (one behind more than one server with the `file` token
store) stops the run rather than signing in again. `content:login` does the
same on its own, to sign in ahead of time or as another CMS user, and
`content:logout` signs out; a run without a terminal never starts a sign-in
and says to run `content:login`. Signing in prints a link to the live
site's CP and a code, and opens the browser where it can. The developer
signs in to the CP the way the site allows (Core single sign-on or a native
login), checks the code, the machine name (as the machine reports it), the
address the request came from and how long ago it started, and approves;
the terminal then gets a personal token that acts as them, so the pull
reads exactly what they may view (and, for `core:pull`, every Core snapshot
the site keeps, whole). Approving a machine, and using its token, needs the
**Pull content** permission (under Core Web in the role editor) besides CP
access; a super user always may. Signing in
again revokes the machine's previous token. The token is kept in
`storage/core-web/remote-site.json` (readable by the developer only, in a
folder that ignores itself in git) and lives 30 days from its last use
(`content_sync.token_days`). On the live site only its sha256 is kept
(`content_sync.tokens`), by default one file per token in
`storage/app/private/core-web/sync-tokens`. Keep that store private: a disk
named there is a private one, never the site's public bucket.

A live site behind more than one server, or on a host whose disk does not
outlive a deploy (Laravel Cloud), keeps its tokens in the database instead:
a local file is seen by one server only, until the next deploy, so a token
issued there is refused on the others and the checkout loses its login.
Set the driver in the site's `config/core-web.php` (committed, not env),
then publish and run the migration:

```php
'content_sync' => [
    'remote_url' => 'https://www.example.com',
    'tokens' => ['driver' => 'database'], // `connection` and `table` optional
],
```

```bash
php artisan vendor:publish --tag=core-web-sync-tokens-migration
php artisan migrate
```

Its own tag publishes this migration alone, so a site that published the
snapshots migration before does not get a second copy of it. It creates
`core_web_sync_tokens` on the default connection (or
`tokens.connection`). Without the table every sync call fails with an error
naming the migration; it never falls back to files. The **Tools → Content
sync** page then says to publish and run the migration, or, when the
database itself cannot be reached, to check the tokens connection; the full
error goes to the log.

**Pulling.** The live site exports entries (published or not, every site),
terms, global variables, collection trees and nav trees, each as the exact
file the Stache writes, at its path under `content/`. Blueprints, fieldsets,
config, users and form submissions are not pulled, and assets come as an
index only (below). `content.lock`
(committed, beside `content/`) holds the hash of every file as last pulled:

- a file unchanged here since the last pull, or missing here, is written,
  in the key order this checkout's file already has;
- a file that differs only in key order or in `updated_at`/`updated_by` (as
  a site on the Eloquent driver exports it) is left as it is, and an entry
  of a structured collection is never given a `parent`, which its tree holds;
- a file changed here is left alone and listed, unless `--force`, which
  still leaves a file whose only difference is key order or save stamps;
- a file the site no longer has is deleted only when it is unchanged here
  since the last pull, and only in a collection, taxonomy, global set or
  nav (and site) the developer may read in full on the site, as each export
  page reports; otherwise it is listed and kept (a changed one goes with
  `--force`, one outside what they may read never does).

**A first pull** on a site whose content is already committed has no
`content.lock` yet, so it cannot tell a local edit from the site's newer
version. A file exactly as the checkout's last commit has it (in a git
checkout) counts as unchanged and is updated; any other file that differs
is skipped and listed. When the checkout holds no edits worth keeping, run
the first pull with `--force` to take the site's version of every file.

Key order is ignored at every depth, since a site storing content in a
database JSON column (Postgres `jsonb`, MySQL `JSON`) reorders the keys of
nested maps on its own. The known limit: reordering the keys of a keyed map
on the live site (an array fieldtype in keyed mode, say) is not seen as a
change, so it is not pulled. Reordering a list (replicator sets, a tree) is.

Then the Stache is refreshed. A path the site sends that is not one of its
type's files under `content/` stops the pull before anything is written,
nothing is written outside `content/`, and a symlinked file or folder is
never written or deleted through. The command runs only where `APP_ENV` is
`local`.

**Assets.** With `features.media` on a checkout without the bucket's key,
the pull also brings the live site's asset index: for every asset container
the developer may view there, each file's path, its public URL and its
stored meta (alt, focus, size, dimensions: the `.meta` file, or the
database row on Statamic's Eloquent driver), never the file itself. It is written to `storage/core-web/remote-assets/<disk>/` (ignored
by git), never to the repo or a bucket, and replaced whole on every pull:
it is disposable, so there is no lock and nothing to keep. The asset disks
then list the live site's files over the local layer (see
[Media → The disks](#the-disks)), so the CP shows and renders them and
`<x-core-img>` points at their public URLs, while uploads stay on the
laptop until `content:push` carries them (see [Content push](#content-push)). A container the site does not have, or the developer may not view,
has its index cleared; any other refusal (a proxy's 403, a 500 on a later
page) fails the pull and keeps the index as it was. A live site on a
core-web from before the asset export refuses the `assets` type: the pull
says so, keeps the index and pulls content as usual. A path in the index
with a way out of the container, or into a `.meta` folder, stops the pull
before anything is written; a file whose URL is not http(s) on the live
site's host or its container's is left out (and counted), so the index can
never have this machine fetch an address the site chose. Oversized `.meta`
is cut to the keys Statamic writes, the index of a container no longer on
the disk is removed, and `media:check` reports an index that does not read.
An assets-only pull does not rebuild the Stache: it clears Statamic's
listing and path caches for each container itself.
`content_sync.pull_assets: false` turns this off; the live site only ever
reads (a file without stored meta there is sent without it, not
measured).

**Revoking.** **Tools → Content sync** in the live site's CP lists the
machines signed in as the user (a super user sees everyone's), with when
each was last used and when it expires, and revokes any of them. A token
also stops working when its user loses CP access or the Pull content
permission, or is deleted, and `content:logout` revokes its own (the stored
one, even after `remote_url` changed).

**Guards.** The sync endpoints answer over HTTPS only (an app running as
`local` or `testing` excepted). Behind a proxy or load balancer that ends
TLS, trust it in the site's `TrustProxies` middleware so Laravel sees the
request as secure; otherwise every sync call is refused. They are
throttled per token and per address (`content_sync.middleware`, the
`core-web-sync` limiter), and starting a login has a limiter of its own;
the CLI reports a 429 with when to retry. A site running as
`APP_ENV=local` refuses them, so no one pulls from a developer's checkout
by mistake (`content_sync.allow_local_remote` lets one serve, to try the
flow between two checkouts), and its CP has no sync pages: no Tools →
Content sync entry and no approval page. The approval page cannot be framed by another
site. Pending logins wait in the cache (`content_sync.store`, null for the
default store), which every server behind the site must share: on more than
one server that is a `database` or `redis` store, never `file`, or an
approval made on one server is lost to the machine polling another.

### Building on the login

`Inly\CoreWeb\ContentSync\RemoteSite` is the checkout's client:
`RemoteSite::fromStorage()` is this machine's login to the configured site
(null without one), `url()` and `token()` what it signs in with, and
`get($endpoint, $query)` / `post($endpoint, $data)` call a sync endpoint by
name (`export`), or any path from the site's root when it starts with `/`,
with the token as bearer and no redirects followed. The sync endpoints' own
401 forgets the login and throws `RemoteLoginNeeded`; a site that cannot be
reached, redirects, rate limits or answers another 401 (basic auth in front
of a staging host) throws `RemoteSiteError` and keeps the login; a rate limit
is its `RemoteSiteBusy` subclass, with the seconds the site asked to wait
(`retryAfter`, null when it named none). On the
live site, a route behind the `core-web.sync` middleware runs as the
token's CMS user (`User::current()`).

A command that calls the site uses the `SignsInToRemoteSite` trait (beside
`RemoteSite`) rather than reading the login itself: `signedInSite()` returns
the login, starting `content:login` first in a terminal, and
`signInAgain()` does the same after a `RemoteLoginNeeded`; each returns null,
with the reason already said, when no login can be had. `content:pull` and
`core:pull` both sign in through it.

## Content push

`content:push` sends what a developer changed in `content/` back to the live
site, never live: an entry becomes a draft an editor publishes in the CP
(a working copy of a published entry, or a new unpublished entry), and a
global's values or a navigation tree becomes a proposal an editor applies
(see [Drafts](#drafts) and [Proposals](#proposals)). Nothing is published,
deleted or moved in a tree, and a push makes one proposal per global set or
nav and site. It uses the same login as [Content pull](#content-pull) and
is on wherever `content_sync` is (`content_sync.push: false` on the live
site refuses it).

```bash
php artisan content:push            # every changed entry, global and nav tree
php artisan content:push --only=entries:pages,globals --site=default
php artisan content:push --dry-run  # what the site would do; writes nothing
php artisan content:push --partial  # push the files that pass, list the rest
php artisan content:push --force    # skip the "changed on the site since the pull" check
```

**What is sent.** Each file under `content/collections`, `content/globals`
or `content/navigation` whose hash differs from `content.lock` and from
what this checkout last pushed. Collection trees (the page order) and terms
are not pushed: they stay CP edits. An entry is pushed only into a
collection the drafts feature covers (`drafts.collections`), and a proposal
needs `features.drafts`. A new entry keeps the id the checkout gave it and,
in a structured collection, is placed under its local parent. At most
`content_sync.push_items` (200) files go in one push; narrow a larger one
with `--only` or `--site`.

**Never undoing the site.** A pull records, per file, the site's hash of
the version it wrote (`bases` in `content.lock`). A push names it, and the
site refuses a file whose live version has changed since (`base_changed`),
so a push never reverts an editor's edit. An entry with an open working
copy this checkout did not push (even the developer's own, made in the CP)
is refused too (`working_copy`). `--force` skips both checks but never the
drafts' own guard: a working copy someone else holds is still refused.
Files pulled before this check existed have no base; pull again, or push
with `--force`, which says which files it cannot check. The whole push is
checked first and written only when every file passes; with `--partial`
the passing ones are written and the refused ones listed. Two files that
are the same entry refuse each other (`duplicate`). A refusal the check
could not foresee, the site changing between the check and the write,
still leaves the files before it written. Each refused file prints its
reason, and the command exits non-zero. One push carries at most
`content_sync.push_bytes` (10 MB) of content in all.

**The lock.** A push does not change what the lock says was pulled: the
draft is not live, so the next pull still sees the site's version. The
pushed hashes go under `pushed`, so an unchanged file is not sent twice,
and each entry's draft as the site hashed it after the push goes under
`drafts`, so pushing the same entry again goes over the checkout's own
draft rather than being taken for a change made on the site.

**Assets.** A file in the local media layer (see [Media → The disks](#the-disks))
that a pushed file names by its path is uploaded to the same path on the
site first, with its alt text and focal point. It never overwrites: a path
the site already has with the same bytes is skipped, and one with other
bytes refuses the file that names it. Nothing is deleted. Uploading needs
the container's upload permission (and folder creation for a new folder),
the path's extension must be the file's own and one the CP takes, the
container's file rules apply as in the CP, and a file may be at most
`content_sync.push_asset_bytes` (20 MB). An uploaded asset is on the site
(and public, on a public container) as soon as it is stored, even when the
files naming it are then refused.

**Permissions.** Pushing needs the **Push content** permission (under Core
Web) besides Pull content; a super user always may. Drafts and proposals
are written as the token's CMS user, as the drafts feature writes any
draft, with provenance `content-push` (who, which machine, the git commit)
and the message "Pushed by <name> from <machine>". A push never sets or clears an entry's
`author`. The command runs only
where `APP_ENV` is `local`.

## Laravel Cloud

Laravel Cloud resets the disk on every deploy, so what editors write cannot
live in `content/`. With `features.cloud` on, a site on Cloud (Cloud sets
`LARAVEL_CLOUD=1`) gets this config without an env var for any of it:

| Config | On Cloud | Env var that overrides it |
|---|---|---|
| Entries, collection and nav trees, global variables, terms, asset meta, form submissions, revisions, tokens | `eloquent` driver | `STATAMIC_CONTENT_DRIVER` |
| CP users (`statamic.users.repository`) | `eloquent` | `STATAMIC_USERS_REPOSITORY` |
| `cache.default`, `session.driver` | `database` | `CACHE_STORE`, `SESSION_DRIVER` |
| Maintenance mode | the `cache` driver on the `database` store | `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE` |
| The snapshots store (`snapshots.store_driver`) | `database` | `CORE_SNAPSHOT_STORE` |
| Content sync tokens (`content_sync.tokens.driver`) | `database` | `CORE_SYNC_TOKENS_DRIVER` |
| Static caching | `half` | `STATAMIC_STATIC_CACHING_STRATEGY` |
| Stache watcher, Statamic's git integration | off | `STATAMIC_STACHE_WATCHER`, `STATAMIC_GIT_ENABLED` |
| Revisions, Statamic Pro | on (each collection still sets `revisions: true`) | `STATAMIC_REVISIONS_ENABLED`, `STATAMIC_PRO_ENABLED` |
| Statamic's every-minute scheduled-entries job | off, `core-web:scheduled-entries` every 15 minutes instead | `STATAMIC_HANDLE_SCHEDULED_ENTRIES` |

An env var the environment sets keeps its own value, including one Cloud
itself injects when a resource is attached (a cache cluster sets
`CACHE_STORE`, for instance). Off Cloud nothing changes, so local machines, CI and Forge keep `content/` as the source.
Blueprints, collections, navigations, taxonomies, global sets, forms and
sites stay files from git on Cloud too.

The site does its half once:

- `composer require statamic/eloquent-driver`, publish its config
  (`--tag=statamic-eloquent-config`) with every `driver` left on `file`
  (the feature switches the content parts) and `UuidEntryModel` for
  entries. For the tables, publish core-web's copies of the driver's
  migrations (`--tag=core-web-cloud-migrations`) instead of the driver's own.
  The stock ones make entry ids uuid, which a Statamic id such as `home`
  fails on in Postgres, and content columns jsonb, which re-sorts keys.
  `content:import` checks the columns first and names any it cannot fill.
  Run them on a new database: these are create migrations, so a database
  made with the driver's copies is dropped and made again.
- Give the `users` table uuid ids and the User model `HasUuids`, an empty
  `$guarded` and casts for `super`, `preferences` and `last_login`, which
  Statamic's eloquent users write.
- Give the `users` table a column for every key the site stores on a user
  (an SSO site's `core_user_id`, and `core_web_live_user_id` where a local
  checkout signs in through the live site, for instance): eloquent users write each
  data key as a column.
- Publish core-web's migrations (`--tag=core-web-migrations`: the
  snapshots store and the content sync tokens) whenever anything uses them:
  the snapshots feature, spent nonces when `content.store` is `snapshots`,
  and `features.content_sync`.
- Asset files: the disk does not outlive a deploy either, so uploads need a
  bucket. Attach one in Cloud and turn on `features.media`, whose disks read
  it from `LARAVEL_CLOUD_DISK_CONFIG` (see [On Laravel Cloud](#on-laravel-cloud)).
- With `features.cms_sign_in`: run `php please auth:migration` (the
  `role_user` and `group_user` tables eloquent users write roles and groups
  to), keep the CP provider on the `statamic` driver with its `model` set,
  and turn on Cloud's scheduler for `core:reconcile-cms-users`.
- Search: a `local` index lives on one container's disk, so on Cloud it
  needs a remote search driver; `core-web:deploy` warns about any local
  index.

With the maintenance flag on the database store, every request reads one
cache row to know whether the site is down.

### Scheduled entries and sleeping

Cloud's scheduler wakes a hibernating site for each task that is due, so a
task due every minute keeps it awake for good. Statamic schedules one: the
job that tells the static cache and search that an entry dated in the past
minute is now published. On Cloud the feature turns it off and schedules
`core-web:scheduled-entries` on `cloud.scheduled_entries.schedule` (every 15
minutes, the wake-up interval these sites use). It covers every entry in a dated
collection dated since its last run, at most `lookback_minutes` (a day)
back, so nothing is skipped between runs. A scheduled entry is published
from its date on either way; only its static-cache copy and search entry
wait for the next run. Match the schedule to the site's wake-up interval,
and turn on Cloud's scheduler. `core-web:deploy` warns on Cloud about any
task scheduled more often than every 15 minutes.

The feature also adds an `outpost` file cache store when the site has none:
Statamic's licensing ping keeps its marker there, and Cloud's build runs
artisan before any migration made the cache table.

### First deploy

On a new, empty database, load `content/` once from Cloud's commands tab:

```bash
php artisan content:import
```

It refuses while the database holds entries or misses one of the driver's
tables. `--fresh` (with `--force`, or a yes to its question) empties
entries, trees, globals, terms and asset meta first, editors' work
included, so it is for a first deploy or a rebuild only; form submissions,
revisions and tokens exist only in the database and are kept. Run it whole:
an asset without its meta row renders no image. It does not move CP users:
on an SSO site Core creates each account on its first sign-in, and
otherwise `statamic:eloquent:import-users` does.

The order is the point. The driver's own `import-collections` saves the
trees before the entries exist, and saving a tree drops every id the
entries table doesn't hold yet, so the trees come out empty. So entries go
first, then trees, then the rest, with URIs and order recomputed last. Each
import also runs in its own process: in one process the entry import leaves
the still-empty database trees cached, and the tree import would save those
instead of the files.

### Deploy commands

Cloud's deploy commands are one line:

```bash
php artisan core-web:deploy
```

It runs `migrate --force`, `statamic:stache:refresh`, `statamic:static:clear`
and `statamic:search:update --all`, then `core:warm` with
`features.snapshots` (a failed warm-up does not fail the deploy: the site
serves the snapshots it has), then the site's own commands in
`cloud.deploy`, and stops at the first required step that fails. On Cloud it
refuses to run while content would still be on the disk. Build commands
stay the host's: `composer install`, the front-end build and
`php artisan optimize`.

## Checking a site's setup

`php artisan core-web:doctor` (registered once any feature is on) checks
what the switched-on features need from the environment it runs in, offline:

| Check | Fails when |
|---|---|
| Core client | The Core URL, client id (`core.*` or `CORE_URL` / `CORE_CLIENT_ID`) or `CORE_CLIENT_SECRET` is blank while a feature that calls Core is on; or a local checkout's `CORE_URL` names a remote Core (anything but localhost, a private address, `host.docker.internal`, `*.test`, `*.localhost`, `*.local`, `*.ddev.site`, `*.lndo.site`). On a local machine without `CORE_URL`, a warning: snapshots and content come from `core:pull` / `content:pull` |
| Queue | `QUEUE_CONNECTION` is `sync` in production with `snapshots` or `cms_sign_in` on; a warning on other servers, skipped locally |
| Schedule | Never: it lists the commands the features schedule and warns when one's schedule was set to `null` |
| Pings | Never: it warns while `WEBSITE_SYNC_SECRET` is still set |
| CMS accounts | Statamic's git integration commits the users folder while `cms_sign_in` is on |

It exits non-zero on a failure, so a deploy or a launch check can run it.
What it cannot see (a worker or the scheduler actually running, the deploy
script) stays a launch check. `media:check` covers the media disks.

## Agent instructions

`resources/agents/AGENTS.md` holds the rules an agent working on a site
needs for each feature, versioned with the code. A site imports it from its
own `CLAUDE.md`:

```markdown
@vendor/inly/core-web/resources/agents/AGENTS.md
```

A change to how a site should use a feature updates that file in the same
pull request.

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

## Site rules

`rules/` holds PHPUnit suites about how a site uses core-web. Inly's site
gate ([site-ci](https://github.com/InlyTechnology/site-ci)) copies every
`rules/*.php` into the site's `tests/Central` beside its central suites and
runs them, so a rule always matches the core-web version the site runs. A
suite extends the site's `Tests\TestCase` and skips what a switched-off
feature does not use. To run them on a site by hand:
`cp vendor/inly/core-web/rules/*.php tests/Central/ && php artisan test tests/Central`.

| Rule | Fails when |
|---|---|
| media disks are core-web's own | `features.media` is on and the site still defines a disk in `media.disks` itself (see [The disks](#the-disks)) |

A new rule can turn a site's deploy red, so run it against the sites on the
gate before releasing it.

## Developing

```bash
composer install
composer test     # PHPUnit
composer format   # Pint
```

CI runs the suite and `pint --test` on every push and pull request.

## Releasing

Releases are git tags (`v0.3.0`); Packagist picks each one up. A pull
request adds its notes to `CHANGELOG.md` under `## Unreleased` (one section,
at the top). To release, run the **Release** workflow from the Actions tab
(it works in the GitHub mobile app) with `patch`, `minor`, `major` or an
exact version. It requires the Tests workflow to have passed on `main`,
renames `## Unreleased` to the version, commits that to `main`, tags that
commit and publishes the GitHub release with the section as its notes. It
refuses an empty or missing `## Unreleased`, an existing or lower version,
and, before 1.0, a patch release that carries upgrade notes.
If the publish step fails after the push, publish the existing tag by hand:
`gh release create vX.Y.Z --verify-tag --title vX.Y.Z --notes-file <notes>`.

## License

MIT. See [LICENSE](LICENSE).
