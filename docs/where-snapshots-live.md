# Where snapshots live

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
