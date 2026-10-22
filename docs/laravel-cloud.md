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
| Stache watcher, Statamic's git integration | off | `STATAMIC_STACHE_WATCHER`, `STATAMIC_GIT_ENABLED` |
| Revisions, Statamic Pro | on (each collection still sets `revisions: true`) | `STATAMIC_REVISIONS_ENABLED`, `STATAMIC_PRO_ENABLED` |

An env var the environment sets keeps its own value, including one Cloud
itself injects when a resource is attached (a cache cluster sets
`CACHE_STORE`, for instance). Off Cloud nothing changes, so local machines, CI and Forge keep `content/` as the source.
Blueprints, collections, navigations, taxonomies, global sets, forms and
sites stay files from git on Cloud too.

The site does its half once:

- `composer require statamic/eloquent-driver`, publish its config
  (`--tag=statamic-eloquent-config`) with every `driver` left on `file`
  (the feature switches the content parts) and `UuidEntryModel` for
  entries, and publish its migrations.
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
  it from `LARAVEL_CLOUD_DISK_CONFIG` (see [On Laravel Cloud](media.md#on-laravel-cloud)).
- With `features.cms_sign_in`: run `php please auth:migration` (the
  `role_user` and `group_user` tables eloquent users write roles and groups
  to), keep the CP provider on the `statamic` driver with its `model` set,
  and turn on Cloud's scheduler for `core:reconcile-cms-users`.
- Search: a `local` index lives on one container's disk, so on Cloud it
  needs a remote search driver; `core-web:deploy` warns about any local
  index.

With the maintenance flag on the database store, every request reads one
cache row to know whether the site is down.

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
