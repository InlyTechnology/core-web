# Pulling production data

A site's content references Core's records, and on a developer's machine the
local Core (if there is one) holds other records, or none. `core:pull` fills
the local snapshots from the live site, so the site renders what the live
site renders without a local Core:

```bash
php artisan core:pull            # every registered snapshot
php artisan core:pull staff      # one
php artisan core:pull --release  # unpin and forget: the site reads CORE_URL again
```

It needs no secret: on a site with [Content pull](content-pull.md) switched
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
