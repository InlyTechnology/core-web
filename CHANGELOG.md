# Changelog

A release that a site breaks on, or that silently changes how a site behaves,
carries an `### Upgrade notes` section: one entry per required action, each
saying what breaks, how to find the affected code, how to migrate and how to
verify. Read the notes of every version between the one a site had and the
one it moves to. Before 1.0 such a release bumps the minor version.

## Unreleased

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
