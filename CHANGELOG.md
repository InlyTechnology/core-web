# Changelog

A release that a site breaks on, or that silently changes how a site behaves,
carries an `### Upgrade notes` section: one entry per required action, each
saying what breaks, how to find the affected code, how to migrate and how to
verify. Read the notes of every version between the one a site had and the
one it moves to. Before 1.0 such a release bumps the minor version.

## Unreleased

- **A save forgets the entry's copies on the other sites**: under
  `SiteScopedInvalidator`, a saved or deleted entry's origin and other
  localizations are forgotten on their own sites too. See
  `docs/static-cache.md` (A save under the `all` rule).
- **`SiteCache::forgetMany()`** forgets pages on several sites in one call,
  and `SiteCache::forget()`, `flushPaths()`, `flush()` and `refresh()`
  resolve each site's address once per call instead of once per cached page.
  See `docs/static-cache.md` (Pages a snapshot change touches).
- **Draft proposals stay private on Laravel Cloud**: with `features.cloud`,
  proposals are kept in the private bucket Cloud attaches
  (`cloud.uploads_disk`) when `drafts.proposals.disk` names none. On the
  default disk they sat in the public bucket, where a staged change and
  `applied.json` could be read by their path. `core-web:doctor` fails while
  proposals on Cloud would sit on the default disk, a local disk or the
  public bucket, and on any host while `drafts.proposals.disk` names a disk
  that is not defined, or is scoped to one that is not. See `docs/laravel-cloud.md` and
  `docs/checking-a-sites-setup.md`.
- **The CP nav counts proposals without opening them**: the Proposals count
  reads which targets have an open proposal from the cache, which a save
  clears, where every CP page read each open proposal's file (one request
  each on a bucket). A change a save cannot clear (proposal files changed by
  hand, another server's own `file` cache) shows within five minutes. See
  `docs/drafts.md`.
- **The 404 page reads its entry once**: the page-not-found entry and its
  fields are looked up once per request, where each view the composer names
  (`errors/404`, `layout`) looked them up again.
- **A cached config skips the defaults merges**: `config:cache` already holds
  the group, media, SEO and sign-in defaults core-web merges at boot, so a
  request on a cached config no longer merges them again, and the package
  config is read once where each merge required it.
- **The preview frame shows its hover outline and hint only where a pointer
  hovers**: on a phone or a tablet, a tap on a marked element left them drawn
  over the page. See `docs/object-preview.md#marking-fields-and-blocks`.
- **Pages render again after a save in production**: core-web turns
  Statamic's `background_recache` on in production where the warm queue
  connection holds jobs for a worker, unless the site names it. See
  `docs/static-cache.md` (Rendering pages again after a save).
- **The `all` rule keeps a save to its site by default**: core-web uses
  `SiteScopedInvalidator` under that rule, on one site or several, unless the
  site names an invalidation class. See `docs/static-cache.md` (A save under
  the `all` rule).
- **A navigation, asset or form save renders again the pages on every site's
  host**, and forgets those on any other host: with `background_recache`
  under `SiteScopedInvalidator`, where Statamic's rule rendered again only
  the base URL's pages and a site on another host kept its old copies. Same
  section.
- **`SiteCache` puts a site with a relative URL on `APP_URL`'s host**, as
  Statamic's cacher does, where it took the host a request named, and
  matches a site's host whatever its case. See `docs/static-cache.md`
  (Pages a snapshot change touches).
- **`core-web:doctor` checks the static cache**. See
  `docs/checking-a-sites-setup.md`.
- **On Laravel Cloud, a save's page renders are queued on `low`**: with
  `features.cloud`, `statamic.static_caching.warm_queue` is `low` unless
  `STATAMIC_STATIC_WARM_QUEUE` is set or the site's config gives a queue. See
  `docs/laravel-cloud.md`.

### Upgrade notes

- **Open proposals on Laravel Cloud move to the private bucket.** A Cloud
  site with `features.drafts` and no `drafts.proposals.disk` now reads
  proposals from the private bucket, so proposals staged before the update
  are no longer listed. Find them: on the default disk (the public bucket),
  under `core-web/proposals/` (or `drafts.proposals.path`). Migrate: copy
  that folder to the same path on the private bucket, then delete it from
  the public one, which still serves it until then. A Cloud site that names
  a disk on the public bucket in `drafts.proposals.disk` (such as `media`)
  removes the setting, so the private bucket is used (other hosts then use
  the default disk), and moves the folder the same way. Verify, on Cloud:
  the CP's Proposals page lists them (the nav's count follows within five
  minutes), `php artisan core-web:doctor` shows `ok Proposals`, and the old
  path is gone from the public bucket.
- **`core-web:doctor` can now fail a deploy.** It exits non-zero on
  a Cloud site with `drafts` on whose proposals would sit on the default
  disk, a local disk or the public bucket, and on any site whose
  `drafts.proposals.disk` names a disk that is not defined, or is scoped
  to one that is not. Find it: a
  deploy or launch script that runs `core-web:doctor`. Migrate: on Cloud,
  attach the private bucket (Core Cloud attaches it as `private`) and leave
  `drafts.proposals.disk` unset, as in the note above; elsewhere, define the
  disk the doctor names or remove the setting. Verify: the doctor shows
  `ok Proposals` on Cloud and no `fail Proposals` anywhere, and exits 0.
- **Background recache is on in production by default.** Where the warm queue
  connection holds jobs for a worker, a save now renders its pages again in
  the background instead of forgetting them, and visitors see the old copy
  until a worker has run the render. A production site its worker cannot reach
  as an anonymous request to the page's own address (basic auth, an IP
  allow-list, DNS that sends the worker elsewhere) keeps its old copies. A
  `false` in `config/statamic/static_caching.php` no longer turns it off. Find
  what a site set itself: `grep -rn "background_recache" config/` and
  `STATAMIC_BACKGROUND_RECACHE` in each environment. Migrate: delete a
  `background_recache` line in `config/statamic/static_caching.php` that only
  turns it on or works it out from the environment; to keep pages forgotten
  after a save, set `STATAMIC_BACKGROUND_RECACHE=false` on that server, or
  `static_cache.background_recache` to `false` in `config/core-web.php` for
  every environment. Verify: on a production server, `php artisan
  core-web:doctor` names the connection and queue the renders go on. After
  deploy, production needs a worker on that queue: save an entry, then check
  that `php artisan queue:monitor <connection>:<queue>` drains to 0, that a
  page the save renders again (under the `all` rule, one listing the entry:
  `SiteScopedInvalidator` forgets its own page at once) shows the change,
  and that `php artisan queue:failed` lists no `StaticWarmJob`.
- **On Laravel Cloud, a save's page renders are queued on `low`.** With
  `features.cloud`, `statamic.static_caching.warm_queue` is `low` unless
  `STATAMIC_STATIC_WARM_QUEUE` is set or the site's config gives a queue, and
  a render on a queue no worker takes never runs. Find it: each Cloud
  environment with a queue worker. Migrate: before deploying, add a `low`
  queue to its worker, or set `STATAMIC_STATIC_WARM_QUEUE` to a queue it
  takes (`default`). Verify: after deploy, `php artisan config:show
  statamic.static_caching.warm_queue` there names a queue the worker takes.
- **A cached page served at another address than its site's keeps its old copy
  after a change kept to the sites it reaches, or a snapshot change.** A
  site's address is its `url:` in `resources/sites.yaml`, on `APP_URL` when
  that `url:` is relative (`/`, `/en/`) or reads it (`{{ config:app:url }}`).
  Statamic's `all` rule, with `background_recache` off as it was by default,
  cleared every address on any save. `SiteScopedInvalidator`, now the default
  under that rule, on one site too, keeps an entry, global, collection tree or
  deleted navigation tree change to the address of each site it reaches, on
  that address's host under http and https, wherever it does not clear the
  whole cache (`docs/static-cache.md`, A save under the `all` rule). A
  snapshot change forgets a relative site's pages on `APP_URL`'s host inside a
  request too, where it took the host the request named. Find it: on each
  server, `php artisan config:show app.url` and each site's `url:` beside the
  addresses visitors use (`APP_URL` left at `http://localhost` from
  `.env.example` or on a platform domain, a `www` alias that is not
  redirected). Migrate: on each server, set `APP_URL` to the address visitors
  use (a site whose `url:` is absolute takes its address from it instead), and
  redirect every other address to the site's address. Verify: every other
  address redirects to the site's address. Load a page there, edit an existing
  entry and, on a site with snapshots, change a record in Core that one of
  them shows. Once the queue has drained, a page each change reaches, cached
  before it, shows it at that address.
