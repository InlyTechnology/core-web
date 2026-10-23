# Content pull

`features.content_sync` lets a developer's checkout pull the live site's
content into its own `content/` directory, signed in as their own CMS user:
no shared secret, no env var, and no Core needed. The same login lets
`core:pull` read the live site's Core snapshots (see
[Pulling production data](pulling-production-data.md)), so one sign-in
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
[Media → The disks](media.md#the-disks)), so the CP shows and renders them and
`<x-core-img>` points at their public URLs, while uploads stay on the
laptop until `content:push` carries them (see [Content push](content-push.md)). A container the site does not have, or the developer may not view,
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

## Building on the login

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
