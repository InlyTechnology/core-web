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

It exits non-zero on a failure, so a deploy or a launch check can run it.
What it cannot see (a worker or the scheduler actually running, the deploy
script) stays a launch check. `media:check` covers the media disks.
