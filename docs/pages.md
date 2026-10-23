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
  partition and returns a document that has a page there (a closure only sees
  documents with one). None is a **503** with `Retry-After`
  while nothing at all can be read (a cold cache and Core unreachable), so an
  outage cannot deindex the pages, and Statamic's `NotFoundHttpException`
  otherwise: `abort(404)` renders Laravel's error view, whose layout lacks the
  cascade and fails.
- `canonical($request, $doc)` answers a request that reached the document at
  another path (an old slug, a renamed brand) with a 301 to its page on the
  site, under the site's own path on a shared host, percent-encoding a
  non-ASCII path. The query string goes
  along (campaign tags, filters); `keepQuery: false` drops it.
- `render($template, $data, $cascade)` is Statamic's View, with `$cascade`
  (an entry, say the listing's) as the cascade's content when the layout
  should read its title or SEO fields.
- `path($doc)` is the document's page path on the site, for links.

`SnapshotPage::for()` on a snapshot without `->pages()` throws a `LogicException`.
