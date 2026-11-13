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

`SiteCache::flush()` forgets the pages cached for one site: on its host, and on
a shared host the pages under its path (a page is the site's that Statamic
renders it as: a site whose URL names its host before one at a relative URL,
then the longest site path the page lies under). `SiteCache::flushPaths()`
forgets some of them, by path on the site, and `SiteCache::forget()` by the
absolute URLs an invalidator reports (a trailing `*` takes every page under
one), keeping only those the site owns. `SiteCache::refresh()` renders the
site's cached pages again in the background instead (one queued request per
page), and visitors keep the cached copy until it is replaced. On a shared host
with no site at its root, a page cached outside every site's path (a 404 at
`/foo` beside `/en` and `/sv`) is no site's, so no site's flush or refresh
reaches it.

## A save on one site of a multisite install

Statamic's `all` invalidation rule clears every site's pages on any save, and
with `background_recache` on it renders again only the pages cached on the
static cache's base URL (its `base_url`, or the current site's URL). On an
install whose sites share no pages, `SiteScopedInvalidator` keeps the rule to
the site of what was saved:

```php
// config/statamic/static_caching.php
'invalidation' => [
    'class' => \Inly\CoreWeb\StaticCaching\SiteScopedInvalidator::class,
    'rules' => 'all',
],
```

- An entry, a global, a collection tree or a deleted navigation tree on one
  site forgets that site's pages, on its host under http and https. The other
  sites' pages stay. A global also forgets the pages of every site whose
  copy of the set inherits its values (`origin`), directly or through
  another copy. Without `background_recache`, a save that reaches every site
  clears the whole cache at once, as Statamic's rule does.
- With `background_recache` on, a saved entry's own page on its site, and a
  moved page's old address with the pages under it, are forgotten at once
  (under http and https), so the next visitor sees the change, and the
  site's other pages are rendered again in the background
  by a queued job each; a saved global renders all of the site's pages
  again that way. Turn it on only with a queue worker: on the `sync` queue
  every page renders inside the save. A localized entry's copies on other
  sites are refreshed when each copy is saved, which Statamic does with its
  origin.
- A deleted entry, global, collection tree or navigation tree, a collection
  tree save and a scheduled entry going live flush the site's pages at once,
  with or without `background_recache`: Statamic invalidates for those
  instead of refreshing.
- A navigation save (Statamic reports it without its site), an asset save or
  a form save is left to Statamic's rule, which clears every page, or with
  `background_recache` renders again those on the base URL. Deleting a
  navigation, an asset or a form, and saving or deleting a taxonomy term,
  always clears every page.
- Other rules than `all` are Statamic's own.

A moved page's old address comes from the entry's original values. It is
missed for a localized copy whose slug comes from its origin, and on a Stache
whose cache store is `array`, which resets those values before `EntrySaved`
is reported. With `background_recache` on, the old address is then rendered
again with the rest of its site: with the `half` strategy its cached copy
becomes the 404 it now answers, and its queued render ends as a failed job.

## Pages cached while a clear runs

Statamic's `half` cacher stores each cached page under a key of its own and
keeps a list of the cached URLs for each domain. A clear (a save's flush, an
invalidated URL, the CP's Invalidate) deletes only the pages it finds on that
list. A page a visitor caches while a clear runs can be stored after the
clear took its URL off the list. Every later visit is then served that copy,
and no save or Invalidate reaches it until it expires or the store holding
it is emptied: `statamic:static:clear` with a dedicated `static_cache`
store, `cache:clear` without one. The same address with a trailing slash
or another query string is cached apart, so it can show the current page.

core-web runs `GuardedApplicationCacher` in its place, which serves a cached
page only when the request that rendered it started after the page was last
cleared:

- Each clear records when it ended, whether its pages were on the list or
  not: a flush of the whole cache, a path with every query string on it,
  and a wildcard (`/blog/*`, or `/search?q=*`) for the pages under it.
  `SiteCache` records the paths and wildcards it flushes, leaving out those
  another site owns, and a flush of a site records a clear of all the
  site's pages. A wildcard with another site under it (`/*` on a site at
  `/` beside `/en`) records a clear of all its own site's pages instead.
- A cached page is stored with the time its request started
  (`REQUEST_TIME_FLOAT`). A page whose request started before a clear that
  covers it ended, or less than a second after (servers' clocks differ
  slightly), is a miss: it is rendered again and listed. A page cached
  before core-web ran this cacher has no start, so it is rendered again
  once.
- A page whose URL cannot be written onto the list within 10 seconds, the
  wait for the list's lock, is not kept. Statamic writes the list without
  its lock then, and two such writes can lose a URL.
- A refresh with `background_recache` records no clear: visitors keep the
  cached copy until the queued render replaces it.

A hit reads the page and these records in one call to the cache store. A
path's record lasts an hour longer than the static cache's `expiry`, and
without one until its store is emptied. A wildcard's record goes with a
later flush of the whole cache or a later, wider wildcard. A site's record,
one per site, stays until its store is emptied.

`static_cache.guard: false` keeps Statamic's own cacher, which serves any
copy the guard was keeping out again: empty the store holding them after
turning it off, as above. The `full` strategy has a cacher of its own and
is unchanged.

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
