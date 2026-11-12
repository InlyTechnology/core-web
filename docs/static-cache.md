# Static cache

## The strategy

core-web turns Statamic's static cache on for every site it runs on a
server, on Laravel Cloud or not: `half`, unless the app's environment is
`local` or `testing`, where a cached page would hide the change being made.
A server needs no `STATAMIC_STATIC_CACHING_STRATEGY` line for it.

The site's own choice stands: a strategy its
`config/statamic/static_caching.php` sets, or the env var (`full`, or `null`
to turn caching off). Statamic's skeleton `.env.example` carries
`STATAMIC_STATIC_CACHING_STRATEGY=null`, so delete that line: a server whose
`.env` was copied from it would stay uncached.

`half` still runs PHP on each request, and serves the stored page instead of
rendering it. Only `full` is served by the web server without PHP, and Cloud
has no web server rewrites for it. `php artisan config:show
statamic.static_caching.strategy` prints what a server resolved.

## Rendering pages again after a save

A save forgets the pages it touches, and each one's next visitor renders it.
With Statamic's `background_recache` on, an entry, global, navigation, asset
or form save renders them again in the background instead, by a queued job
each, and visitors keep the cached copy until it is replaced. A deletion, a
term, a collection tree and a scheduled entry going live still forget them;
under the `all` rule, [A save under the `all` rule](#a-save-under-the-all-rule)
has what goes at once.

core-web turns `background_recache` on where the app's environment is
`production` and the connection Statamic queues the renders on
(`warm_queue_connection`, or `queue.default`) holds its jobs for a worker. It
stays off on a `sync`, `null`, `deferred` or `background` connection, which
runs the job inside the save, after it, or never, and in every other
environment: each render is a request from the worker to the page's own
address that sends no credentials, so a staging behind basic auth would keep
its old copies. A production site its worker cannot reach that way (behind
basic auth, an IP allow-list, or DNS that resolves the site's address
elsewhere from the worker) keeps them too, and turns it off below.

The site's own choice stands, the first of these it names:

- `STATAMIC_BACKGROUND_RECACHE` in the env, `false` included;
- `static_cache.background_recache` in `config/core-web.php`, `true` or
  `false`, in every environment;
- `true` in its `config/statamic/static_caching.php` (`false` is Statamic's
  default, so it chooses nothing).

A cached config keeps what was resolved when it was cached. The worker must
take the queue the renders go on, `warm_queue` (`low` on Laravel Cloud, see
[Laravel Cloud](laravel-cloud.md)) or the connection's default queue (a
failover connection's first connection), which `php artisan core-web:doctor`
names, and the default connection's default queue too:
Statamic queues the further pages of a paginated page there. `php artisan
config:show statamic.static_caching.background_recache` prints what a server
resolved.

## Pages a snapshot change touches

A snapshot moving saves no entry, so Statamic's invalidation never hears of
it. `SiteCache::flushOnChange()` is the change listener that forgets the
pages showing it; `SiteCache::flushEverySiteOnChange()` forgets every site's
pages, for data every page shows.

By default a change flushes every page of the partition's site (the default
site for an unpartitioned snapshot). A snapshot that names the pages its
documents appear on flushes only those:

```php
Snapshot::make('campaigns')
    ->dependents(fn (array $doc, string $id, ?string $partition) => [
        $doc['url_path'],             // its own page
        '/campaigns',                 // the listing
        '/brands/'.$doc['brand'],     // its brand's page
        ...$pickedBy($id),            // pages whose content picks it
    ])
    ->onChange(SiteCache::flushOnChange());
```

`dependents()` returns paths on the partition's site (`/`, `/campaigns`; a
trailing `*` takes every page under a prefix, `/brands/*`), and is called for
each changed document as it was and as it is, so a moved or removed
document's old page goes too. The snapshot's own sitemap
(`/sitemap-core-{type}.xml`) is added when it has one. A cached page goes with
every query string it was cached under.

The whole site is flushed instead whenever the pages are not known for sure:

- the listener has no `SnapshotChange` (the first pull, or a listener failed
  since it was last told);
- the snapshot declares no `dependents()`, or they return `null` for one of
  the documents;
- more than `targeted_flush.max_documents` documents changed at once;
- naming the pages throws (the exception is reported).

Correctness beats savings: return `null` from `dependents()` for a document
whose pages you cannot name.

`SiteCache::flush()` forgets the pages cached for one site: on its host (the
host its URL names, or `APP_URL`'s for a site with a relative URL, never the
one a request names) under http and https, and on a shared host the pages under
its path (the longest site path a page lies under decides whose it is).
`SiteCache::flushPaths()` forgets some of them, by path on the site, and
`SiteCache::forget()` by the absolute URLs an invalidator reports (a trailing
`*` takes every page under one), keeping only those the site owns (for a site
with a relative URL, on its host or the request's, where Statamic makes its
URLs) and forgetting them on its host; `SiteCache::forgetMany()` does that for
several sites at once, keyed by site handle, reading each host's cached pages
once for all of them. `SiteCache::refresh()` renders the site's cached pages
again in the background instead (one queued request per page), and visitors
keep the cached copy until it is replaced; `SiteCache::refreshPaths()` does
that for the paths it is given, named as `flushPaths()` names them, and leaves
a path nothing is cached at alone. Each path is requested once with every query
string it was cached under, and a page cached as an error is forgotten instead,
as Statamic does. With `background_recache` off, both forget the pages instead:
Statamic would keep each new render under its recache token, beside the old
copy, which visitors would go on getting. On a host with no site at its root, a
page cached outside every site's path (a 404 at `/foo` beside `/en` and `/sv`)
is no site's, so no site's flush or refresh reaches it.
`SiteCache::refreshAll()` renders again every page cached on a site's host,
under http and https, that page included. It forgets the pages cached on any
other host (a `www` alias, an address no site names) instead, since a request
names its own host and a render would be sent there.

