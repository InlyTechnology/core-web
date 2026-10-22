# Static cache

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
path on the site.
