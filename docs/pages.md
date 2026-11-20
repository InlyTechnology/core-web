# Pages

Not every snapshot is a page, so a snapshot opts in with `->pages()`: given a
document and a site handle, the path of its page on that site, or null for a
document without one there. Nothing is routed by the package; the site keeps
its routes and URL shapes, and `SnapshotPage` does the part every such
controller repeats:

```php
Snapshot::make('locations')
    // ...
    ->pages(fn (array $doc, string $site) => '/locations/'.$doc['slug']);

Route::get('locations/{slug}', function (Request $request, string $slug) {
    $pages = SnapshotPage::for('locations');                 // the current site; for('locations', 'second') for another
    $location = $pages->find(fn (array $doc) => $doc['slug'] === $slug || in_array($slug, $doc['old_slugs'], true));

    return $pages->canonical($request, $location)              // 301 to its own path, or null
        ?? $pages->render('locations.show', ['location' => $location, 'title' => $location['name']], Entry::findByUri('/locations'));
});
```

- `find($id)` or `find(fn (array $doc, string $id) => bool)` reads the site's
  partition and returns a document that has a page there, with its
  [details](registering-snapshots.md#details) (a closure only sees documents
  with one, without their details). None is a **503** with `Retry-After`
  while nothing at all can be read (a cold cache and Core unreachable), so an
  outage cannot deindex the pages, and Statamic's `NotFoundHttpException`
  otherwise: `abort(404)` renders Laravel's error view, whose layout lacks the
  cascade and fails.
- `canonical($request, $doc)` answers a request that reached the document at
  another path (an old slug, a renamed brand, the same path with a trailing
  slash) with a 301 to its page on the site, under the site's own path on a shared host, percent-encoding a
  non-ASCII path. The query string goes
  along (campaign tags, filters); `keepQuery: false` drops it.
- `render($template, $data, $cascade)` is Statamic's View, with `$cascade`
  (an entry, say the listing's) as the cascade's content when the layout
  should read its title or SEO fields.
- `gone($template, $data, $cascade)` renders like `render()` and answers
  **410**, with the cascade's `response_code` at 410 for that render, so the
  [SEO head](seo.md) noindexes the page and leaves out its canonical. See
  [Records that leave the catalogue](#records-that-leave-the-catalogue).
- `path($doc)` is the document's page path on the site, for links.

## Records that leave the catalogue

A record that is gone (a sold car, an ended campaign) answers either:

- **404**, by leaving it out of the snapshot: `find()` throws Statamic's
  not-found exception.
- **410 with its page kept**, when visitors still arrive from old links and
  the page has something to offer them ("this car is sold" and similar
  ones): the snapshot keeps the document, the page renders through
  `gone()`, and the snapshot's [`->sitemap()`](sitemaps.md) closure gives no
  URL for it.

```php
return $pages->canonical($request, $car)
    ?? ($car['sold_at'] ? $pages->gone('cars.sold', ['car' => $car]) : $pages->render('cars.show', ['car' => $car]));
```

Never a 200: a sold record that answers 200 stays indexed, and its old
addresses turn up in Search Console as duplicates ("Alternate page with
proper canonical tag"). Never a redirect of every gone record to one page or
to a filtered listing either: Google reads a redirect to an unrelated page as
a soft 404, so it drops the old address no faster, and the visitor is never
told that the record they followed is gone.

The [static cache](static-cache.md) never stores a 410 (Statamic caches a
200, and under `half` a 404 too), so each visit renders the page.

`SnapshotPage::for()` on a snapshot without `->pages()` throws a `LogicException`.

A snapshot whose pages show Core records names its Core object with
`->coreObject()`, so Core links each record to its page and previews it there
(see [Object preview](object-preview.md)).