## A save under the `all` rule

Statamic's `all` invalidation rule clears every site's pages on any save, and
with `background_recache` on it renders again only the pages cached on the
static cache's base URL: its `base_url`, or else the current site's URL, on
`APP_URL` when that URL is relative. The current site is the one whose URL the
address handling the save lies under (`APP_URL` on a queue worker or in a
console command), or else the default site. Those pages are none when that URL
has a path (`https://example.com/sv/`), and leave out every site on another
host. Under the `all` rule core-web uses `SiteScopedInvalidator` instead, on
one site or several, which keeps the rule to the site of what was saved. A
class a site names as its `invalidation.class` in
`config/statamic/static_caching.php` stands. `php artisan config:show
statamic.static_caching.invalidation` prints what a server resolved.

`SiteScopedInvalidator` takes the sites to share no pages. A site whose pages
show another site's content names
`\Statamic\StaticCaching\DefaultInvalidator::class` there, and turns
`background_recache` off too unless the static cache's base URL is the scheme
and host every site's visitors use, with no path.

- An entry, a global, a collection tree or a deleted navigation tree on one
  site forgets that site's pages, on its host under http and https. The other
  sites' pages stay, except a saved or deleted entry's copies on them (its
  origin and its other localizations, along the whole chain of origins): each
  links to it from a language switcher or `hreflang`, so it is forgotten on
  its own site, and its next visitor renders it with the new address. A
  global also forgets the pages of every site whose copy of the set inherits
  its values (`origin`), directly or through another copy. Where it would
  forget them all at once (without `background_recache`, and for the cases
  below that flush), a change that reaches every site, as any does on one
  site, clears the whole cache instead, as Statamic's rule does.
- With `background_recache` on, a saved entry's own page on its site, and a
  moved page's old address with the pages under it, are forgotten at once
  (under http and https), so the next visitor sees the change, and the
  site's other pages are rendered again in the background
  by a queued job each; a saved global renders all of the site's pages
  again that way. Statamic saves an entry's localizations with it, so saving
  an origin renders each copy's site again as well.
- A deleted entry, global, collection tree or navigation tree, a collection
  tree save and a scheduled entry going live flush the site's pages at once,
  with or without `background_recache`: Statamic invalidates for those
  instead of refreshing.
- A navigation save (Statamic reports it without its site), an asset save or
  a form save clears every page, or with `background_recache` hands them to
  `SiteCache::refreshAll()`. Deleting a navigation, an asset or a form, and
  saving or deleting a taxonomy term, always clears every page.
- Other rules than `all` are Statamic's own.

A moved page's old address comes from the entry's original values. It is
missed for a localized copy whose slug comes from its origin, and on a Stache
whose cache store is `array`, which resets those values before `EntrySaved`
is reported. With `background_recache` on, the old address is then rendered
again with the rest of its site: with the `half` strategy its cached copy
becomes the 404 it now answers, and its queued render ends as a failed job.
The pages under a moved page move with it, but their copies on other sites
are not forgotten: they link to the old addresses until their own site is
next cleared.

## Ad and analytics parameters

Statamic caches a page once per query string, so every click from a campaign
(`?utm_source=…&gclid=…`) cached a whole page of its own that no one read
again. `static_cache.tracking_query_strings` lists the parameters ad and
analytics platforms add (Google, Meta, Microsoft, TikTok, LinkedIn,
Mailchimp, HubSpot), and core-web adds them to Statamic's
`disallowed_query_strings`, beside the site's own. Statamic matches names
exactly, so a new platform parameter goes in the list here, once for every
site. `[]` turns it off.

A page is then cached under its address without them, as rendered for the
visitor who brought them. So whatever reads them on the server renders
inside `@nocache`: on the `half` strategy Statamic renders a nocache region
again in each visitor's own request, with their own query, and the cached
page keeps only its placeholder. A Core form's `query` page source is the
usual case. Reading them in the browser (analytics and ad scripts do) needs
nothing. Pagination links leave them out on their own while pages are
cached: core-web gives Laravel's paginator a query without them, so `withQueryString()` (which
Statamic's paginated tags call) keeps a listing's other parameters but never
hands the first visitor's campaign to everyone who pages on.

Not applied on the `full` strategy: it fills nocache regions from the
browser, which sends the address with the parameters, and Statamic looks the
regions up under that address while they were stored under the one without
them.

A site that knows its own parameters can go further with Statamic's
`allowed_query_strings`, which keeps only the listed ones (pagination, a
listing's filters) and ignores anything an ad platform adds next.