- **On a multisite install, the `all` rule keeps a save to its own site by
  default.** core-web names `SiteScopedInvalidator` as the invalidation class
  where the site names none, so a page showing another site's content (a
  listing of its entries, its globals) keeps its old copy when that content
  is saved. Find such pages:
  `grep -rnE "(site|locale)=|->in\(" resources/views app`, and a class the
  site names already:
  `grep -n "invalidation" config/statamic/static_caching.php`. Migrate: a
  site whose pages show another site's content names
  `\Statamic\StaticCaching\DefaultInvalidator::class` as
  `invalidation.class`, and turns `background_recache` off unless the
  static cache's base URL is the scheme and host every site's visitors use,
  with no path (`docs/static-cache.md`, A save under the `all` rule). Verify:
  `php artisan config:show statamic.static_caching.invalidation` prints the
  class, and `php artisan core-web:doctor` names it.

## v0.47.0

- **Core previews the site's own pages**: with `features.preview` and
  `features.content`, Core's preview frame shows one of the site's pages as
  it is in the CMS, its working copy included, where the page itself refuses
  to be framed. Core asks for a signed link at
  `GET /api/core/v1/page-preview/{id}` (Statamic connector API v1.7) and
  frames `/core-preview/page/{id}`, rendered like the object preview. These
  two routes need no `features.snapshots`. See `docs/object-preview.md`.
- `ObjectPreviewController::coreOrigin()` is deprecated: call
  `PreviewFrame::coreOrigin()` instead. It still works.

## v0.46.0

- **A save refreshes its own site only**: with `background_recache` on,
  `SiteScopedInvalidator` forgets the saved entry's page on its site at once
  (under http and https) and renders the rest of that site's cached pages
  again in the background (`SiteCache::refresh()`). Statamic's `all` rule
  renders again every page cached on the static cache's base URL, which is
  every site's pages when the sites share that host (hundreds of renders for
  one save on an install with many sites), while pages on other hosts kept
  their stale copies. Without `background_recache` the saved site's pages
  are flushed. See `docs/static-cache.md`.
- **A global reaches the sites that inherit it**: a global saved on one site
  now also flushes (or refreshes) the pages of every site whose copy of the
  set reads its values through an origin, directly or through another copy.
  They kept showing the old values.
- **A site's flush forgets each page once**: `SiteCache::flush()` and
  `flushPaths()` forget a page once with every query string it was cached
  under, where they forgot it once per cached query string, each time
  reading the cacher's whole URL list again.

### Upgrade notes

- **`/*` no longer takes a site's home page.** `SiteCache::flushPaths()` with
  `/*` forgot the site's root page with the pages under it; it now forgets
  only the pages under it, as every prefix does (`/brands/*` never takes
  `/brands`). Find it: search the site's snapshot `dependents()` closures
  and `flushPaths()` calls for `'/*'`. Migrate: return `'/'` beside `'/*'`
  where the home page shows the data too. Verify: change one such document
  and see the home page rendered with the new value.

## v0.45.0

- **Local search indexes work on Laravel Cloud**: with `features.cloud`,
  Statamic builds a `local` search index on the `sync` queue, so the
  container that searches builds its own copy in that request. Queued on a
  worker, the index was written to the worker's disk, which no web replica
  reads: every search queued another build (one job per 100 documents) and
  then failed on the missing index. On Laravel 13 a save updates the index
  of the container it is made on, not the worker's copy, and
  `core-web:deploy` on Cloud rebuilds only the indexes that outlive the
  container. A connection the site sets (`STATAMIC_SEARCH_QUEUE_CONNECTION`,
  or `search.queue_connection` in its config) is kept. See
  `docs/laravel-cloud.md`.

## v0.44.0

- **Search a Core object for a listing**: the `search` feature runs
  `Search::for('vehicles')->search(request()->query(), facets: ['make'])` over
  a search named in `search.objects`. The query keeps only what the object's
  `/schema` offers, and the answer comes from its snapshot while the snapshot
  holds every row (`search.local_limit`, 5,000) and the query keeps to the
  rules Core's search contract pins, or from Core's Objects API, kept briefly
  and served stale while Core fails. Synonyms, accent folding, values in use
  and facet counts are Core's, declared on the object. `Snapshot::fromObject()`
  pulls an object's rows as the search reads them. Needs Core v63.0.0 or
  later, for facets on the Objects API and `sort_custom` in `/schema`. See
  `docs/search.md`.

## v0.43.0

- **Static caching is on wherever the app is not local or under tests**:
  core-web sets the `half` strategy on every server, Forge as well as
  Laravel Cloud, when the site's config and env name none. A strategy the
  site sets, or `STATAMIC_STATIC_CACHING_STRATEGY` (`null` included), still
  wins. `core-web:deploy` skips `statamic:static:clear` when caching is off,
  which failed the deploy. See `docs/static-cache.md#the-strategy`.
- **Pagination links drop ad and analytics parameters**: while pages are
  cached, a listing's `withQueryString()` links (Statamic's paginated tags call it) no longer
  carry `utm_*`, `gclid` and the rest of `static_cache.tracking_query_strings`.
  The page is cached without them since v0.38, so the first visitor's
  campaign reached every later visitor's next page.

### Upgrade notes

- **A server without a strategy now caches pages.** Off Laravel Cloud, a
  site with no `STATAMIC_STATIC_CACHING_STRATEGY` in its env and `null` in
  `config/statamic/static_caching.php` was uncached and now runs `half` in
  every environment but `local` and `testing` (staging included). Find it:
  before updating, `php artisan config:show statamic.static_caching.strategy`
  on the server prints `null`; then search the views for `$errors`,
  `session(` and `old(` outside `@nocache`. Migrate: render anything per
  visitor (forms with errors or a success message, session reads, `old()`)
  inside `@nocache`; to keep a server uncached, set
  `STATAMIC_STATIC_CACHING_STRATEGY=null` there. Delete the skeleton's
  `STATAMIC_STATIC_CACHING_STRATEGY=null` line from `.env.example` (and a
  server's `.env` copied from it), or that server stays uncached. Verify:
  the CP's Utilities → Cache shows the `half` strategy and pages cached
  after a few visits. On Cloud, a strategy the site's
  `config/statamic/static_caching.php` sets now wins where `half` used to
  override it (a `full` there is not served on Cloud, so set it back to
  `null`), and `APP_ENV=local` or `testing` now leaves caching off.

## v0.42.0

- **Control panel thumbnails come from the CDN**: with `media` on, a file
  on a media disk the CDN serves gets its CP thumbnail (asset browser, edit
  screen, assets fields, listing columns, the `missing_alt_text` widget) from
  the CDN instead of Glide, an MP4 under 100 MB a CDN frame. Glide decoded
  each original inside the app, so a few large photos could take a small
  Laravel Cloud instance past its memory. See `docs/media.md`.

## v0.41.0

- **A large push no longer outlasts the web server**: `content:push` sends
  entries the site already has in batches (`content_sync.push_batch_files`,
  `content_sync.push_batch_assets`), records each as it lands and still
  checks all of them before writing any. See `docs/content-push.md`.
- **A push can be sent again after a lost answer**: a draft that is the
  pusher's own and already holds what the file says (a working copy, or an
  unpublished entry) is no longer refused as `working_copy` or
  `base_changed`, so a timeout during the write needs no `--force`.
- **Previews ask no consent and load no trackers**: Core's preview frame,
  the control panel's Live Preview and a draft's preview link render without
  the cookie banner, the settings link, Tag Manager or the `seo` feature's
  Google tags, Consent Mode stays denied and a gated embed shows a note in
  its place. `Preview::mark()` does the same for a preview route of the
  site's own and keeps its response, an error page included, out of every
  cache. `Preview::active()` answers whether a response is a preview. A site
  picks up the script's part on its next asset build. See
  `docs/cookie-consent.md`.

## v0.40.0

- **Live reads run Core functions**: `LiveRequest::function('vehicles/calculate-financing', [...])`
  runs a Core function on the site's token. The answer is kept for the
  `max-age` the function sends unless the read names a `ttl()`, Core's
  per-input errors reach the page as with any read, and a 503 or 429 turns
  the read off for its `Retry-After`. `LiveRead::ttl()` now returns `?int`,
  null by default; a read that overrides it is unchanged. See
  `docs/live-reads.md`.
- **A Core link in a link field is picked like an entry**: the Campaign or
  Brand option of a Statamic `link` field shows one inline item with a status
  dot and a menu, chosen from a searchable list, instead of a multi-select
  with a counter and a chip. Stored values are unchanged. See
  `docs/buttons.md`.
- **A push carries the images a block requires**: an image a required asset
  field names (a hero slide's photo, say) and the push uploads no longer
  refuses the file as missing its image. The push checks every file before
  it uploads anything, and for an entry the check now counts the files it is about to
  upload as there. See `docs/content-push.md`.

## v0.39.0

- **Object preview** (Statamic connector API v1.5 and v1.6): with
  `features.preview` on, a snapshot whose pages show Core records names its
  object with `->coreObject()`. Core then reads which records the site
  serves a page for (`object-pages`) and frames a record's page beside its
  edit form with the person's unsaved values (`/core-preview/object`), read
  from Core with `X-Core-Preview` and rendered through the site's own route,
  with nothing of it kept in the static cache, its nocache regions or cache
  tags. `@coreField('title', true)` and `@coreBlock($loop->index)` mark what
  a click in that frame picks, and print nothing on any other page. Docs:
  [Object preview](docs/object-preview.md).

## v0.38.0

- **Skip link**: `<x-core-web::skip-link />` (with `features.seo`) renders
  the link a keyboard user meets first, jumping past the header to the
  page's content (`#main-content`, else the page's `<main>`), worded
  through `__()` (*Hoppa till innehållet* in Swedish) and styled by `resources/css/core-skip-link.css`. It replaces
  Peak Tools' skip links on a site moving off Peak. Docs: [SEO](docs/seo.md#the-skip-link).
