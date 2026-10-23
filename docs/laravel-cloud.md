# Laravel Cloud

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
| Statamic's Glide cache (`statamic.assets.image_manipulation.cache`) when `true` or `hybrid`, which keep renditions in one container's `public/img` | off: each image URL carries its source (signed, with `secure` on) and each container renders its own copies, through PHP | none; a disk name in `config/statamic/assets.php` keeps a cache shared in a bucket |
| Peak's `favicons` and `social_images` disks, when `config/filesystems.php` defines them `local` (with `features.media` on) | media disks on the bucket, in folders of the same name: Peak's addons hardcode the container handles and write through the disk; `/favicons/...`, where Peak Tools links its icons, redirects to the bucket | none; a disk of another driver is left alone |
| Stache watcher, Statamic's git integration | off | `STATAMIC_STACHE_WATCHER`, `STATAMIC_GIT_ENABLED` |
| The Stache's cache store (`statamic.stache.cache_store`), also over a store the site's `config/statamic/stache.php` names | `file`: the Stache is built from git, the same in every container, and on the database store every read would be a query. Each container builds its own on its first request, so add `php artisan statamic:stache:warm` to Cloud's build commands to ship it warm | `STATAMIC_STACHE_CACHE_STORE` |
| The static cache's store (`static_cache`), when the site has none or one on the `file` driver (Statamic's skeleton ships one) | the database store under a prefix per build (from the Vite manifests), so every replica shares one copy and a page cached by the old release while a deploy runs is never served by the new one. Clearing it (the deploy, the CP's Cache utility) empties the whole cache table. On another default store it is removed and the pages sit in that store | none; a store of another driver is kept |
| A media disk (`media.disks`) the site defines `local`, such as Statamic's skeleton `assets` disk or a Files container kept local for another host (with `features.media`) | a media disk on the bucket, like Peak's | none |
| Livewire's temporary uploads (`livewire.temporary_file_upload.disk`), when Cloud attaches a private bucket as `cloud.uploads_disk` (`private`) | a `livewire-tmp` disk on the private bucket, under a driver of its own (on an `s3` disk Livewire refuses a `multiple` field and never cleans up), so Livewire deletes uploads older than a day itself | none; a disk the site chose is kept |
| Revisions, Statamic Pro | on (each collection still sets `revisions: true`); revisions off when `STATAMIC_PRO_ENABLED=false` and `STATAMIC_REVISIONS_ENABLED` is unset | `STATAMIC_REVISIONS_ENABLED`, `STATAMIC_PRO_ENABLED` |
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
  A site that already published the driver's copies publishes with `--force`
  to replace them, deletes any driver entries migration under another name
  (`create_entries_table.php`), and rebuilds those tables (`migrate:fresh` on
  a database no editor has used yet): these are create migrations, so they
  never change a table that already exists.
- Give the `users` table uuid ids and the User model `HasUuids`, an empty
  `$guarded` and casts for `super`, `preferences` and `last_login`, which
  Statamic's eloquent users write. Every `user_id` column follows as
  `foreignUuid`: on `sessions`, or the first sign-in fails writing a uuid
  into a bigint, and in the tables `php please auth:migration` writes, or
  Postgres refuses their foreign keys.
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
  it from `LARAVEL_CLOUD_DISK_CONFIG` (see [On Laravel Cloud](media.md#on-laravel-cloud)).
  Require `league/flysystem-aws-s3-v3` before attaching it: Cloud refuses to
  deploy an app with a bucket and without that package.
- A Statamic Solo licence sets `STATAMIC_PRO_ENABLED=false` on the
  environment, since the feature turns Pro on, and keeps `revisions: false` on
  its collections. Revisions need Pro, so they are off with it unless
  `STATAMIC_REVISIONS_ENABLED` says otherwise.
- Cloud fills `APP_NAME` with the application's slug, which Statamic shows
  above the CP login; set it to the site's name.
- Mail: commit the site's `mail.from.local_part` ([Mail sender](mail-sender.md))
  rather than a `MAIL_FROM_ADDRESS` per environment.
- The feature leaves the queue alone. With no worker and no Cloud queue
  attached, keep `QUEUE_CONNECTION=sync`, or queued work (form mail, the
  snapshot and sign-in jobs) never runs.
- With `features.cms_sign_in`: run `php please auth:migration` (the
  `role_user` and `group_user` tables eloquent users write roles and groups
  to), keep the CP provider on the `statamic` driver with its `model` set,
  and turn on Cloud's scheduler for `core:reconcile-cms-users`.
- Search: a `local` index lives on one container's disk, so on Cloud it
  needs a remote search driver; `core-web:deploy` warns about any local
  index.

With the maintenance flag on the database store, every request reads one
cache row to know whether the site is down.

## Scheduled entries and sleeping

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

## First deploy

On a new, empty database, load `content/` once from Cloud's commands tab:

```bash
php artisan content:import
```

It refuses while the database holds entries or misses one of the driver's
tables. `--fresh` (with `--force`, or a yes to its question) empties
entries, trees, globals, terms and asset meta first, editors' work
included, so it is for a first deploy or a rebuild only; form submissions,
revisions and tokens exist only in the database and are kept. Run it whole:
an asset without its meta row renders no image. Last, with users on the
eloquent repository, it imports `users/` (`statamic:eloquent:import-users`)
while the users table is empty. A table that holds an account, or a site
with `cms_sign_in` on (Core makes each account at its first sign-in and
will not adopt one it did not make), keeps `users/` out, and `--fresh`
never empties the table. A users import that fails only warns: the
content is in, so run `statamic:eloquent:import-users` by hand.

The order is the point. The driver's own `import-collections` saves the
trees before the entries exist, and saving a tree drops every id the
entries table doesn't hold yet, so the trees come out empty. So entries go
first, then trees, then the rest, with URIs and order recomputed last. Each
import also runs in its own process: in one process the entry import leaves
the still-empty database trees cached, and the tree import would save those
instead of the files.

Entry URIs and order are recomputed after the rows, one collection per
process (up to an hour each), so a large site's set neither runs out of
memory nor stops without a word. If one fails or runs out of time, the
rows are in: the command names the
`php artisan core-web:entry-order <collection>` runs that finish it.

Asset meta is imported the same way, one container per process (up to an
hour each, since each asset is a read of its `.meta` from the bucket), by
`php artisan core-web:asset-meta <container>`. It skips assets already in
the database, so after a timeout the same command picks up where it
stopped; the import names the containers left. `--skip-asset-meta` leaves
asset meta out of `content:import` entirely, for a library imported by
hand.

Nothing is left on the queue. Each step runs through the hidden
`core-web:import-step`, with the `sync` queue (Cloud caches config at build,
so an environment variable handed to the step would not reach it) and
with search indexing off (the indexes, Statamic's `cp` one included, are
swapped for a null index), which would otherwise update them once per saved
entry. When an index's driver keeps it off the container (any but `local`
and `null`), every index is rebuilt once at the end with
`statamic:search:update --all`; a site with only `local` indexes skips it,
since such an index is a file on one container's disk and builds itself on
its first search.

With `cloud` on, `media:sync`, `media:check` and `content:check` exist on
every site: while their feature (`media`, `checks`) is off they say so and
succeed, so one first-deploy list fits every site.

## Deploy commands

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
