# Changelog

## Unreleased

- **Drafts**: `features.drafts` (off by default) adds `DraftWriter`, which
  writes drafts of a site's entries as an editor's CP save does, for any
  source (Core AI, a content push): unpublished entries and working copies
  saved as the author's CP user with their provenance and message, the
  holder guard, the restate rule, a base hash check, field conditions, dry
  runs, publishing and a signed Live Preview link. The site names its
  draftable collections in `drafts.collections`. See README → Drafts.
- **Proposals**: with `features.drafts`, changes to content without
  revisions (navigation trees, page trees, globals, terms, asset meta) are
  staged as proposals an editor applies or discards under Content →
  Proposals in the CP. See README → Proposals.
- **Content checks**: `features.checks` (off by default) adds
  `content:check`, run nightly, and a `content_checks` dashboard widget,
  with three checks: `own-url-links` (fixed as drafts and proposals),
  `dead-targets` and `seo-gaps`. See README → Content checks.

## v0.9.0

- **Chat**: messages and handoffs also have a limit per address,
  `chat.turn_rate_limit_per_address` (30 a minute), so a script that opens a
  fresh session per message no longer slips past the per-session limit.
- **Chat**: a result card's `url` or `image` starting `//` (another host) is
  dropped, like a link in Core's rich text.
