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
a shared host the pages under its path (the longest site path a page lies
under decides whose it is). `SiteCache::flushPaths()` forgets some of them, by
path on the site, and `SiteCache::forget()` by the absolute URLs an
invalidator reports (a trailing `*` takes every page under one), keeping only
those the site owns; `SiteCache::forgetMany()` does that for several sites at
once, keyed by site handle, reading each host's cached pages once for all of
them. `SiteCache::refresh()` renders the site's cached pages again in the
background instead (one queued request per page), and visitors keep the
cached copy until it is replaced; `SiteCache::refreshPaths()` does that for
the paths it is given, named as `flushPaths()` names them, and leaves a path
nothing is cached at alone. With `background_recache` off, both forget the pages
instead: Statamic would keep each new render under its recache token, beside
the old copy, which visitors would go on getting. On a shared host with no site at its root,
a page cached outside every site's path (a 404 at `/foo` beside `/en` and
`/sv`) is no site's, so no site's flush or refresh reaches it.

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
  sites' pages stay, except a saved or deleted entry's copies on them (its
  origin and its other localizations, along the whole chain of origins): each
  links to it from a language switcher or `hreflang`, so it is forgotten on
  its own site, and its next visitor renders it with the new address. A
  global also forgets the pages of every site whose copy of the set inherits
  its values (`origin`), directly or through another copy. Without
  `background_recache`, a save that reaches every site clears the whole
  cache at once, as Statamic's rule does.
- With `background_recache` on, a saved entry's own page on its site, and a
  moved page's old address with the pages under it, are forgotten at once
  (under http and https), so the next visitor sees the change, and the
  site's other pages are rendered again in the background
  by a queued job each; a saved global renders all of the site's pages
  again that way. Turn it on only with a queue worker: on the `sync` queue
  every page renders inside the save. Statamic saves an entry's
  localizations with it, so saving an origin renders each copy's site again
  as well.
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
