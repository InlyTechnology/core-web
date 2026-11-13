# Checking a site's setup

`php artisan core-web:doctor` (registered once any feature is on) checks
what the switched-on features need from the environment it runs in, offline:

| Check | Fails when |
|---|---|
| Core client | The Core URL, client id (`core.*` or `CORE_URL` / `CORE_CLIENT_ID`) or `CORE_CLIENT_SECRET` is blank while a feature that calls Core is on; or a local checkout's `CORE_URL` names a remote Core (anything but localhost, a private address, `host.docker.internal`, `*.test`, `*.localhost`, `*.local`, `*.ddev.site`, `*.lndo.site`). On a local machine without `CORE_URL`, a warning: snapshots and content come from `core:pull` / `content:pull` |
| Queue | `QUEUE_CONNECTION` is `sync` in production with `snapshots` or `cms_sign_in` on; a warning on other servers, skipped locally |
| Schedule | Never: it lists the commands the features schedule and warns when one's schedule was set to `null` |
| Pings | Never: it warns while `WEBSITE_SYNC_SECRET` is still set |
| CMS accounts | Statamic's git integration commits the users folder while `cms_sign_in` is on |
| Static cache | Never. It warns while a server's cache is off, or while `background_recache` is on but the connection it queues the renders on runs jobs without a worker, is not defined, is a failover connection naming none, or is not named at all; with a worker taking the renders, it names the connection and queue they go on. With the `all` rule, it warns while `invalidation.class` names no class or a class that is no Statamic invalidator, and names the class a site chose. See [Rendering pages again after a save](static-cache.md#rendering-pages-again-after-a-save) and [A save under the `all` rule](static-cache.md#a-save-under-the-all-rule) |
| Proposals | With `drafts` on, `drafts.proposals.disk` names a disk that is not defined, or is scoped to one that is not. On Laravel Cloud (with `cloud`), proposals would sit on the default disk, a local disk or a disk on the public bucket (a scoped disk is judged by the disk it is scoped to); a warning when the disk is another bucket than the private one Cloud attaches |

It exits non-zero on a failure, so a deploy or a launch check can run it.
What it cannot see (a worker or the scheduler actually running, the deploy
script) stays a launch check. `media:check` covers the media disks.