- **Core client**: the client secret is sent to Core over https only
  (plain http in `local` and `testing`, as for Core's signing keys), and the
  token request no longer follows redirects. A token answer that is not a
  2xx with an `access_token` throws, instead of caching an empty token.
- **Live reads**: `features.live` (off by default) lets a site read Core
  while the visitor waits, for what no snapshot holds: a calculation on the
  visitor's input, a figure that must be current, a short list fetched and
  cached on the server. A snapshot stays the answer for data that makes
  pages of its own. See README → Live reads.
  - The site names each read in `live.reads` with a `LiveRead` class: the
    input rules, the one call to Core (`LiveRequest::get()` / `post()`, on the
    site's client token), what of Core's answer the page gets, a `ttl()`,
    `keep()` for an answer not to keep (a fallback), and `staleFor()` to serve
    the last good answer while Core fails.
  - The browser reads a `public()` read at `GET /!/core-web/live/{name}?…`
    (reads are server-only by default); server code
    (a Livewire component, a controller) calls `CoreLive::read($name, $input)`
    and gets a `LiveResult`.
  - Answers are `{data}`, or `{error}` with `invalid` (422, with `errors` per
    field, the site's rules or Core's), `not_found` (404) and `unavailable`
    (503). Nothing reaches Core but the validated input, a path with a dot
    segment is refused, and Core's 422 messages pass on only for the fields
    in `rules()`.
  - After Core itself fails a read (502, 503, 504, unreachable, no token),
    it answers at once (stale or `unavailable`) for `live.retry_after`
    seconds (30) instead of every visitor waiting out the timeouts; a 500,
    which one visitor's input can cause, switches nothing off.
  - A path with a raw `?`, `#`, backslash, space or control character is
    refused: `rawurlencode()` the input a path carries.

## v0.8.0

- **Pings sealed with Core's key**: the ping endpoint opens a ping Core seals
  with its own Ed25519 key (`X-Core-*` headers, purpose `website.objects`,
  audience `client.client_id`) and reads its data from the JSON body the
  envelope binds. Such a ping needs no `ping.secret` and opens once. A ping
  without those headers is the shared-secret `{ payload, signature }` as
  before, so a site can take both while its Core moves over; blank
  `ping.secret` once Core seals every ping to refuse shared-secret ones.
  - Core must post the exact bytes it sealed (`withBody($json)`, or a Saloon
    request that seals its final body), or the body hash is refused.
  - A sealed ping opens whatever `features.content` says, needs
    `client.client_id`, and spends its nonce in `content.store`.
  - A refused sealed ping answers JSON (`envelope_refused` with its `reason`
    once the signature verified, `no-store`), and a body that is no object a
    422 `invalid_ping`.
  - `FakesCore::receiveSealedPing()` sends one sealed with the test key, and
    `sealedServer()` gives a sealed call's server variables and body to send
    again.

## v0.7.0

- **Website content**: `features.content` (off by default) is the site half of
  Core's website content protocol v1.1 (inly-core v31.1.0 or later).
  - The `core-web.envelope:<purpose>` route middleware opens an envelope Core
    sealed with its own Ed25519 key, steps 1 to 11, and checks the
    environment of a draft or publish. It refuses with a JSON 403 and marks
    every answer `no-store`. The alias is registered whatever the switches,
    so a site's routes can always name it; while `features.content` is off
    it refuses every call.
  - `CoreEnvelope::open()` opens an envelope bound to no request, and refuses
    one sealed to a request (a `method`, `path`, `query` or `body_sha256`
    claim) with that reason.
  - `CoreSigningKeys` reads Core's key document over https from the paired
    Core, following no redirect. A copy is refreshed at four minutes, by one
    call while the others open with it, and opens nothing past five, so a
    retired key stops opening envelopes even while Core cannot be read. A
    failed refresh, or a fetch with no copy, is retried after 10 seconds; an
    unknown key id refetches at most once a minute.
  - `content.environment` reads `CORE_CONTENT_ENVIRONMENT`, for a site whose
    `APP_ENV` differs from its Core's; empty is the app's own environment.
  - `ContentPings` tells Core a page changed through
    `POST {core}/webhooks/connectors/{content.connector_id}`.
  - `FakesCore` seals content calls with a test key it publishes as Core's
    (`callSealed()`, `coreEnvelopeHeaders()`, `CoreEnvelopeSealer`).
  - The protocol's test vectors run in the suite: the Core-key ones as
    given, the shared-secret ones re-sealed with the test key.

  See README § Website content.
- `CoreClient::sendResponse()` takes a closure for `$data`. The closure is
  called for each attempt, so the retry after a 401 sends a fresh body.
- core-web requires `ext-sodium`.
- `CoreClient::sendResponse()` widens `$data` to `array|Closure`, so a subclass
  that overrides it with `array $data` must widen it too.

## v0.6.0 (2026-10-15)

- **Chat**: `features.chat` (off by default) relays a site's visitors to Core's
  public chat agents through Core's stock chat route
  (`/api/app/chat/{agent}`, inly-core v29.0.0 or later). `CoreChat` is the transport (the client token
  as the bearer on every call, the conversation's access token in
  `X-Core-Conversation-Token` on the calls after opening it, the visitor in
  `X-Core-Client-Id`), `ChatTranscript` normalizes a transcript (escaped
  Markdown as `html`, structured replies unwrapped, result cards narrowed),
  and the relay routes under `/!/core-web/chat/{agent}` keep each
  conversation's token in the session, by agent and the key the agent's
  `ChatContext` class names. `resources/js/core-chat.js` is a headless Alpine
  data factory and `<x-core-web::chat>` its wrapper. `CoreClient::sendResponse()`
  (and `postResponse()`) sends a GET or POST with the client token, retried
  once on a 401, and hands back Core's answer. See README § Chat.
  Messages are capped at `chat.max_message_length` (10000, Core's default per
  agent).
  Messages and handoffs have a limit of their own (`chat.turn_rate_limit`,
  10 a minute per session and address), and a session holds at most
  `chat.max_conversations` (5) per agent. A conversation Core reports as
  used up answers `exhausted` and is forgotten; a rate limit answers
  `limited` with Core's `Retry-After`. Agent keys must be Core's lowercase
  slugs.

## v0.5.0 (2026-10-14)

- **`core:pull`**: fills the local snapshots from another Core, production as
  a rule, through a read-only client of its own (`pull.base_url` and
  `pull.client_id` committed as defaults in the site's config, the secret in
  `CORE_PULL_CLIENT_SECRET`), so a local site renders
  the live site's data without a local Core. The copies are pinned: no
  `core:warm`, ping or render replaces them until the next pull or
  `core:pull --release`, which forgets them. Runs only where `APP_ENV` is
  `local`. Part of
  `features.snapshots`. `SnapshotStore` gains `pinning()`, `pinned()`,
  `pinnedNow()` and `release()`. See README § Pulling production data.

## v0.4.0 (2026-10-13)

- **Dormant until switched on**: installing the package no longer registers
  anything. Each feature has a switch under `features` in the config, off by
  default, and a feature that is off adds no route, schedule, middleware,
  command, tag or cache store. `features.snapshots` switches on what the
  package did before: the `snapshots` store, `core:warm` and its schedule, the
  degraded guard, the ping route and the snapshot sitemaps. The Core client
  needs no switch. `CoreWebServiceProvider::enabled($feature)` answers whether
  a feature is on. See README § Features.

### Upgrade notes

- **A site that shows Core snapshots breaks until it switches them on**:
  every page that reads a snapshot throws "Core snapshots are switched off",
  and pings, `core:warm`, its schedule and the snapshot sitemaps are gone.
  Find it: the site registers snapshots (`grep -rn "SnapshotRegistry" app/`)
  or its robots view uses `{{ core_sitemaps }}`. Migrate: add
  `config/core-web.php` holding
  `return ['features' => ['snapshots' => true]];`, or, when the site
  publishes the whole file, add that `features` key to it; then let the
  deploy rebuild its config and route caches (`optimize`). Verify:
  `php artisan route:list --name=api.core.objects` lists the ping route and
  `php artisan schedule:list` lists `core:warm`.

## v0.3.0 (2026-10-12)

- **Own repository**: the package moves to its own public repository and is
  published on Packagist under the MIT license. No code change.
- **Compact row meta**: `CoreClient::objects()` and `object()` ask Core for
  `meta=compact` (Core 9.0+), which keeps each row's attributes,
  relationships, identity meta and `meta.formatted` and leaves out the
  per-row `meta.schema` and `meta.object`, most of every snapshot answer. A
  query that names its own `meta` (`'meta' => 'full'` for a field's per-row
  `data`, such as a money field's currency) keeps it. A Core before 9.0
  ignores the parameter and answers full rows. `get()` is unchanged.
  `FakesCore::answerRowMeta()` answers full or compact meta as Core does
  (`$fakeCoreCompactsMeta = false` for a Core before 9.0), and
  `requestedRowMeta()` reads it off a request.
- **No-op writes**: a snapshot's documents are stored once per partition, in
  `last_good`; `fresh` becomes a marker that says they are current. A full pull
  that changed nothing renews the marker only (the documents are written again
  once half through their stale lifetime), and a ping for one object that
  reads as stored, or that the snapshot keeps nothing of, writes nothing
  (`refreshOne()` returns `0`). A copy written by an earlier version is still
  read, and replaced on the next write. A patched document keeps its position
  in the stored copy. A marker whose copy is gone is read as a cold cache, and
  the next pull stores the copy again.
- **Change sets**: change listeners get a third argument, a `SnapshotChange`
  with the documents added, changed or removed since they were last told
  (`ids()`, `before()`, `after()`), or null when that is not known. A
  listener taking two arguments keeps working.
- **Targeted static cache flush**: `Snapshot::dependents()` names the pages a
  document appears on, as paths on the partition's site, and
  `SiteCache::flushOnChange()` then forgets only the pages a change touches
  (as each document was and is), plus the snapshot's sitemap. It falls back to
  the whole site when the change is not known, a document's pages are not
  named, naming them fails, or more than
  `snapshots.targeted_flush.max_documents` (20) documents changed;
  `snapshots.targeted_flush.enabled` switches it off. `SiteCache::flushPaths()`
  forgets one site's cached pages by path.
- **Testing**: `FakesCore::answerSparseFieldsets()` narrows the fake's Objects
  API answers to the sparse fieldsets each request names, as Core does, and
  `requestedFieldsets()` reads them off a request, so a site can test that its
  pulls ask for every field their documents read.
- **Nightly warm**: `snapshots.warm_schedule` defaults to `15 3 * * *` (was every
  ten minutes): with Core's pings and no-op writes the scheduled pull is the
  safety net under a lost ping. A site that relied on the ten-minute pull
  should give each snapshot a fresh TTL longer than a day (README § Schedule)
  or set its own schedule. The fresh marker never outlives the stale copy.

- `composer test` and `composer format` run on the PHP that invokes Composer.
- **Sites under a path**: sites may share a host, each under its own path
  (`https://example.com/second`). A snapshot sitemap answers under the site's
  path too (`/second/sitemap-core-{type}.xml`, route `{name}.site`), and only
  at a site's own path; `SiteCache::flush()` forgets only the pages under the
  flushed site's path on a shared host, not every page of the host.

## v0.2.0 (2026-09-29)

- **Env names**: the defaults read `CORE_URL`, `CORE_CLIENT_ID`,
  `CORE_CLIENT_SECRET` and `CORE_CA_BUNDLE` (were `CORE_WEB_*`), plus
  `WEBSITE_SYNC_SECRET` as before, so a site no longer publishes the config
  just to map its own env names. Rename the env on upgrade; a site that
  published `config/core-web.php` only for env names can delete it.
- **Database snapshot store**: `snapshots.store_driver` (`CORE_SNAPSHOT_STORE`)
  = `file` (default, unchanged) or `database`, a database store on its own
  `core_snapshots_cache` / `core_snapshots_cache_locks` tables for hosts whose
  disk does not outlive a deploy (Laravel Cloud). The migration publishes with
  `--tag=core-web-migrations`. An unknown driver is refused at boot.

## v0.1.0 (2026-09-29)

First release, extracted from a client site without behaviour change.

- Snapshots: `Snapshot`, `SnapshotRead`, `SnapshotStore`, `SnapshotRegistry`,
  `RefreshSnapshotJob`, `core:warm`, `UncacheableWhileDegraded`.
- `SnapshotRegistry::idle()` for ping types the site takes without acting on
  (replaces a constant in the site's ping controller).
- The `snapshots` file cache store is added unless the app defines one.
- `core:warm` scheduled from `core-web.snapshots.warm_schedule` (every ten
  minutes by default).
- `CoreClient`.
- `PingController` and `PingSignature` on `POST api/core/objects` (route
  `api.core.objects`), outside the `web` group; an empty secret rejects every
  ping. A bulk ping with id `0` is read as `all`.
- Snapshot sitemaps at `/sitemap-core-{type}.xml` (route `core.sitemap`) and the
  `{{ core_sitemaps }}` tag.
- `SiteCache`, `SiteScopedInvalidator`, `CoreHtml`.
- `Testing\FakesCore`.