- **Content feed**: with `content.feed.enabled` on, core-web serves the Statamic
  connector API's `content` endpoints. That covers every page of the drafted
  collections (or of `content.feed.collections`), with its text, blocks, SEO,
  working copy and stored fields. Each page lists, under `shows`, the Core
  objects it picks through `core_snapshot_pages` or a Dictionary named in
  `content.feed.dictionaries`. Saving or deleting a page pings Core. A site
  with a feed of its own leaves it off. Docs: [Content feed](docs/content-feed.md).
- **A changed catalog warns instead of refusing** (Statamic connector API
  v1.4): a draft naming an older `catalog_hash` is written, checked against
  the blueprints as they are now, and its answer carries a
  `catalog_changed` entry under `warnings`. A Core that retried on the old
  refusal keeps working; it simply never sees one. Docs: [Drafts](docs/drafts.md#content-schema).
- **Recommendations for drafts**: `draft_recommend` on a field lists rules
  the AI aims for without being held to them, read as the field's
  `recommended` in the content schema. Docs: [Drafts](docs/drafts.md#content-schema).
- **Publishing someone else's draft**: `DraftWriter::publish()`, and so
  `POST drafts/{id}/publish`, publishes a draft whoever holds it, for an
  author with the CMS `publish` permission on the entry, as the CP does. It
  used to refuse with `held` unless the author held the draft, so a reviewer
  in Core could not publish an editor's draft. The revision records who
  published, with the publisher's message and provenance. Writing a draft
  still never touches one someone else holds.
  Docs: [Drafts](docs/drafts.md).

- **Faster drafts**: the catalog fingerprint a draft is checked against is
  kept between requests instead of rebuilt from every blueprint on each
  draft. A save of a blueprint, fieldset, collection, taxonomy or term, and a
  stache clear on deploy, drops it; a catalog read refreshes it.
- **Compressed static cache on a Redis cache cluster**: on Laravel Cloud
  with `CACHE_STORE=redis` and phpredis, statically cached pages now go
  through a compressing copy of the cache's Redis connection (zstd, else
  lz4, else lzf), under a prefix per build as on the database store. A page
  is hundreds of KB of HTML, so a small Valkey instance held only a few
  hundred; compressed it holds several times more. The rest of the cache is
  not compressed (counters `INCRBY`), and locks stay on the plain
  connection. Clearing the static cache now runs `FLUSHDB` on the cache's
  database, as it emptied the cache table on the database store;
  `CORE_STATIC_CACHE_REDIS_DB` moves the pages to a database of their own
  where the instance has more than one. Docs: [Laravel Cloud](docs/laravel-cloud.md).
- **Ad and analytics parameters stay out of the static cache's key**: the
  parameters ad platforms add to a landing page (`utm_*`, `gclid`, `fbclid`,
  HubSpot's `hsa_*` and others, in `static_cache.tracking_query_strings`) are
  added to Statamic's `disallowed_query_strings`, beside the site's own. A
  click from a campaign used to cache a full page of its own that nothing
  read again; it now gets the page cached under the plain address. Not
  applied on the `full` strategy. Docs: [Static cache](docs/static-cache.md#ad-and-analytics-parameters).

### Upgrade notes

- **A site that compresses its own Redis static cache** (a `static_cache`
  store in `config/cache.php` on a Redis connection): that store is kept
  and core-web leaves it alone. To use core-web's instead, remove the
  store and its connection, deploy, and check `redis-cli --scan --pattern
  '*static_*responses*'` lists the new build's pages.
- **Anything that reads these parameters on the server must render inside
  `@nocache`.** A page is now cached under its address without them, as
  rendered for the visitor who brought them, so a value read outside
  `@nocache` reaches every later visitor. That was always the rule for a
  Core form (its `query` page source), and is now what breaks when it is
  missed. Find them with `grep -rn "utm_\|gclid\|fbclid\|query()" app
  resources/views`, and check each one renders in a `@nocache` partial or
  reads the address in the browser. Verify on a cached page: open it with
  `?utm_source=a`, then with `?utm_source=b`, and check the second shows
  `b`.
- **A site that keeps its own list** in `disallowed_query_strings` can leave
  it: the lists are merged. Remove entries now in core-web's when you like.
  `'static_cache' => ['tracking_query_strings' => []]` in the site's
  `config/core-web.php` turns core-web's off.

## v0.37.0

- **Link fields pick Core's pages**: each `buttons.core_links` entry is now
  also an option of Statamic's `link` fieldtype, beside Entry and URL, so any
  link field can pick a campaign, brand or other page built from Core. The
  link follows Core when the page's address changes, and a pick whose page
  is gone links nowhere. Saved links are untouched. Docs: [Buttons](docs/buttons.md#links-to-cores-pages).

## v0.36.0

- **Clearer machine sign-in page**: the page that approves `content:login`
  lists the machine's name, where and when it asked, and its code as
  separate rows, says what the machine will be able to do as a short list
  (pushing only for a person who may push, on a site that takes pushes), and
  puts Deny and Approve next to the warning. Its text no longer runs together:
  a CP component marked `v-pre` is never rendered, so text from elsewhere
  sits in plain elements now, here and on the tokens page and alt-text
  widget.
- **Proposals read as tables and trees**: the CP page for a proposal shows
  what changes in the shape it has. Plain fields of a global, term or
  asset share one Field / Now / Proposed table. A grid or replicator is a
  table of its rows in their proposed order, removed rows where they
  stood, each marked added, changed, removed or moved, with columns named
  by their field labels and the old and new value of each changed cell. A
  navigation or page tree shows the proposed tree, with branches marked
  added, removed, renamed or moved. A row or branch counts as moved only
  when it changed places, not when others were added or removed around
  it, and it also shows struck through in red where it stood. The order
  line a proposal returns to Core appears only when rows changed places.

## v0.35.0

- **Readable proposals**: a proposal for a global, term or asset shows a
  grid or replicator row by row (added, changed, removed), names pages by
  title and URL instead of their entry ids, and shows Bard as text. A row
  that only moved shows as an order line, and a change the readable lines
  cannot show (a Bard link, a set's values) falls back to raw JSON. The
  `changed` lines a proposal returns to Core read the same way, keyed by
  field handle (`redirects › {row id}`).
- **`core_drafts` widget** (with `drafts`): the drafts Core AI or a
  content push wrote and the open proposals, newest first, each with a
  Review link, so editors see what is waiting. See `docs/features.md`.
- **`consent_stats` widget** (with `consent` and its log): how visitors
  answered the banner over the last `days`, the share accepting all,
  choosing some or rejecting all, and per category. See
  `docs/cookie-consent.md`, Dashboard widget.

## v0.34.0

- **Consent cookie page as a link**: the `core-web::consent` fieldset's
  Cookie page is a `link` field (`consent_policy_link`), so it can be an
  entry, a PDF from the `assets` container or an address. A page picked in
  `consent_policy_page` is still read. The settings dialog links it under
  the consent details. See `docs/cookie-consent.md`.
- **`buttons` feature**: the `core-web::button` and `core-web::buttons`
  fieldsets, and `Button::resolve()` (null when a button renders nothing),
  in place of the agency base's button fieldset and `resolveButton()`. Variants, link
  types (sms included), an inline variant, opt-in icons (a name, with
  `icons.reference` linked to look one up), entries across sites and
  links to Core snapshot pages (`buttons.core_links`) are config; the site's
  own `<x-ui.button>` and `<x-buttons>` draw them. See `docs/buttons.md`.
- **`missing_alt_text` widget** (with `media`): images without alt text,
  counted by a queued scan, in place of Peak Tools' `images_missing_alt`.
  See `docs/media.md`.
- `Snapshot::listingTitle()` names a document in pickers (the Core link
  dictionary); a snapshot overrides it for a better label.

- **Proof of what a visitor consented to**: each consent row names the
  fingerprint and locale of the banner it was made on, and each banner is
  kept once with every text, vendor and cookie. `php artisan consent:show
  <id>` and the control panel's **Consent log** utility show a visitor's
  choices with that banner, export them as JSON, and list the totals per
  banner. Adding or removing a vendor, category or cookie now asks every
  visitor again by itself. See `docs/cookie-consent.md`.
- **Consent signals for Microsoft Advertising and Clarity**: a site that
  declares `microsoft_uet` (new in the catalogue) or `microsoft_clarity`
  gets their consent APIs' denied defaults in the head, and every choice
  updates them beside Consent Mode. Nothing changes for a site declaring
  neither.
- **`seo` feature, in place of Peak SEO**: `<x-core-web::seo.head>` renders
  the title, description, robots, canonical, hreflang, Open Graph, JSON-LD
  and the Google tags (held by the `consent` feature until statistics is
  granted); `/robots.txt` blocks AI training crawlers and lists the
  sitemaps; `/sitemap.xml` per site and the `/sitemaps.xml` index; the
  redirects global answers front-end 404s; the page-not-found entry fills
  `errors/404`. The `core-web::seo_*` fieldsets keep Peak SEO's field
  handles, so content carries over; only production is indexed (one global
  toggle and one per entry). Off by default; Peak SEO must be removed
  before it is switched on. See `docs/seo.md`.
- **`favicons` feature, in place of Peak Tools' favicons**:
  `<x-core-web::favicons.head>`, `/site.webmanifest` and `/favicon.ico`,
  every size cut by the CDN (or Glide) from one PNG on the `favicons`
  global, with an optional dark PNG and SVGs for light and dark tabs. On
  Statamic Pro the control panel's tab icon becomes the site's icon with a
  dot, written when the global is saved. Off by default. See
  `docs/favicons.md`.

### Upgrade notes

- **A site switching on `buttons` renames its button variants.** The
  `core-web::button` fieldset names the variant `variant`, where the agency
  base's fieldset said `style`, so the CP shows saved buttons with the default
  variant until the content is renamed (the front end reads the old `style`
  meanwhile). Find: a site with `'buttons' => true` in `config/core-web.php`
  whose content has button rows with `style:`. Migrate: `php artisan
  buttons:migrate --dry-run`, then `php artisan buttons:migrate`, and commit
  the content. Verify: a second `buttons:migrate` reports `Changed 0 items.`
- **The consent log needs a second migration.** A site with `consent` on and
  its log enabled keeps logging every choice without it, but without the
  banner it was made on. Find:
  `config/core-web.php` has `'consent' => true` and `log.enabled` is not
  false. Migrate: `php artisan vendor:publish
  --tag=core-web-consent-snapshots-migration`, then deploy, which migrates.
  Verify: after a choice on the site, `php artisan consent:show` lists a
  banner fingerprint rather than "not recorded".
- **Visitors are asked again when vendors change.** A choice now carries the
  scope it was given on (the categories, vendors and cookie names), and a
  change to it asks again, as raising `policy_version` did. Choices made
  before this release carry no scope and stand until they expire or the
  version is raised. Find: nothing to change. Verify: add a vendor locally,
  reload, and the banner shows again.
- **The consent log is kept three years, not five.** `consent.log.retention_days`
  defaults to 1095, so the next `consent:prune` deletes choices older than
  that. Find: a site that relies on the five-year default. Migrate: set
  `consent.log.retention_days` to 1825 in `config/core-web.php` to keep five
  years. Verify: `php artisan config:show core-web.consent.log.retention_days`.

## v0.33.0

- **Registration numbers in `CoreForm`**: a `license_plate` field renders as
  a capitals text input, is checked as Core checks a plate's shape and goes
  trimmed and in capitals. It no longer logs as a type core-web does not
  know. See `docs/forms.md`, Registration numbers.
- **Field limits**: `minLength` / `maxLength`, `min` / `max` and a date's
  `earliest` / `latest` are checked before an entry is sent and set on the
  default markup's inputs. The definition contract copy is Core's current one.

## v0.32.0

- **Block changes in a draft** (connector API v1.3): a draft of an existing
  entry may send `block_changes` instead of a whole page builder, a list of
  `insert`, `update`, `replace`, `move` and `remove` operations naming blocks
  by their id in the current draft. The site applies them to the draft it
  holds, refuses a block type the page builder does not offer, and answers
  with the draft as written. See `docs/drafts.md`, Changing single blocks.

## v0.31.0

- **`content:push --publish`**: on a live site with
  `content_sync.push_publish` on (off by default), a push publishes its
  drafts and applies its proposals once every file is written, as the
  pushing person and within their CP permissions; what they may not publish,
  or what the checkout keeps `published: false`, stays a draft and is sent
  again by the next `--publish`. Not combined with `--force`. The lock gains
  `published`. See `docs/content-push.md`, Publishing.
- **`content:push` carries page trees and terms**: a collection's page tree
  is a proposal like a nav tree (a new entry pushed in the same run may sit
  in it), and a term's default-site values are a proposal; a new term,
  which Statamic cannot keep as a draft, is created only by `--publish`.
  Each needs the person's own reorder, edit or create permission. See
  `docs/content-push.md`, What is sent and Terms.
- **Choosing what a push sends**: `content:push` takes paths (files,
  folders or `*` patterns under `content/`), `--staged` (as `git add`ed) and
  `--committed`, and `--json` for agents. The new `content:status` lists
  what a push with the same arguments would send, without calling the site.
  See `docs/content-push.md`, Choosing what goes.

## v0.30.0

- **Docs in `docs/`**: the README keeps the overview and installation, and
  each feature has its own page under `docs/` (shipped with the package, so a
  site's agent reads `vendor/inly/core-web/docs/`). `resources/agents/AGENTS.md`
  and error messages point at those pages.
- **Cloud sites keep one static cache across replicas**: with `cloud` on, the
  static cache moves off a `file` store onto the shared database store, under
  a prefix per build so a deploy never serves a page the old release cached.
  Before, a save cleared one replica's copy and the others kept serving the
  old page. Clearing it empties the cache table. `docs/laravel-cloud.md`.
- **Form uploads on Cloud wait in the private bucket**: Livewire's temporary
  uploads (CoreForm file fields, a site's own) go to a `livewire-tmp` disk on
  the private bucket Cloud attaches, with several files per upload, and
  Livewire still deletes them after a day.
- **The Stache uses the `file` store on Cloud**, and a media disk a site keeps
  `local` is moved to the bucket there, as Peak's are. `docs/laravel-cloud.md`.
- **A multi-pick with an invalid pick collects every valid one**:
  Statamic drops an invalid pick from a multi-value field (a dictionary's raw
  array of keys) and keeps the others' keys, so `[0 => …, 2 => …]`.
  `Values::collect()` and `Values::first()` took that gapped list for one
  record and returned it as a single item; they now read an array with only
  integer keys as a list.

### Upgrade notes

- **Old form uploads may sit in the public bucket.** On Cloud, a site whose
  default disk was the public `media` bucket kept Livewire's temporary
  uploads (form photos, documents) under `livewire-tmp/` there, and Livewire
  never cleans an `s3` disk. Find: list `livewire-tmp/` in the Cloud site's
  `media` bucket. Migrate: delete that folder; new uploads go to the private
  bucket. Verify: the folder is gone and a form upload still submits.

## v0.29.1

- **Cloud's env vars survive a cached config**: with `cloud` on, a config
  cached on Laravel Cloud kept the values the build resolved only until the
  first request, which read the env again without `.env` and put every
  default back (a Solo site showed Statamic Pro's trial alert). The values
  resolved when the config was cached are now kept, from the next deploy.

## v0.29.0

- **Solo sites on Cloud need one env var**: with `cloud` on,
  `STATAMIC_PRO_ENABLED=false` turns revisions off too, since they need Pro.
  `STATAMIC_REVISIONS_ENABLED` still wins when set. README → Laravel Cloud.
- **The cookie settings dialog opens centred**: on a site whose CSS reset
  zeroes margins (Tailwind's preflight) it opened in the top-left corner.
  The page behind it no longer scrolls while it is open.
- **The consent id is folded away**: in the settings dialog it sits with the
  consent date under *Show consent details*, and the cookie declaration
  (`<x-core-web::consent.declaration />`) shows it under the current choice.

## v0.28.0

- **Undo an applied proposal**: applying a proposal records the value it
  replaced, and an editor undoes it from the proposal or the Proposals
  page's *Recently applied* list until the target changes again. Gives
  navigations, page trees, globals and terms one step back, on Laravel
  Cloud too. Only the last proposal applied to a target is undone. The
  Statamic connector API's proposal shape gains `undoable`, `undone_by`,
  `undone_at` and the status `undone`. See README → Proposals.
- **`content:import` finishes asset meta on large media libraries**: asset
  meta is imported one container per process (up to an hour each) through
  the hidden `core-web:asset-meta <container>`, which skips assets already
  in the database. Before, all of it ran in one ten-minute step, which a
  large bucket outlasted (each asset is a read of its `.meta`), and a run
  again started over. A failure or a timeout names the containers left and
  the commands that finish the import. README → Laravel Cloud.
- **Rate limits group IPv6 by /64**: the named per-address limits (chat
  turns, the consent log, CMS sign-in, content sync, forms) count an IPv6
  client by its /64, the block one subscriber is given, and an IPv4-mapped
  address as the IPv4 client.
- **Shared-secret pings open once**: a ping signed with
  `WEBSITE_SYNC_SECRET` needs a nonce, is refused when its nonce was seen
  before, and may expire at most an hour (plus a minute of skew) out. Pings
  sealed with Core's key were already single-use. README → Ping contract.
- **Local sign-in only from the machine itself**: the local CP sign-in
  through the live site answers only a loopback peer with no proxy headers,
  as the local form relay does; a share, tunnel or another device gets a
  plain-text 403.

### Upgrade notes

- **Local sign-in behind Docker or a proxy**: a checkout served from Docker
  (Sail, DDEV, Lando) or behind any local reverse proxy that adds
  `X-Forwarded-*` or `Via`, where requests do not reach PHP from 127.0.0.1
  directly, can no longer use the local CP sign-in. Find it: the sign-in
  answers "signs in as your live account only from this machine". Migrate:
  serve the checkout on the host (`php artisan serve`, Herd), or set
  `core-web.cms_sign_in.local_password_sign_in` to `true` and sign in with a
  local password. Verify: `/cp` signs in.
- **Shared-secret pings in a site's own tests**: a test that posts the same
  `FakesCore::signedPing()` envelope twice, or signs one with a fixed or
  far-off `expires_at`, now gets a 403. Find it: ping tests failing with 403.
  Migrate: sign each ping fresh and leave `expires_at` and `nonce` to
  `sign()`, or freeze time to within an hour of a fixed vector. A sender of
  shared-secret pings must send a 32-hex nonce and expire within the hour,
  or move to pings sealed with Core's key. Verify: the tests pass.

## v0.27.0

- **CMS sign-in works with users in the database**: the hand-off back from
  Core failed with `Call to undefined method
  Statamic\Auth\Eloquent\User::passwordHash()` on a site whose users are on
  the eloquent repository (the `cloud` feature's default), so nobody could
  sign in to the CP there. The account's password is now cleared on either
  repository, and taking super or every mapped role from an account writes
  `false` and an empty role list there instead of a null column.

## v0.26.0

- **Core AI's drafts through core-web**: with `drafts` and `content` on,
  core-web serves `POST drafts`, `GET drafts/{id}` and `POST
  drafts/{id}/publish` (Statamic connector API v1.1) on its shared
  `DraftWriter`, so a site drops its own drafts controller and writer. See
  README → Drafts → Core AI's drafts.
- **Content schema**: with `drafts` and `content` on, core-web also serves
  `GET blocks` and `GET blocks/{collection}/{handle}`, what Core AI builds a
  draft from, read from the blueprints of `drafts.collections`
  (`StatamicContentSchema`, configured in `drafts.schema`), and refuses a
  draft naming an older `catalog_hash`. Docs: README → Drafts → Content
  schema.
- **Statamic helpers**: `Inly\CoreWeb\Support\Values` (`unwrap`, `collect`,
  `first`), `Assets` (`meta`, `mimeType`, `size`, outage-guarded) and `Links`
  (`url`, `withSuffix`), for the field shapes every site normalises; the media
  components read through them too. `Values::first()` fetches a query builder
  before taking the first item, so it keeps the editor's manual order where a
  site's own `firstAsset()` calling `->first()` on the builder did not. Docs:
  README → Statamic helpers.

### Upgrade notes

- **A site's own `blocks` endpoints and `DraftCatalog`.** What breaks: a
  site's own block catalog at `api/core/v1/blocks` shadows core-web's (app
  routes load after a package's), a class implementing `DraftCatalog` no
  longer loads (the interface is gone), and every draft naming the site's
  own `catalog_hash` is refused (`catalog_changed`), since drafts are now
  checked against core-web's schema. Find it: `grep -rn DraftCatalog app/`
  and `grep -rn "blocks" routes/`. Migrate: delete the routes, controller,
  catalog class and its binding; set `drafts.schema` (`settings_fieldset`,
  `guide_fieldset`, `value_formats`, e.g. the site's own `assets` format) to
  what the catalog hardcoded, implement `DescribesStoredValue` on
  Dictionaries whose keys the catalog described, and bind a
  `StatamicContentSchema` subclass to `ContentSchema` for anything else (an
  entry `status()` of its own). Verify: `GET api/core/v1/blocks` answers the
  same blocks and fields as before (the hash changes once), and a draft
  with its `catalog_hash` is taken.

## v0.25.0

- **`content:import` imports CP users**: with users on the eloquent
  repository (the `cloud` feature's default), it runs
  `statamic:eloquent:import-users` last when `users/` holds accounts and the
  users table is empty, so a site without Core sign-in can sign in after its
  first deploy. A table that already holds accounts, or a site with
  `cms_sign_in` on, is left alone; a failed users import only warns.
  README → Laravel Cloud.
- **`content:import` finishes on large sites**: entry URIs and order are
  recomputed per collection in their own process, and a failure or a
  timeout names the
  collections left and the `core-web:entry-order` command that finishes
  them. Before, a large site's last step could be killed without a message.
  README → Laravel Cloud.
- **`content:import` leaves nothing on the queue**: each step runs through
  the hidden `core-web:import-step`, which runs it with the `sync` queue and
  with search indexing off. Before, Cloud's cached config ignored the
  `QUEUE_CONNECTION=sync` handed to each step, so saving the trees queued one
  entry-order job per entry, and search updates queued too, waiting on a
  queue a site may have no worker for. When an index's driver outlives the
  container (anything but `local` and `null`), the indexes are rebuilt once
  at the end; a `local` index builds itself on its first search. README → Laravel
  Cloud.
- **Feature commands on every Cloud site**: with `cloud` on, `media:sync`,
  `media:check` and `content:check` succeed with a "feature is off" note
  where their feature is off, so a fixed first-deploy list (core-cloud's)
  runs on every site. README → Laravel Cloud.

- **Mail sender**: core-web builds `mail.from.address` as
  `<local part>@<domain>` from `core-web.mail.from`, so a site commits its
  local part (`acme`) instead of a whole address per environment. The
  domain defaults to `transactional.inly.dev`; `MAIL_FROM_LOCAL_PART` and
  `MAIL_FROM_DOMAIN` override each part, and `MAIL_FROM_ADDRESS` or a
  committed address still wins. Without a local part nothing changes.
  README → Mail sender.

- **Cookie consent**: an opt-in `consent` feature, a light consent tool in
  place of Cookiebot for sites that need no certified CMP. The banner,
  settings dialog and cookie declaration in Inly Core's look (overridable),
  scripts and embeds held back per category, Google Consent Mode v2 with
  Tag Manager held back until statistics or marketing consent by default, and a consent
  log in the site's database. A corner button reopens the settings once the
  visitor has chosen (`consent.button`). Editors set the banner's title,
  text and cookie page on a global (`consent.global`, the `core-web::consent`
  fieldset), and a site with only necessary cookies shows no banner.
  README → Cookie consent.

## v0.24.0

- **Glide images on Cloud work on every replica**: the `cloud` feature turns
  off Statamic's `true` and `hybrid` Glide caches, which keep renditions in
  one container's `public/img` and their keys in a store. On another replica,
  or after a deploy, a cached page's `/img/` URLs failed (404, or 400 with
  `true`) and the CP's thumbnails answered 500. Uncached, each URL carries its
  source (signed, with `secure` on) and every container renders its own
  copies, so each image goes through PHP and is rendered again after a
  deploy. Presets are no longer made at upload there either. A cache on a
  disk of its own is kept.
  README → Laravel Cloud.
- **Favicons and social images on the bucket on Cloud**: with `media` on, the
  `cloud` feature turns Peak's `local` `favicons` and `social_images` disks
  into media disks on the bucket. On Cloud's disk, Peak Tools' generated icon
  set and Peak SEO's generated social images were gone at the next deploy and
  seen by one replica only. Peak's `/favicons/...` links redirect to the
  bucket. README → Laravel Cloud.

### Upgrade notes

- **Glide renders through PHP on Cloud.** A Cloud site with
  `image_manipulation.cache` set to `true` or `hybrid` now serves Glide
  images uncached. Find it with `grep -n "'cache' =>" config/statamic/assets.php`.
  Nothing to migrate. Image URLs change from `/img/containers/...` to
  `/img/asset/...?s=`, so clear the static cache once after the deploy
  (`core-web:deploy` does). Verify by opening a page and a CP asset
  thumbnail on Cloud.
- **Peak's favicons and social images move to the bucket on Cloud.** A Cloud
  site with `media` on whose `config/filesystems.php` defines `favicons` or
  `social_images` with the `local` driver now serves them from the bucket,
  in folders of the same name. Find it with
  `grep -n "'favicons'\|'social_images'" config/filesystems.php`. Run
  `php artisan media:sync push --force` once after the deploy so files in
  git reach the bucket (an icon set Peak Tools generated on Cloud was lost
  already: generate it again from the CP), and set `allow_uploads: true`
  back on those containers if it was turned off for Cloud. A site that already
  lists them in `media.disks` keeps its folders and needs nothing. A site that
  committed icons to `public/favicons` removes them from git after the push:
  Cloud serves files in `public/` before the redirect to the bucket. Verify with
  `php artisan media:check --disk=favicons` (and `--disk=social_images`),
  and that a page's favicon link answers a redirect to the bucket.

## v0.23.1

- **Docs**: README → Laravel Cloud lists the gotchas found launching the
  first site: `league/flysystem-aws-s3-v3` is required before Cloud deploys
  with a bucket, uuid foreign keys to users and sessions, the Statamic Solo
  licence env, `APP_NAME`, the CORS the public bucket must answer behind
  core-cdn, and the queue on Cloud. No code changes.

## v0.23.0

- **Cloud sites can sleep**: on Laravel Cloud the `cloud` feature turns off
  Statamic's every-minute scheduled-entries job, which woke a hibernating
  site each minute, and schedules `core-web:scheduled-entries` every 15
  minutes instead. It covers every entry dated since its last run, so
  static-cached pages and search still update. `core-web:deploy` warns on
  Cloud about tasks due more often than every 15 minutes. README → Laravel Cloud → Scheduled
  entries and sleeping.
- **Cloud media on Core Cloud's buckets and CDN**: with two buckets attached,
  the asset disks use the public one on disk `media`
  (`media.bucket.cloud_disk`, or `CORE_PUBLIC_DISK`), not just the first. With no `media.bucket.url` committed, files are served
  through `CORE_CDN_BASE_URL` (set by Core Cloud's CDN integration) before
  the bucket's own URL, so images are transformed with nothing committed.
  README → Media → On Laravel Cloud.
- **Cloud content tables that fit content/**: `--tag=core-web-cloud-migrations`
  publishes the eloquent driver's content migrations with string entry ids
  and json columns. With the stock ones, `content:import` failed on Postgres
  (an id such as `home` is not a uuid), and jsonb re-sorted every entry's
  keys. The import now checks the columns first and names any it cannot
  fill. README → Laravel Cloud.

### Upgrade notes

- **`content:import` refuses content tables with jsonb or non-string entry
  ids.** Affects Cloud sites that published the eloquent driver's own
  migrations: `grep -ln "jsonb(\|uuid('id')" database/migrations`, or run
  `content:import`, which names the columns. Publish core-web's copies over
  them (`php artisan vendor:publish --tag=core-web-cloud-migrations
  --force`), delete a driver `create_entries_table.php` if there is one, and
  rebuild the tables on a database no editor has used (`migrate:fresh`). A
  database editors already use keeps its tables until its data is moved
  over. Verify: `content:import` gets past its column check.
- **Images on a Core Cloud site switch to CDN URLs.** Affects Laravel Cloud
  sites with no `media.bucket.url` whose environment has `CORE_CDN_BASE_URL`
  and a bucket on disk `media` (`grep -n "'url' =>" config/core-web.php` under
  `bucket`). Their files were served from the bucket's own URL and are now
  served through the CDN, transformed. Nothing to migrate; to keep the
  bucket's URL, commit `'cdn_url' => ''` under `media.bucket`. Verify with
  `php artisan media:check` and an image's `src` on a page.
- **A scheduled entry's static-cache copy and search entry now update up to
  15 minutes late on Cloud.** Affects sites with `features.cloud` on Laravel
  Cloud (`grep -n "'cloud' =>" config/core-web.php`, set to true or an env
  var that is). A config published earlier needs no new keys. Statamic's
  every-minute job no longer runs there. If an entry must appear within the
  minute, set `cloud.scheduled_entries.schedule` to `* * * * *` (the site
  then never sleeps) or `STATAMIC_HANDLE_SCHEDULED_ENTRIES=true`. Verify
  with `php artisan schedule:list`: `core-web:scheduled-entries` is listed
  and Statamic's `HandleEntrySchedule` is not.

## v0.22.0

- **Progress while pulling**: `content:login` says it is waiting for the
  approval and counts down the code's time, and `content:pull` and
  `core:pull` show a live count per type and asset container while they
  page. Only on a terminal: piped output and logs get the result lines alone.
- **Agent rules for media**: `resources/agents/AGENTS.md` now says the bucket
  key is for servers only, that a checkout gets assets with `content:pull
  --only=assets` (and a stale local key must go), how to move a site's own
  bucket disk onto core-web's, and that local uploads never reach
  production. README → Media gains "Moving a site's own bucket disk onto
  these".
- **Content push**: `content:push` sends a checkout's changed entries,
  globals and nav trees to the live site as drafts and proposals, never live,
  with the local media files they name. A file changed on the site since the
  pull is refused unless `--force`; the push is all-or-nothing unless
  `--partial`. Needs the new **Push content** permission. `content.lock`
  gains `bases` (written by `content:pull`), `pushed` and `drafts`. Docs: README →
  Content push.
- **Local CP sign-in through the live site**: a local checkout without
  `CORE_URL` signs its CP in after `php artisan content:login`, as a local
  account mirroring the developer's live-site account (super flag, roles,
  groups), re-checked with the live site on every sign-in. The live site
  answers a new sync endpoint, `me`, so it needs this version first. See
  README → CMS sign-in → Local development.
- **Core forms on a local checkout**: without a `CORE_URL`, a local checkout
  signed in with `content:login` sends its Core forms through the live site:
  `CoreForms::embedBaseUrl()` gives Core's embed the local relay, and
  `CoreForm` entries are sent on by the live site with its client token. The
  live site relays only the forms in its own form list. Entries are real, and
  `<x-core-web::forms.relay-notice />` says so. Both sides need the release.
  See README → Forms → Forms on a local checkout.
- **The site's Core is committed, not env**: `core.url` and `core.client_id`
  in `config/core-web.php` name the Core a site belongs to and fill
  `client.base_url` / `client.client_id` on every server where neither
  `CORE_URL` nor `CORE_CLIENT_ID` is set (never where `APP_ENV` is `local` or
  `testing`). `CORE_URL` and `CORE_CLIENT_ID` become overrides, set together,
  for an environment paired with another Core. Once a site commits `core.url`,
  an empty `CORE_URL` no longer leaves a server unpaired: every server not on
  `local` pairs with that Core. `core-web:doctor` fails a local checkout
  whose `CORE_URL` names a remote Core. See README → Configuration.

## v0.21.0

- **Glide presets without a CDN**: `<x-core-img>` gives a local Asset (JPEG,
  PNG or WebP) the CDN does not serve a srcset from the site's `w<px>` Glide
  presets narrower than the original, plus the original at its own width,
  with `sizes` defaulting to `100vw`. A site without such presets renders as
  before. See README → Media → The components.
- **Gravity on the CDN scheme**: `UrlScheme::image()` takes `gravity`, an
  `"XxY"` point as fractions of the image, clamped to 0-1 (core-cdn's
  `gravity`), so a site's own crops (an og:image) keep the editor's focal
  point without building the query string itself.
- **`media:check` after `optimize`**: on a server whose config is cached (every
  Forge deploy), `media:check` and `content:pull`'s asset index took
  core-web's own disks for the app's. They now know which disks core-web
  defined.

## v0.20.0

- **Site rules**: `rules/CoreWebRulesTest.php`, run by Inly's site gate
  (site-ci) on every site that installs core-web. Its first rule fails a
  site with `features.media` on that still defines its own `assets` disk (or
  any disk in `media.disks`). Docs: README, "Site rules".

## v0.19.0

- **Agent instructions**: `resources/agents/AGENTS.md` carries the rules an
  agent working on a site needs, per feature. A site imports it from its
  `CLAUDE.md` (`@vendor/inly/core-web/resources/agents/AGENTS.md`), so the
  rules update with the package. See README → Agent instructions.
- **`core-web:doctor`**: checks what the switched-on features need from the
  environment (the Core client, a real queue, the schedule, the legacy ping
  secret, CMS accounts kept out of git), exiting non-zero on a failure.
  Registered once any feature is on. See README → Checking a site's setup.

## v0.18.0

- **Laravel Cloud**: `features.cloud` (off by default). On Laravel Cloud
  (`LARAVEL_CLOUD=1`) it moves what must outlive a deploy into the database:
  content and CP users on `statamic/eloquent-driver`, cache, sessions, the
  maintenance flag and the snapshots store; it also sets static caching to
  `half`, turns on revisions and Pro, and turns off the stache watcher and
  Statamic's git integration. An
  env var the environment sets keeps its own value, and off Cloud nothing
  changes. It also adds `content:import` (loads `content/` into an empty
  database, trees after entries) and `core-web:deploy` (the deploy steps in
  one command). See the README's Laravel Cloud section.

## v0.17.0

- **Asset index pull**: on a checkout with `APP_ENV=local`,
  `features.content_sync` and `features.media` on, `content_sync.remote_url`
  set and no bucket key, `content:pull` (and `--only=assets[:container]`)
  brings the live site's asset index (each file's path, public URL and
  `.meta`, never its bytes) into `storage/core-web/remote-assets/`,
  replaced on every pull. The asset disks then read three layers: uploads
  in `public/media-layer/`, the committed `public/<folder>` (read only,
  and still shown before the first pull), and the live files from the
  index, so the CP browses and renders production's assets and uploads stay
  local; deleting or moving a committed or pulled file throws
  `ProductionFileLocked`. `Asset::url()` there is a local-only route
  (`/!/core-web/media/<disk>/<path>`) that redirects to whichever layer
  holds the file. In the CP a file only the index holds takes its
  thumbnail from the CDN (its public URL without one; a video's a CDN
  frame, or none) rather than Glide downloading every live file for one.
  The export gains an `assets` type, limited to containers the user may
  view; a site on Statamic's Eloquent driver sends files in
  subfolders and their meta too. A live site without the type is skipped
  with a warning and content still pulls. `content_sync.pull_assets: false` keeps the plain
  local folder. See README → Content pull → Assets and README → Media → The
  disks.

## v0.16.0

- **Content pull**: `features.content_sync` (off by default) lets a local
  checkout pull the live site's content (entries, terms, global variables,
  collection and nav trees) into `content/` as the files the Stache
  writes, signed in as the developer's CMS user: `content:login` approves
  the machine in the live site's CP and stores a personal token (30 days
  from its last use, revocable under Tools → Content sync), `content:pull`
  writes what changed on the site and keeps local edits, tracked in a
  committed `content.lock`, deleting only where the developer may read
  everything on the site; a file that differs only in key order or in
  `updated_at`/`updated_by` (a site on the Eloquent driver) is left as it
  is. Approving a machine and using its token need the addon's `pull
  content` permission. Set `content_sync.remote_url` in the
  site's `config/core-web.php` (committed; there is no env var for it).
  The live site keeps token hashes in private files by default; one behind
  more than one server or on a host whose disk does not outlive a deploy
  (Laravel Cloud) sets `content_sync.tokens.driver` to `database` and runs
  the `core_web_sync_tokens` migration (published with
  `--tag=core-web-sync-tokens-migration`); the tokens page says what to
  set up when that table or its database is missing. A site running as
  APP_ENV=local (without `content_sync.allow_local_remote`) refuses sync
  calls and has no sync pages in its CP. See README → Content pull.
- **`core:pull` through the live site**: `core:pull` reads the Core
  snapshots from the live site's sync API on the `content:login` login, so
  one login covers content and snapshots and no developer needs a Core
  client secret. The live site serves the copy it renders from (one cached
  pull from its own Core when it has none), only for the snapshots it
  registers, paged, behind the same guards, token and rate limit as the
  content export. It no longer pulls from the remote Core directly: the
  remote client secret plays no part in it, and snapshot warmers no longer
  run during a pull (the documents are pinned as the live site stored
  them). Without `content_sync.remote_url` or a login, the command says to
  set one or run `content:login`. Pins behave as before. The Pull content
  permission and the machine approval screen now say the token also reads
  every snapshot the site keeps, whole. A copy that changes between pages
  restarts the pull (twice at most), and a 429 or 503 is waited out as
  `Retry-After` says (a minute at most). See README → Pulling production
  data.
- **Signing in is no separate step**: `core:pull` now
  starts `content:login` itself when run in a terminal without a login, or
  after the site refused the stored one, and carries on pulling, as
  `content:pull` does; without a terminal both still stop and say to run
  `content:login`, which remains for signing in ahead of time or as
  another user. A site that refuses even the login just made (one behind
  more than one server with the `file` token store) stops the run after
  that one sign-in, naming the snapshots not pulled. Commands share this
  through the `SignsInToRemoteSite` trait. See README → Content pull.
- **The remote Core is gone**: the `remote` config block
  (`CORE_REMOTE_URL`, `CORE_REMOTE_CLIENT_ID`, `CORE_REMOTE_CLIENT_SECRET`),
  its legacy name `pull` and the `CORE_PULL_*` variables are no longer
  read, and no developer needs a Core secret. A local CMS sign-in goes to
  `CORE_URL` (a local Core) or, with `cms_sign_in.local_password_sign_in`,
  Statamic's own login; the control panel link points at `CORE_URL` only.
  `cms_sign_in.remote_site` and `cms_sign_in.core.verify_remote` are gone
  with it. See README → CMS sign-in.

### Upgrade notes

- **`core:pull` needs the live site**: it no longer pulls from the remote
  Core with `CORE_REMOTE_CLIENT_SECRET` (or `CORE_PULL_CLIENT_SECRET`), so
  a machine that pulled that way now fails with "Nothing to pull from" or
  "not signed in". Find it: `php artisan config:show
  core-web.content_sync.remote_url` is empty, or `.env` holds
  `CORE_REMOTE_CLIENT_SECRET` or `CORE_PULL_CLIENT_SECRET`. Migrate:
  switch on `features.content_sync` and set `content_sync.remote_url` to
  the live site in the site's `config/core-web.php` (README → Content
  pull); deploy this core-web version to the live site, so it serves
  `/!/core-web/sync/snapshots/{type}`, with `features.snapshots` on and
  the same snapshots registered as the checkout pulls; and give developers
  the Pull content permission there. Verify: `php artisan core:pull` signs
  in (or uses the existing `content:login`) and reports each snapshot
  pinned from the live site's host.
- **The remote Core is removed**: the `remote` and `pull` keys in a site's
  `config/core-web.php`, the `CORE_REMOTE_*` / `CORE_PULL_*` variables,
  `cms_sign_in.remote_site` and `cms_sign_in.core.verify_remote` are
  ignored, and the class `Inly\CoreWeb\Client\RemoteCore` is gone, so
  site code that uses it now fatals. A local machine no longer signs in to
  the CP through the production Core: with `features.cms_sign_in` on, no
  `CORE_URL` and `local_password_sign_in` not active, the CP login answers
  503. The control panel link points at `CORE_URL` only, so a machine
  without one shows no link. Find it: `grep -rn
  "core-web\.\(remote\|pull\)\|RemoteCore" app/ config/ tests/ resources/`,
  `grep -n "'remote'\|'pull'\|remote_site\|verify_remote"
  config/core-web.php` and `grep -rn "CORE_REMOTE_\|CORE_PULL_" .env*`.
  Migrate: remove or replace the code that uses `RemoteCore` or
  `core-web.remote` / `core-web.pull` (`Inly\CoreWeb\Client\CoreClient::fromConfig()`
  is the site's own Core); delete the `remote` / `pull` blocks,
  `cms_sign_in.remote_site` and `cms_sign_in.core.verify_remote` from the
  site's config and the `CORE_REMOTE_*` / `CORE_PULL_*` lines from `.env`
  and `.env.example`; to sign in to the CP locally, run a local Core as
  `CORE_URL` that knows the site under `cms_sign_in.site` (README → CMS
  sign-in), or set `cms_sign_in.local_password_sign_in` to `true` (only
  with `APP_ENV=local`). Verify: the greps come back empty, `php artisan
  config:show core-web` lists no `remote` key, and `/cp/auth/login` goes
  to the local Core or shows Statamic's login.

## v0.15.0

- **Media**: `features.media` (off by default) renders a site's pictures and
  videos through the transforming CDN, and defines its asset disks on the
  bucket. See README → Media.
  - `<x-core-img>`, `<x-core-video>` and `<x-core-media>` take a Statamic
    Asset, an assets field, a URL or a file from Core alike. Images get
    `format=auto`, a srcset, the focal point and a blurred placeholder;
    videos get CDN versions for wide and small screens, a frame poster and a
    blurred placeholder of that frame until it loads.
    URLs the CDN does not serve render as is.
  - The disks in `media.disks` are the bucket in production and the local
    folder without a key, unless the app's config/filesystems.php defines
    them (remove the site's own `assets` disk). Elsewhere they sit behind a
    layer (`CORE_MEDIA_LAYER=false` turns it off on a staging server set up
    before go-live, so editors build content on the real bucket):
    writes land in it, and deleting or moving production's files throws
    `ProductionFileLocked`.
  - Only the key and secret (`CORE_MEDIA_KEY`/`CORE_MEDIA_SECRET`, or
    Cloud's) and `CORE_MEDIA_LAYER` are env; the rest of the
    bucket is committed config, and a site's `media` block keeps the
    defaults it leaves out. `acl: false` for R2.
  - On Laravel Cloud, with no key set, the disks use the environment's
    attached bucket (`LARAVEL_CLOUD_DISK_CONFIG`), without ACLs and without a
    layer unless `CORE_MEDIA_LAYER=true`; a committed `bucket.url` (the CDN)
    still wins over the bucket's own URL.
  - `MediaSource::urlOf()` gives a file's URL as the components render it.
  - `media:sync push|pull` copies a disk between its folder and the bucket
    (push adds, `--overwrite` replaces);
    `media:check` checks the bucket's headers and a CDN rendition.

## v0.14.0

- **CMS sign-in**: `features.cms_sign_in` (off by default) makes Core the
  only way into the control panel: the CP login redirects to Core, Core's
  sealed hand-off is confirmed with Core on the site's client token before
  anyone is let in, accounts are provisioned and stamped by it with roles
  mapped from Core's CMS grants (`cms_sign_in.roles`), Core's offboarding
  notices delete them, and `core:reconcile-cms-users` sweeps nightly. Local
  machines sign in through the remote Core. A site that signs its CP in from
  Core with its own code removes that code when it switches this on. See
  README → CMS sign-in.
- **Snapshots**: a snapshot can opt into pages with `->pages(fn (array $doc,
  string $site) => '/path')`, and `SnapshotPage` serves one from the site's
  own route: `find()` (503 while nothing can be read, Statamic's 404 when
  gone), `canonical()` (a 301 to the document's own path, query string kept)
  and `render()` (Statamic's View with an optional cascade entry). Snapshots
  without `->pages()` are unchanged. See README → Pages.

## v0.13.1

- **Naming**: the website content protocol is now called the Statamic
  connector API, matching Core's Statamic connector, in the README, config
  comments and package description. The test-vector fixture moved to
  `tests/fixtures/statamic-connector-api-v1-test-vectors.json`. Nothing on the
  wire, in config keys or in the version numbers changes.

## v0.13.0

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
- **Protocol v1.2**: with `features.content` and `checks` or `drafts`, Core
  reads a site's checks and findings, asks for fixes, reads proposal targets
  and makes proposals for a person in Core, sealed with Core's key. See
  README → Core reads checks and proposes.

## v0.12.0

- **Forms**: `features.forms` (off by default) for a form with the site's
  own Livewire markup that posts to a Core form (`CoreForm`). Core's
  definition, kept in the `core-web-forms` snapshot, gives it defaults,
  validation, the hidden page fields, and any required field the markup
  leaves out, rendered after it (with a warning in `local`). Entries go on
  the site's client token, behind a honeypot and capped per address by
  `forms.submit_rate_limit`. Mark each such form **Custom form embed** in
  Core, so its admins are warned before changing its fields. Needs
  Livewire. See README → Forms.
- **Core client**: `withToken()` sends a request it builds itself (a
  multipart body) on the client token.

## v0.11.0

- **Control panel link**: `features.cp_link` (off by default) adds a link to
  Core right under Dashboard for every CP user, in a new tab, to `CORE_URL`
  (or locally to the remote Core when no `CORE_URL` is set).
  `cp_link.label` names it and `cp_link.icon` swaps Core's mark for a
  Statamic icon or an SVG. See README → Control panel link.
- **Config**: `pull` is renamed `remote` (`CORE_REMOTE_URL`,
  `CORE_REMOTE_CLIENT_ID`, `CORE_REMOTE_CLIENT_SECRET`), since it is the Core
  a local machine uses, not only `core:pull`'s source. A site config naming
  `pull` keeps working, as do the `CORE_PULL_*` variables unless the site
  writes its own `remote` block (README → Pulling production data). `pull`
  and `CORE_PULL_*` are deprecated.

### Upgrade notes

- **`pull` is deprecated**: the config key `pull` is now `remote`, and
  `CORE_PULL_URL`, `CORE_PULL_CLIENT_ID` and `CORE_PULL_CLIENT_SECRET` are
  now `CORE_REMOTE_URL`, `CORE_REMOTE_CLIENT_ID` and
  `CORE_REMOTE_CLIENT_SECRET`. The old names are still read and will be
  removed in a later release. Two cases stop working now: a site that
  writes its own `remote` block reads only the variables that block names,
  so a `.env` holding only `CORE_PULL_*` fails `core:pull`; and code or
  tests that set `core-web.pull.*` at runtime are no longer read, since the
  `pull` block is copied into `remote` once, at boot. Find it:
  `grep -rn "'pull'\|core-web\.pull\|CORE_PULL_" config/ app/ tests/ .env*`.
  Migrate: rename the `pull` block in `config/core-web.php` to `remote` and
  its variables to `CORE_REMOTE_*` (nest the old name as the default,
  `env('CORE_REMOTE_CLIENT_SECRET', env('CORE_PULL_CLIENT_SECRET', ''))`,
  until every developer's `.env` has moved), rename them in `.env.example`,
  and replace `core-web.pull` with `core-web.remote` in code and tests.
  Verify: `php artisan config:show core-web.remote` shows the base URL,
  client id and a secret, and `php artisan core:pull` runs.

## v0.10.0

- **Snapshots**: a `core_snapshot` control panel widget lists a snapshot's
  documents, paginated, each linked to its record in Core. A snapshot opts in
  with `->listing(title:, coreType:, coreId:)`; `CoreClient::openUrl()` builds
  the link to Core's `/open/{object}/{id}`. See README → Control panel widget.
  Its labels are translatable, with Swedish shipped in `lang/sv.json`; `icon`
  sets the widget's icon.

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

### Upgrade notes

- **The Core URL must be https outside `local` and `testing`**: the token
  request throws a `RuntimeException` ("core-web sends its client secret to
  Core over https only") on a staging or production site whose `CORE_URL`
  is `http://`, so every call that needs a token fails: snapshot pulls
  (also those a ping starts), page pings to Core, chat turns and live
  reads. Find it: `grep -n "CORE_URL" .env` on each server. Migrate: set
  `CORE_URL` to Core's `https://` address. Verify: `php artisan cache:forget core_web_access_token`, then
  `php artisan core:warm` prints a document count for every type and no
  `pull failed` (a site without `features.snapshots`: send a chat message or
  run a live read).
- **A Core URL that redirects no longer gets a token**: the token request
  follows no redirect, so a `CORE_URL` (or `core:pull`'s `pull.base_url`)
  that answers with a redirect, such as http to https or a `www` variant,
  throws `TokenRefused` with the 3xx status. Find it:
  `curl -s -o /dev/null -w '%{http_code}' -X POST https://<core>/oauth/token`
  prints a 3xx. Migrate: set the URL to the address the redirect lands on.
  Verify: as above.
- **A refused token throws `TokenRefused`**: it extends `RuntimeException`,
  so code catching that keeps working, but code that matched the message
  `Failed to obtain a Core access token: ` now sees the status in brackets
  after it. Find it: `grep -rn "Failed to obtain a Core access token" app/ tests/`.
  Migrate: catch `Inly\CoreWeb\Client\TokenRefused` and read `->status`;
  the https refusal above stays a plain `RuntimeException`.
- **`CoreSigningKeys::secureBaseUrl()` is removed**: a subclass calling it
  fails. Find it: `grep -rn "secureBaseUrl" app/`. Migrate: call
  `CoreClient::secureUrl($url)`.
- **Chat turns are capped per address too** (30 a minute): visitors sharing
  one address, such as an office network, share the cap. Find it: the site
  has `features.chat` on. Migrate: raise `chat.turn_rate_limit_per_address`
  in the published config if that is too low. Verify: nothing to run.

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

### Upgrade notes

- **`ext-sodium` is required**: `composer install` refuses the package on a
  PHP without the sodium extension. Find it: `php -m | grep -i sodium` on
  each server and in CI prints nothing. Migrate: install or enable the
  extension (bundled with PHP builds on Forge and Laravel Cloud). Verify:
  `composer check-platform-reqs` passes.
- **A `CoreClient` subclass overriding `sendResponse()` must widen `$data`**:
  PHP refuses an override typed `array $data` with a fatal error at boot.
  Find it: `grep -rn "function sendResponse" app/`. Migrate: type the
  parameter `array|Closure $data` and resolve a closure before use. Verify:
  the site boots (`php artisan about`).

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

### Upgrade notes

- **Rows come without `meta.schema` and `meta.object`**: on Core 9.0 and
  later, `objects()` and `object()` ask for compact meta, so a snapshot that
  reads a row's `meta.schema` (a money field's currency, for one) silently
  gets nothing. Find it: `grep -rnE "meta\.(schema|object)|\['meta'\]\['(schema|object)'\]" app/`.
  Migrate: pass `'meta' => 'full'` in that snapshot's query. Verify: the
  page shows the value again after `php artisan core:warm`.
- **`core:warm` runs nightly, not every ten minutes**: a site that Core does
  not ping shows changes up to a day late, with nothing failing. Find it:
  no ping URL for the site in Core's connector setup. Migrate: in the
  site's published `config/core-web.php` set `snapshots.warm_schedule` to
  `*/10 * * * *`; a partial config file must copy the whole `snapshots`
  array, since a top-level key replaces the package's default whole.
  Verify: `php artisan schedule:list` shows `core:warm` every ten minutes.
- **A fresh TTL under a day now serves stale copies between nightly
  pulls**: a stale copy no longer starts a pull, and a ping for one object
  pulls the whole snapshot instead of patching it. Find it: a snapshot
  whose fresh TTL is under a day. Migrate: give it a fresh TTL longer than
  a day (README § Schedule). Verify: a ping for one object updates only
  that document.

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

### Upgrade notes

- **The env names change**: with the old names the client has no URL or
  credentials and every call to Core fails. Find it: `grep -n "CORE_WEB_" .env .env.example`
  on each server and in the repo. Migrate: rename each `CORE_WEB_*` variable to
  the matching `CORE_URL`, `CORE_CLIENT_ID`, `CORE_CLIENT_SECRET` or
  `CORE_CA_BUNDLE`;
  a `config/core-web.php` published only to map env names can be deleted.
  Verify: `php artisan core:warm` prints a document count for every type
  and no `pull failed`.

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
